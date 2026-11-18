# Verificación

## Comprobación rápida

```powershell
powershell -NoProfile -File scripts/check.ps1
```

Valida la sintaxis PHP del código principal y de los scripts, y la sintaxis JavaScript de la interfaz y las pruebas. No recorre `vendor/`, paquetes ni archivos históricos. No modifica usuarios ni proyectos.

## Pruebas de roles en navegador

`tests/role-panels.cjs` usa Playwright con Microsoft Edge. Necesita una instalación de pruebas aislada y su archivo JSON de estado; no crea el entorno automáticamente. Las bases y rutas de ejecuciones anteriores no se reutilizan de manera implícita.

```powershell
$env:TEST_URL = 'http://localhost/MI_INSTALACION_DE_PRUEBA/'
npm run test:roles -- RUTA_AL_ESTADO_DE_PRUEBA.json
```

El estado contiene `password`, `users` (IDs de admin, coordinator, reviewer y student), `other` (ID de revisor de otra carrera), `projects` (IDs de los tres proyectos) y `analysis` (ID del análisis completado). Las cuentas se llaman `admin@test.local`, `coordinator@test.local`, `reviewer@test.local` y `student@test.local`.

Los escenarios esperan un proyecto privado del estudiante en la carrera 1, uno institucional del administrador en la carrera 2, y uno de carrera del administrador en la carrera 1. El análisis corresponde al primero, sin revisiones ni asignaciones iniciales. La prueba registra un dictamen y crea/retira asignaciones: no debe ejecutarse sobre la instalación habitual ni InfinityFree.

Los resultados y capturas se guardan en `tests/artifacts/role-panels/`. Los resultados existentes son evidencia de su ejecución original, no una comprobación automática de cambios futuros.

La prueba antigua `browser.cjs` y su lanzador se archivaron porque dependían de `scripts/process-queue.php`, que no existe en esta versión.
