# Deploy manual a produccion

Este repositorio incluye el workflow manual **Deploy Production** en
`.github/workflows/deploy-production.yml`. No se ejecuta con `push`, `pull_request`
ni programacion: solo puede iniciarse desde la pestana **Actions** de GitHub.

El workflow se conecta por SSH al servidor de produccion, actualiza `main`, instala
dependencias, compila los assets y ejecuta las migraciones y seeders necesarios. No
modifica `.env`, no borra `storage` y no ejecuta comandos destructivos.

## Secrets requeridos

En GitHub, abre **Settings > Secrets and variables > Actions > New repository secret**
y crea estos secrets:

| Secret | Valor |
| --- | --- |
| `PROD_HOST` | Hostname o IP publica del servidor de produccion. |
| `PROD_USER` | Usuario SSH con acceso al directorio de la aplicacion. |
| `PROD_PORT` | Puerto SSH, normalmente `22`. |
| `PROD_SSH_KEY` | Clave privada SSH completa del usuario de deploy. |
| `PROD_PATH` | Ruta absoluta de la aplicacion, por ejemplo `/home/USUARIO/htdocs/v2.kodbli.app`. |

La clave publica correspondiente a `PROD_SSH_KEY` debe estar en
`~/.ssh/authorized_keys` del usuario indicado en `PROD_USER`. La clave privada nunca
debe agregarse al repositorio ni imprimirse en logs.

Antes del primer deploy, valida fuera de GitHub la huella SSH del servidor:

```bash
ssh-keyscan -p 22 -H TU_HOST
ssh-keygen -lf -
```

Comparala con la huella publicada por el proveedor del servidor. El runner es efimero,
por lo que el workflow obtiene la clave publica del host en cada ejecucion antes de
conectarse. Si la huella cambia inesperadamente, deten el deploy y valida el cambio.

## Ejecutar el deploy manual

1. Confirma que los cambios ya estan en `main` y que el commit fue revisado.
2. Abre **Actions** en GitHub y selecciona **Deploy Production**.
3. Pulsa **Run workflow**, deja seleccionada la rama `main`, marca
   **Confirm production deployment** y ejecuta el workflow.
4. Sigue el log. El workflow muestra la rama y el ultimo commit antes y despues de
   `git pull`.

El proceso remoto ejecuta, en este orden:

```bash
git status --short
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php8.5 -v
php8.5 -m | grep -i pgsql
php8.5 artisan down
php8.5 artisan migrate --force
php8.5 artisan db:seed --class=PermissionSeeder --force
php8.5 artisan db:seed --class=GuatemalaLocationSeeder --force
php8.5 artisan optimize:clear
php8.5 artisan config:cache
php8.5 artisan route:cache
php8.5 artisan queue:restart
php8.5 artisan up
```

Si una orden falla antes de `php8.5 artisan down`, el workflow termina sin entrar en
mantenimiento. Si falla despues de activar mantenimiento, un `trap` intenta siempre
ejecutar `php8.5 artisan up` antes de finalizar el proceso remoto.

## Que revisar si falla

- **Secrets faltantes:** el workflow falla antes de abrir SSH e indica cual falta.
- **SSH o host key:** valida `PROD_HOST`, `PROD_PORT`, el usuario, la clave publica en
  el servidor y la huella del host.
- **`git pull` falla:** entra por SSH y revisa el estado del checkout. El workflow no
  continua si no puede actualizar `main`.
- **Checkout remoto con cambios locales:** el workflow se detiene antes de `git pull`.
  Revisa esos cambios por SSH; no los descartes sin identificar su origen.
- **Composer, npm o build fallan:** corrige el commit o las dependencias y vuelve a
  ejecutar el workflow. No fuerces un deploy parcialmente construido.
- **Migracion o seeder falla:** revisa el log de Artisan y el estado de la base de
  datos. El workflow intentara sacar la aplicacion de mantenimiento.
- **La aplicacion queda en mantenimiento:** ejecuta `php8.5 artisan up` por SSH de forma
  inmediata y revisa el log de la ejecucion fallida.

## Deploy de emergencia por SSH

Solo cuando GitHub Actions no este disponible, entra al servidor con el usuario de
deploy y ejecuta el mismo flujo desde la ruta de produccion:

```bash
cd ~/htdocs/v2.kodbli.app
git status --short
git branch --show-current
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php8.5 -v
php8.5 -m | grep -i pgsql

maintenance_enabled=false
restore_application() {
  exit_code=$?
  if [ "$maintenance_enabled" = true ]; then
    php8.5 artisan up || true
  fi
  exit "$exit_code"
}
trap restore_application EXIT

php8.5 artisan down
maintenance_enabled=true
php8.5 artisan migrate --force
php8.5 artisan db:seed --class=PermissionSeeder --force
php8.5 artisan db:seed --class=GuatemalaLocationSeeder --force
php8.5 artisan optimize:clear
php8.5 artisan config:cache
php8.5 artisan route:cache
php8.5 artisan queue:restart
php8.5 artisan up
maintenance_enabled=false
```

No edites `.env` durante este procedimiento. Si cualquier paso posterior a `down`
falla, confirma que `php8.5 artisan up` haya sido ejecutado antes de continuar el
diagnostico.
