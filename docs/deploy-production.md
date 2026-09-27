# Deploy de producción

## Estado del mecanismo interno

La pantalla **Super Admin > Sistema > Actualizaciones** ejecuta un único script
versionado mediante una cola exclusiva. El mecanismo interno sólo queda autorizado
después de instalar y verificar esta versión endurecida en producción.

El primer salto desde la versión que contiene el script anterior debe ser **manual y supervisado**.
No se debe intentar ese bootstrap desde el panel interno: el job que
comienza la actualización ejecuta el script que ya está instalado en producción.

El workflow existente de GitHub no se amplía ni se considera endurecido en esta
tarea. No debe utilizarse como sustituto automático de ese primer procedimiento
supervisado.

## Contrato del deploy interno

Al solicitar una actualización, Laravel obtiene `origin/main` y persiste su SHA
completo en `DeployRun.remote_commit_target`. Job, servicio y script conservan ese
valor exacto; un movimiento posterior de `origin/main` no modifica el despliegue ya
aprobado.

El script sólo acepta un SHA hexadecimal completo. Después de `git fetch` comprueba
que el commit existe, pertenece a la historia de `origin/main` y es un fast-forward
desde el checkout actual. `main` se avanza con `git merge --ff-only TARGET_SHA`; no se
crean merge commits ni se despliega un ref introducido desde HTTP.

Si `HEAD` ya coincide con el target, el run termina correctamente como no-op antes de
entrar en mantenimiento o ejecutar Composer, npm, migraciones, seeders o cachés.

Para una actualización real, el orden es:

1. preflight de repositorio, SHA, runtimes, Composer y hooks;
2. `php8.5 artisan down`;
3. pausa de workers de negocio mediante el hook configurado;
4. fast-forward al SHA aprobado;
5. Composer con PHP 8.5;
6. `npm ci` y `npm run build`;
7. migraciones y seeders autorizados;
8. reconstrucción de cachés y smoke checks;
9. señal `php8.5 artisan queue:restart`;
10. reanudación de workers de negocio;
11. `php8.5 artisan up`.

Después de entrar en mantenimiento, cualquier error deja la aplicación en
mantenimiento e intenta confirmar nuevamente la pausa de workers. No existe rollback
automático, no se ejecuta `migrate:rollback` y no se hace `artisan up` desde el trap.
Se requiere una corrección hacia adelante o una restauración de base de datos evaluada
manualmente.

Los únicos seeders ejecutados en cada deploy son `PermissionSeeder` y
`GuatemalaLocationSeeder`; ambos están diseñados para sincronización idempotente. No
se ejecuta el seeder general ni datos demo.

## PHP, HOME y Composer

Todos los comandos Artisan usan literalmente `php8.5`. El binario `php` o un alias de
shell no forma parte del contrato.

`DEPLOY_COMPOSER_PATH` debe ser una ruta absoluta a un script PHP o PHAR de Composer
legible y compatible con:

```bash
php8.5 "$DEPLOY_COMPOSER_PATH" --version --no-ansi
```

La salida debe identificar `Composer version`. Una ruta a un wrapper de shell o a un
binario no ejecutable por PHP 8.5 es inválida y detiene el deploy antes de
mantenimiento. El install usa:

```bash
php8.5 "$DEPLOY_COMPOSER_PATH" install \
  --no-dev --prefer-dist --no-interaction --optimize-autoloader
```

El script conserva `HOME` y `COMPOSER_HOME` cuando Supervisor los proporciona. Si
falta `HOME`, usa `DEPLOY_HOME` o intenta resolver el home real del usuario del
proceso. Si falta `COMPOSER_HOME`, usa `DEPLOY_COMPOSER_HOME` o `$HOME/.composer`.
Ambas rutas deben existir o poder crearse y ser escribibles. Sólo se registran las
rutas, nunca `.env`, `auth.json`, tokens o credenciales.

## Configuración de producción

