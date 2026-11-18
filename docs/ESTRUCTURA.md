# Organización del código

## Flujo de una solicitud

`index.php` → `public/index.php` → JavaScript/CSS de `public/assets/`.

`api.php` → `public/api.php` → `app/init.php` → clases de `app/` → MySQL o archivos de `storage/`.

## Qué archivo editar

| Cambio | Archivo principal |
|---|---|
| Permisos, acceso y sesión | `app/Auth.php` |
| Datos del panel por rol | `app/Dashboard.php` |
| Asignación de revisores | `app/Assignments.php` |
| Proyectos y búsqueda de antecedentes | `app/Projects.php` |
| Carga y extracción de PDF/DOCX | `app/Documents.php` |
| Procesamiento de documentos y análisis | `app/Jobs.php` |
| Solicitudes a Gemini | `app/Gemini.php` |
| Generación de informes PDF | `app/Reports.php` |
| Rutas y respuestas API | `public/api.php` |
| Pantallas generales, navegación y formularios | `public/assets/app.js` |
| Paneles por rol y formulario de asignaciones | `public/assets/role-panels.js` |
| Estilos generales | `public/assets/app.css` |
| Estilos de los paneles por rol | `public/assets/role-panels.css` |
| Conexión local y clave Gemini | `config/local.php` |

## Base de datos

`database/thesisguard.sql` es el volcado completo para una base vacía. Incluye las 14 tablas, las asignaciones y los datos actuales de la instalación local; no se importa encima de una base existente.

Las actualizaciones incrementales se guardan en `database/migrations/`. La migración `002_project_reviewers.sql` recupera de forma idempotente la tabla `project_reviewers`, usada por permisos, paneles y asignaciones de revisores. Antes de aplicarla en una base publicada, comprueba el esquema remoto y crea/exporta un respaldo verificable.

Para actualizar este archivo después de modificar los datos locales, exporta estructura y datos desde phpMyAdmin y reemplaza database/thesisguard.sql con la descarga. Para trasladar documentos e informes utiliza la carpeta storage correspondiente a la misma exportación. La exportación unificada actual se verificó contra los archivos y la base local.

`scripts/restore.php` toma únicamente los CREATE TABLE de este archivo y carga los datos del respaldo que seleccionaste. No mezcla los datos del SQL con los del respaldo.

Los SQL históricos y exportaciones anteriores se conservan en `storage/archive/sql-historicos-*.zip`. El archivo `database/migrations/002_project_reviewers.sql` reproduce la migración 002 original desde el ZIP histórico para poder aplicarla de manera controlada.

## Configuración y datos

`config/example.php` contiene valores predeterminados; `config/local.php` los reemplaza. `config/infinityfree.example.php` es una plantilla para la copia de hosting.

Los originales en `storage/documents/` y los informes en `storage/reports/` están vinculados a la base por ruta y huella SHA-256. No deben renombrarse como parte de una limpieza.

## Paquetes e historial

- `storage/deployments/`: copias preparadas para subir, con su propio SQL, configuración y ZIP.
- `storage/backups/`: respaldos nuevos producidos por `scripts/backup.php`.
- `storage/archive/previous-packages/`: paquetes antiguos y copias de pruebas que antes estaban en `storage/backups/_obsoletos/`.
- `storage/archive/local-tools/`: capturas auxiliares, herramientas históricas y el lanzador de worker que apuntaba a un archivo inexistente.
- `storage/archive/legacy-tests/`: pruebas antiguas que requerían scripts retirados.

Los movimientos de esta reorganización están registrados en `storage/archive/organization-manifest.json`. No se eliminaron los archivos históricos. `storage/` permanece bloqueado para acceso web directo mediante `.htaccess`.

Las pestañas del editor abiertas sobre las ubicaciones anteriores pueden quedar obsoletas. Ciérralas y abre los archivos desde el árbol actual.
