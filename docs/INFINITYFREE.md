# Subir ThesisGuard a InfinityFree

El código principal está en `app/` y `public/` de la raíz del proyecto. Ejecuta los comandos de esta guía desde esa raíz. Una copia de despliegue no se actualiza automáticamente cuando editas el código.

**Sitio ya instalado:** no vuelvas a importar un SQL completo en la base existente. Para una actualización, revisa qué cambios de estructura faltan antes de aplicar archivos nuevos. Conserva la configuración remota y los documentos actuales.

## Reparar una instalación existente sin `project_reviewers`

La aplicación actual necesita `project_reviewers` para asignaciones y permisos de revisores. La migración original se recuperó en `database/migrations/002_project_reviewers.sql`. El archivo usa `CREATE TABLE IF NOT EXISTS`, no borra ni actualiza asignaciones, y registra la versión solo si detecta las columnas, claves y referencias esperadas.

**No ejecutes la migración hasta confirmar la base activa, guardar un respaldo de estructura y datos, y comparar la salida del preflight con la migración.** En phpMyAdmin selecciona la base publicada y ejecuta primero estas consultas de solo lectura:

```sql
SELECT VERSION() AS server_version, DATABASE() AS selected_database;

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'migrations','careers','users','projects','documents','analyses',
    'reviews','jobs','reports','settings','audit_logs','labels',
    'login_attempts','assignments','project_reviewers'
  )
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
    'migrations','careers','users','projects','documents','analyses',
    'reviews','jobs','reports','settings','audit_logs','labels',
    'login_attempts','assignments','project_reviewers'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('projects','users','project_reviewers')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME,
       k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE AS k
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
 AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
WHERE k.TABLE_SCHEMA = DATABASE()
  AND k.TABLE_NAME IN ('users','projects','project_reviewers')
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION;
```

If `migrations` exists, also check the applied-version marker:

```sql
SELECT version FROM migrations ORDER BY version;
```

Export the database from phpMyAdmin (structure and data) and preserve the matching `storage/documents/` and `storage/reports/` files before any DDL. If `project_reviewers` is absent and the parent-column types match, run `database/migrations/002_project_reviewers.sql` against that selected database only after approval. If the table already exists but differs, stop and inspect its `SHOW CREATE TABLE` output; do not try to reconcile it with `IF NOT EXISTS`.

After the migration, confirm that `project_reviewers` has `project_id`, `reviewer_id`, `assigned_by`, and `assigned_at`, composite primary key `(project_id, reviewer_id)`, and foreign keys to `projects.id`/`users.id` with project deletion cascade. Confirm that `migrations` contains `002_project_reviewers`.

Upload the updated `app/` and `public/` PHP files to `/htdocs` after backing up those files. `database/migrations/` is for phpMyAdmin and must not be copied into the public web root. `scripts/backup.php` is a local CLI utility; it is not required on the hosting.

To reverse an application-code regression, restore the backed-up PHP files. **Do not drop `project_reviewers` or delete its rows**: old and current application files may both depend on it, and dropping it destroys assignment data. A failed migration that leaves no 002 marker must be inspected before retrying.

## 1. Crear hosting y base de datos

En InfinityFree, crea una cuenta de hosting con tu dominio o un subdominio gratuito. En su panel, abre **MySQL Databases** y crea una base, por ejemplo `thesisguard`.

Anota el hostname, nombre completo de base, usuario y contraseña MySQL que indica el panel. No uses `localhost`, `127.0.0.1` ni el usuario `root` de XAMPP.

## 2. Preparar la copia local

El paquete anterior está en `storage/deployments/infinityfree-20261116-225735-04e96d/` y conserva una instantánea anterior de documentos y código. Para una instalación completa con los datos actuales, actualiza primero `database/thesisguard.sql` desde Exportar en phpMyAdmin local (estructura y datos), y genera una copia nueva con estos comandos desde la raíz del proyecto:

```powershell
$destino = "storage/deployments/infinityfree-$(Get-Date -Format yyyyMMdd-HHmmss)"
New-Item -ItemType Directory -Force "$destino/htdocs/config" | Out-Null

# 1) Solo los archivos que van al hosting
Copy-Item index.php, api.php, .htaccess, .user.ini "$destino/htdocs"
Copy-Item app, public, vendor "$destino/htdocs" -Recurse
Copy-Item config/example.php, config/infinityfree.example.php "$destino/htdocs/config"
Copy-Item config/infinityfree.example.php "$destino/htdocs/config/local.php"

# 2) storage protegido, con los documentos e informes actuales
New-Item -ItemType Directory -Force "$destino/htdocs/storage/documents", "$destino/htdocs/storage/reports" | Out-Null
Copy-Item storage/.htaccess "$destino/htdocs/storage"
Copy-Item storage/documents/* "$destino/htdocs/storage/documents" -ErrorAction SilentlyContinue
Copy-Item storage/reports/* "$destino/htdocs/storage/reports" -ErrorAction SilentlyContinue

# 3) El SQL completo está en database/thesisguard.sql; se importa por separado.

# 4) Zip opcional para subir de una vez
Add-Type -AssemblyName System.IO.Compression.FileSystem
$webPath = (Resolve-Path -LiteralPath "$destino/htdocs").Path
$zipPath = Join-Path (Resolve-Path -LiteralPath $destino).Path "thesisguard-subir.zip"
[System.IO.Compression.ZipFile]::CreateFromDirectory($webPath, $zipPath)
```

