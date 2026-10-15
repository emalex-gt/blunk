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

La primera instalación de la versión que añade el respaldo pre-deploy también debe
ser manual y supervisada: el script que ya está instalado no puede ejecutar una
capacidad que aún no existe en ese checkout. Esa instalación debe aplicar la
migración de `deploy_runs`, configurar las rutas de PostgreSQL y verificar un
respaldo manual antes de habilitar el panel para los siguientes deploys.

La primera utilización productiva del mecanismo automático de respaldo ya fue
verificada correctamente. Esta documentación describe el procedimiento operativo
para los deploys posteriores; no convierte los deploys ordinarios en una acción
automática al hacer push a `main`.

## Política operativa de mantenimiento

### Deploys normales

Los deploys ordinarios no deben realizarse durante el horario normal de operación
de los clientes. El operador debe elegir manualmente una ventana con la menor
actividad agregada esperada entre los países y tenants afectados. Blunk todavía no
tiene suficientes datos de uso en producción para fijar una franja horaria global
permanente.

La elección futura de ventanas debe basarse en actividad real por tenant, país y
zona horaria. Hasta que exista esa información, el deploy es una decisión deliberada
del operador autorizado a través del mecanismo interno controlado.

### Mantenimiento planificado y comunicación

Para mantenimiento planificado, Operaciones debe comunicar:

- un aviso inicial entre 24 y 48 horas antes;
- un recordatorio poco antes de la ventana, con objetivo aproximado de 1 a 2 horas;
- fecha/hora, duración o ventana estimada, posible indisponibilidad temporal y una
  razón breve no sensible;
- correo a los contactos apropiados de cada tenant y un banner dentro de la
  aplicación.

La automatización de correo y banner todavía no existe. Por ahora esto es un flujo
manual obligatorio de Producto/Operaciones y la automatización queda registrada como
trabajo pendiente, no como funcionalidad disponible.

### Excepción de hotfix de emergencia

Un deploy durante horario operativo sólo se permite para un hotfix crítico
justificado: caída activa o degradación severa, riesgo de integridad/corrupción de
datos, incidente o exposición urgente de seguridad, bloqueo fiscal/FEL, o bloqueo de
ventas, POS u otra operación central cuyo riesgo sea mayor que esperar la ventana.

Las funcionalidades ordinarias y los cambios cosméticos no son hotfixes. En una
emergencia se puede omitir el aviso de 24–48 horas, pero se comunica tan pronto como
sea práctico y se emite aviso posterior si no fue posible hacerlo antes. Siempre que
sea técnicamente posible se usa el mismo pipeline seguro de deploy.

### Requisitos antes de un deploy normal

Antes de iniciar un deploy normal, el operador confirma:

- working tree local limpio y SHA exacto del target identificado;
- target publicado en `origin/main`;
- pruebas focales relevantes GREEN y, para releases transversales o riesgosos, suite
  PHPUnit local completa GREEN;
- build frontend GREEN cuando cambien frontend o assets;
- comprobaciones de diff limpias antes del commit cuando correspondan;
- ejecución exclusivamente mediante el mecanismo interno controlado;
- ausencia de PHPUnit en producción.

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

1. prechecks de repositorio, SHA, runtimes, Composer, hooks y configuración de backup;
2. `php8.5 artisan down`;
3. pausa y verificación de workers/escritores de negocio mediante el hook configurado;
4. respaldo PostgreSQL en formato custom mediante `pg_dump`;
5. validación del respaldo mediante `pg_restore --list`;
6. registro de los metadatos del respaldo validado;
7. limpieza de retención de respaldos propios del mecanismo;
8. fast-forward al SHA aprobado;
9. Composer con PHP 8.5;
10. migraciones y seeders autorizados;
11. `npm ci` y `npm run build` cuando forman parte del script;
12. reconstrucción de cachés/configuración/rutas y smoke checks;
13. señal `php8.5 artisan queue:restart`;
14. reanudación de workers de negocio;
15. `php8.5 artisan up`.

