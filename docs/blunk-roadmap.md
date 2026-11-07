# Blunk Roadmap 1.0

Acceso exclusivo Super Admin: `/super-admin/development/tasks`, menú Desarrollo.

## Instalación controlada

No se han ejecutado migraciones, importaciones, tests, builds ni despliegues durante la implementación.
Después de revisar y respaldar la base de datos del entorno elegido, ejecutar únicamente la migración de este módulo:

```sh
php artisan migrate --path=database/migrations/2026_11_07_000001_create_development_tasks_tables.php
php artisan blunk:roadmap-import --dry-run
php artisan blunk:roadmap-import --apply
```

El comando sin opciones también simula. `--apply` crea solo orígenes ausentes; nunca actualiza tareas importadas, aunque estén archivadas. La restricción única protege frente a importaciones concurrentes. No está conectado a seeders ni a despliegues. Los códigos BLK derivan del ID y permanecen estables.

## Uso y límites

- Dashboard, listado y Kanban comparten filtros. Cada columna Kanban pagina por separado; cambiar estado se hace en el detalle, sin arrastrar tarjetas.
- Tres estados independientes. El cierre, despliegue y validación registran fechas al seleccionar sus respectivos estados; salir de esos estados limpia la fecha correspondiente. Los cambios quedan en el historial.
- Archivado reversible, checklist, comentarios y protección contra sobrescritura de otra sesión. Responsables: usuarios Super Admin.
- El orden numérico manual puede usarse dentro de cada prioridad o globalmente mediante el filtro Orden.
- Actividades con campos permitidos; las descripciones y notas técnicas no se duplican en el historial. Se registran sus modificaciones y los IDs, títulos, estados y orden de subtareas antes/después. No introducir secretos en campos ni comentarios.
- La carga inicial contiene 66 entradas. Las prioridades P2 son provisionales. Las tareas sin evidencia suficiente incluyen una nota de verificación; no se ha auditado todo el ERP. Ninguna se importa como desplegada, validada o cerrada.
- La entrada de recibos MYJ corresponde a aceptación del cliente; su nota referencia el desarrollo general para evitar duplicar implementación.
- Sin sincronización GitHub, automatizaciones, sprints ni nuevas dependencias. Pendiente validar comportamiento en ejecución y diseño en navegador antes de publicar.

## Verificaciones recomendadas (no ejecutadas)

1. Migración en PostgreSQL de desarrollo; importación simulada sin escrituras y dos aplicaciones consecutivas sin duplicados. Editar/archivar una importada y repetir: conservar sus datos.
2. Denegación de todas las rutas GET/POST/PUT/PATCH a invitados y usuarios tenant, incluidos IDs conocidos.
3. Crear/editar, cerrar/reabrir, archivar/restaurar, asignar cliente sin tenant, checklist y comentarios. Rechazar IDs de subtareas ajenas y enlaces externos no GitHub HTTPS.
4. Guardar desde dos sesiones: rechazar la segunda edición desactualizada. Verificar rollback de tarea e historial ante validación fallida.
5. Contrastar conteos y paginación bajo todos los filtros, incluyendo columnas Kanban con más de 12 tareas, cerradas y archivadas.
6. Verificar independencia de estados y commits; comentarios/descripciones con HTML deben mostrarse como texto. Revisar móvil y teclado.

Comprobaciones realizadas: sintaxis PHP, diagnósticos TypeScript limitados a las pantallas nuevas (sin emisión) y `git diff --check`.