No copies `tests/`, `node_modules/`, `.tools/`, `database/`, `scripts/`, `storage/backups/`, `storage/deployments/`, `storage/archive/`, `docs/` ni tus claves locales: no son necesarios para el hosting.

Dentro encontrarás:

- `htdocs/`: archivos que debes subir, incluidas las dependencias `vendor/`.
- `thesisguard-subir.zip`: la misma carpeta `htdocs/` comprimida, opcional para subir más rápido.

El SQL permanece en **`database/thesisguard.sql`**, fuera del paquete, para mantener una sola copia de trabajo. Incluye estructura, datos y asignaciones. Se importa directamente desde esa ubicación en phpMyAdmin.


No necesitas subir node_modules, archivos de pruebas, copias de seguridad ni scripts de consola: el paquete no los incluye. `htdocs/config/local.php` se genera con valores de ejemplo para que los reemplaces.

## 3. Completar configuración

Edita **el archivo de la copia** `htdocs/config/local.php` y reemplaza `db_host`, `db_name`, `db_user`, `db_password` y `gemini_api_key`. Puedes copiar la clave Gemini de tu configuración local actual. Nunca pongas la clave en JavaScript.

El paquete ya deja `secure_cookies` en `true` para el hosting.

Activa HTTPS para el dominio antes de iniciar sesión: `secure_cookies` está en `true`. Si el dominio necesita un certificado, configúralo en la sección SSL de InfinityFree.

## 4. Importar datos y subir archivos

1. Abre phpMyAdmin desde el panel de InfinityFree y selecciona la base **vacía** recién creada.
2. En **Importar**, selecciona `database/thesisguard.sql` y ejecuta la importación. Ya contiene todas las tablas y los datos: no se importa ningún otro SQL.
3. Abre FileZilla con los datos FTP del panel. Entra en la carpeta `htdocs` correspondiente al dominio.
4. Sube **el contenido** de la carpeta local `htdocs`, incluyendo `.htaccess` y los `.htaccess` interiores. `index.php` debe quedar directamente en el htdocs remoto, sin otra carpeta htdocs anidada.
5. Comprueba que la cola de transferencias no tenga archivos fallidos. Deben existir `storage/documents` y `storage/reports`, incluso si están vacías.

## 5. Verificar

Abre `https://TU-DOMINIO/` en un navegador e inicia sesión con la misma cuenta y contraseña que utilizabas localmente. Revisa un proyecto, descarga un documento y prueba subir un PDF pequeño. En Configuración, pulsa **Probar conexión real** para verificar Gemini; luego prueba un análisis y un informe PDF.

Esta copia fue preparada localmente. La conexión MySQL, los permisos de escritura, las extensiones PHP y Gemini deben verificarse en el hosting.

Requisitos: PHP 8.2 o superior, PDO MySQL, mbstring, DOM, cURL, fileinfo y Phar para DOCX. Los límites de ejecución y recursos del hosting pueden impedir procesar documentos grandes o análisis largos. La aplicación procesa los trabajos durante las peticiones web y no requiere cron para subir y analizar.

## Problemas frecuentes

- **Error de base de datos:** revisa los cuatro valores MySQL y que la importación haya terminado.
- **La sesión no se mantiene:** accede por HTTPS con certificado válido.
- **Error 500:** comprueba versión PHP, dependencias completas y extensiones; revisa el registro del panel si está disponible. No borres las reglas que protegen config y storage.
- **No se guarda un documento/informe:** revisa existencia y permisos de escritura de storage/documents y storage/reports.
- **Gemini falla:** usa Probar conexión real para distinguir credenciales/cuota de problemas de red; las respuestas largas pueden exceder los recursos del plan gratuito.

Documentación oficial:

- https://forum.infinityfree.com/t/how-to-connect-with-mysql/49340
- https://forum.infinityfree.com/t/file-size-and-upload-limits/49308
- https://forum.infinityfree.com/t/can-i-connect-to-other-servers-from-my-website-sockets-curl-and-apis/49348
- https://forum.infinityfree.com/t/how-to-add-mysql-to-website-and-where-to-put-php-file/70677/2