Valores requeridos, sin guardar secretos en el repositorio:

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1200
DEPLOY_QUEUE_WORKER_ENABLED=true
DEPLOY_HOME=/ruta/home/usuario-worker
DEPLOY_COMPOSER_HOME=/ruta/home/usuario-worker/.composer
DEPLOY_COMPOSER_PATH=/ruta/absoluta/composer.phar
DEPLOY_PAUSE_WORKERS_HOOK=/ruta/absoluta/blunk-pause-business-workers
DEPLOY_RESUME_WORKERS_HOOK=/ruta/absoluta/blunk-resume-business-workers
FEL_ROUTE_AUTOMATION_ENABLED=false
```

`DB_QUEUE_RETRY_AFTER` debe ser mayor que el timeout de 900 segundos del job. El
panel y el servicio fallan cerrado si la conexión de cola activa no satisface esa
regla.

Los dos hooks deben ser archivos absolutos, ejecutables y administrados fuera del
repositorio. El de pausa debe detener y verificar todos los workers que puedan escribir
datos de negocio, incluido el worker FEL si existe, pero **no** el worker exclusivo
`deploys`. El de reanudación debe iniciar exactamente esos mismos workers. Los nombres
de programas Supervisor son propios del servidor y no se fijan en Blunk.

Supervisor debe ejecutar exactamente una instancia dedicada que no consuma `default`:

```ini
[program:blunk-deploy-worker]
command=php8.5 /RUTA_APP/artisan queue:work database --queue=deploys --sleep=3 --tries=1 --timeout=900
directory=/RUTA_APP
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=920
user=USUARIO_WEB
environment=HOME="/RUTA_HOME",COMPOSER_HOME="/RUTA_HOME/.composer"
redirect_stderr=true
stdout_logfile=/RUTA_APP/storage/logs/deploy-worker.log
```

Sustituye los placeholders por valores reales y verifica permisos antes de activar
`DEPLOY_QUEUE_WORKER_ENABLED`. El usuario del worker necesita Git, npm, `php8.5`,
lectura del Composer configurado, escritura en `storage/logs/deploys` y permiso para
ejecutar ambos hooks. No se requieren comandos, ramas ni rutas suministrados por el
navegador.

## `queue:restart` y el propio worker de deploy

`php8.5 artisan queue:restart` sólo escribe una marca de reinicio en cache. El worker
Laravel consulta esa marca después de que `RunProductionDeployJob::handle()` retorna.
Por ello, el proceso shell termina primero, `DeployService` captura log/exit code, lee
el SHA final y persiste `succeeded` o `failed`; sólo entonces el worker dedicado sale y
Supervisor lo levanta nuevamente. No se debe reemplazar esta señal por una terminación
directa del proceso Supervisor desde los hooks.

## Bootstrap manual supervisado

Antes del primer uso del panel:

1. realiza una ventana de mantenimiento supervisada usando PHP `php8.5`;
2. detén y verifica los workers de negocio con los nombres reales del servidor;
3. instala el commit que contiene este mecanismo mediante fast-forward controlado;
4. instala dependencias, compila, migra y ejecuta únicamente los dos seeders indicados;
5. configura `.env`, hooks y el worker exclusivo;
6. limpia/reconstruye caches y confirma `FEL_ROUTE_AUTOMATION_ENABLED=false`;
7. verifica el SHA, la aplicación y los logs antes de salir de mantenimiento;
8. prueba primero un run no-op desde el panel.

Si cualquier migración falla, conserva mantenimiento y los workers detenidos. No
restaures servicio hasta decidir y ejecutar una corrección hacia adelante o una
restauración coherente de aplicación y base de datos.

## Diagnóstico

Cada run registra usuario, SHA anterior, target solicitado, SHA final, estado, código
de salida y log. Es seguro registrar etapa, usuario del proceso, versiones de runtimes
y rutas operativas. No copies en tickets el contenido de `.env`, Composer `auth.json`,
tokens, DSN ni credenciales Git.

Estados terminales:

- `succeeded`: el script terminó en cero y el SHA final coincide exactamente;
- `failed`: el proceso, configuración o verificación final falló;
- `cancelled`: el gate estaba deshabilitado o otro deploy poseía el lock exclusivo.

Un run `running` sólo se reclama con una actualización atómica de `pending` a
`running`, dentro de un lock global de ejecución. Un segundo delivery del mismo job no
vuelve a ejecutar el script.