El respaldo se crea sólo después de mantenimiento y de confirmar la pausa de los
workers de negocio, pero antes de modificar el checkout, Composer o la base de
datos. Si `pg_dump`, la existencia/tamaño del archivo o `pg_restore --list` falla,
el deploy se aborta sin avanzar el SHA y la aplicación queda en mantenimiento con
los workers de negocio pausados. El registro de metadatos del respaldo validado también
es obligatorio. La limpieza de retención ocurre sólo después de ese registro y, si
falla, deja una advertencia pero no invalida un respaldo ya válido ni aborta el deploy.
No se ejecuta un restore automático.

Después de entrar en mantenimiento, cualquier error deja la aplicación en
mantenimiento e intenta confirmar nuevamente la pausa de workers. No existe rollback
automático, no se ejecuta `migrate:rollback` y no se hace `artisan up` desde el trap.
Se requiere una corrección hacia adelante o una restauración de base de datos evaluada
manualmente.

## Política de fallos

Si un paso falla después de entrar en mantenimiento, la aplicación permanece abajo y
los workers relevantes se mantienen o vuelven a pausarse. No se intenta un rollback
automático especulativo, ni una restauración automática. La investigación manual debe
decidir deliberadamente entre una corrección hacia adelante y una restauración
coherente de aplicación/base de datos; la integridad de datos tiene prioridad sobre
minimizar la indisponibilidad.

El procedimiento de restore/recovery es una operación separada y debe documentarse y
autorizarse explícitamente; esta política no inventa un procedimiento que el sistema
no implementa.

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
DEPLOY_BACKUP_DIRECTORY=/ruta/externa/resguardos/blunk-pre-deploy
DEPLOY_PG_DUMP_PATH=/usr/bin/pg_dump
DEPLOY_PG_RESTORE_PATH=/usr/bin/pg_restore
DEPLOY_BACKUP_RETENTION=30
DEPLOY_BACKUP_TIMEOUT=900
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

`DEPLOY_BACKUP_DIRECTORY` debe ser absoluta, escribible por el worker y estar fuera
del repositorio. El mecanismo sólo administra archivos con el patrón
`blunk-pre-deploy-<sha-corto>-<UTC>.dump`; conserva los 30 más recientes y nunca
elimina el respaldo del deploy en curso. `DEPLOY_PG_DUMP_PATH` y
`DEPLOY_PG_RESTORE_PATH` deben apuntar a ejecutables PostgreSQL compatibles con el
servidor. La conexión se lee de la configuración Laravel activa; la contraseña se
entrega únicamente al proceso hijo como `PGPASSWORD`, nunca se añade a argumentos,
logs o metadatos. El usuario del worker necesita permiso de conexión a PostgreSQL y
escritura sólo sobre ese directorio externo.

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
de salida y log. Cuando el respaldo es válido también registra ruta, tamaño y marcas
de creación/validación; la pantalla de detalle lo muestra. Es seguro registrar etapa,
usuario del proceso, versiones de runtimes y rutas operativas. No copies en tickets el
contenido de `.env`, Composer `auth.json`, tokens, DSN ni credenciales Git.

Estados terminales:

- `succeeded`: el script terminó en cero y el SHA final coincide exactamente;
- `failed`: el proceso, configuración o verificación final falló;
- `cancelled`: el gate estaba deshabilitado o otro deploy poseía el lock exclusivo.

Un run `running` sólo se reclama con una actualización atómica de `pending` a
`running`, dentro de un lock global de ejecución. Un segundo delivery del mismo job no
vuelve a ejecutar el script.

## Verificación posterior al deploy

Después de cada deploy, el operador verifica:

- estado `succeeded` del deploy;
- igualdad entre SHA target solicitado y SHA final checkout;
- aplicación disponible y workers de cola en estado `RUNNING`;
- existencia del respaldo automático pre-deploy, tamaño distinto de cero y marca de
  validación;
- metadatos de backup presentes en el historial del deploy;
- smoke check superado;
- ausencia de errores inesperados inmediatamente tras el release.

## Trabajo pendiente de producto y operaciones

Las siguientes capacidades no están implementadas todavía:

- modelo/estado de anuncios de mantenimiento;
- banner global in-app;
- notificaciones por correo y recordatorios;
- programación consciente de tenant, país y zona horaria;
- historial/auditoría adicional de mantenimiento si resulta útil;
- selección de ventanas a partir de analítica real de uso.
