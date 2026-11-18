# ThesisGuard

Repositorio y revisión académica con PHP, MySQL y Gemini.

## Dónde trabajar

**Edita el código de `app/` y `public/` en esta raíz.** Las carpetas dentro de `storage/deployments/` son copias para subir al hosting; no son el código principal. Los paquetes antiguos están en `storage/archive/`.

```text
THESIS_IA_PHP/
├── app/                   Lógica PHP y arranque (init.php)
├── public/                Interfaz y API
│   └── assets/            JavaScript, CSS, fuentes e icono
├── config/                Configuración local y plantillas
├── database/              thesisguard.sql y migraciones incrementales
├── scripts/               Utilidades de consola vigentes
├── docs/                  Documentación
├── tests/                 Pruebas y resultados
├── storage/
│   ├── documents/         Originales subidos por los usuarios
│   ├── reports/           Informes generados
│   ├── logs/              Registros
│   ├── backups/           Respaldos de datos y archivos
│   ├── deployments/       Paquetes para InfinityFree
│   └── archive/           Copias y herramientas históricas
├── vendor/                Dependencias PHP instaladas por Composer
├── node_modules/          Dependencias de pruebas de navegador
├── .tools/                Composer y certificados TLS locales
├── index.php              Entrada web para XAMPP/htdocs
└── api.php                Entrada API para XAMPP/htdocs
```

Los archivos `index.php` y `api.php` de la raíz cargan los de `public/`. Son entradas necesarias para esta instalación, no copias que debas eliminar.

## Ejecutar la instalación local existente

1. Inicia Apache y MySQL en XAMPP.
2. Mantén los datos locales en `config/local.php`.
3. Abre `http://localhost/THESIS_IA_PHP/`.

El arranque PHP es `app/init.php`. Los documentos y análisis se procesan desde las solicitudes web; el sistema actual no necesita el antiguo lanzador de worker.

## Base de datos

Usa **`database/thesisguard.sql`** para importar la estructura completa y los datos actuales en una base vacía. Contiene 14 tablas y las cuentas existentes, con contraseñas cifradas. No es un parche para una base ya instalada.

Para una actualización incremental, revisa solo la migración correspondiente en `database/migrations/`; no importes el SQL completo sobre una base existente. Antes de ejecutar una migración remota, exporta y verifica un respaldo. La migración `002_project_reviewers.sql` restaura la tabla de asignaciones de revisores y no elimina registros.

Para actualizar el volcado local después de cambiar datos, exporta estructura y datos desde phpMyAdmin y guarda la descarga reemplazando `database/thesisguard.sql`. No se versiona en Git porque contiene datos de usuarios. Los SQL históricos se conservan comprimidos en `storage/archive/sql-historicos-*.zip`; `002_project_reviewers.sql` fue recuperada de ese archivo como migración utilizable.

## Roles

| Rol | Responsabilidad |
|---|---|
| Administrador | Usuarios, configuración, auditoría y gestión institucional |
| Coordinador | Proyectos de su carrera y asignación de revisores |
| Docente revisor | Revisión y dictámenes de proyectos asignados |
| Estudiante | Sus proyectos, documentos, análisis y observaciones recibidas |

Todos acceden por el mismo formulario. Los permisos se verifican también en PHP.

## Comandos útiles

Desde PowerShell, en esta carpeta:

```powershell
# Comprobar sintaxis del código actual (no modifica la base)
powershell -NoProfile -File scripts/check.ps1

# Respaldo de la base local con documentos e informes
& C:/xampp/php/php.exe scripts/backup.php
```

`scripts/check-gemini.php` realiza una llamada real a Gemini y consume cuota. `scripts/seed.php` agrega ejemplos ficticios a la base configurada. `scripts/restore.php` restaura en una base nueva y un directorio vacío; consulta su mensaje de uso antes de ejecutarlo.

## Documentación

- [Arquitectura y archivos que editar](docs/ESTRUCTURA.md).
- [Subida a InfinityFree](docs/INFINITYFREE.md).
- [Pruebas de navegador](docs/PRUEBAS.md).

El paquete existente fue movido a `storage/deployments/infinityfree-20261116-225735-04e96d/`. Es una instantánea: los cambios posteriores en el código principal no se copian automáticamente a su ZIP.
