<?php
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
$public = basename(dirname($_SERVER['SCRIPT_NAME'])) === 'public';
$assets = $public ? 'assets/' : 'public/assets/';
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="theme-color" content="#0b2340"><meta name="color-scheme" content="light"><meta name="description" content="ThesisGuard: repositorio y revisión académica asistida por inteligencia artificial."><title>ThesisGuard · Investigación con evidencia</title><link rel="icon" type="image/svg+xml" href="<?= $assets ?>favicon.svg"><link rel="preload" href="<?= $assets ?>fonts/fraunces.woff2" as="font" type="font/woff2" crossorigin><link rel="preload" href="<?= $assets ?>fonts/manrope.woff2" as="font" type="font/woff2" crossorigin><link rel="stylesheet" href="<?= $assets ?>app.css?v=14"><link rel="stylesheet" href="<?= $assets ?>role-panels.css?v=1"></head>
<body><div id="app"><div class="initial-loader"><span class="loader"></span>Preparando tu espacio de investigación…</div></div><div id="toast" role="status" aria-live="polite"></div><dialog id="modal"></dialog><script src="<?= $assets ?>role-panels.js?v=1" defer></script><script src="<?= $assets ?>app.js?v=16" defer></script></body>
</html>
