$ErrorActionPreference = 'Stop'
$checkRoot = Split-Path -Parent $PSScriptRoot
$checkPhp = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $checkPhp)) { $checkPhp = (Get-Command php -ErrorAction Stop).Source }
$checkNode = (Get-Command node -ErrorAction Stop).Source
$checkFailures = @()
$checkCount = 0
$checkPhpFiles = @('index.php', 'api.php', 'config/example.php', 'config/infinityfree.example.php') | ForEach-Object { Get-Item -LiteralPath (Join-Path $checkRoot $_) }
foreach ($checkDir in @('app','public','scripts')) {
    $checkPhpFiles += @(Get-ChildItem -LiteralPath (Join-Path $checkRoot $checkDir) -Recurse -File -Filter '*.php')
}
foreach ($checkFile in $checkPhpFiles) {
    $checkOutput = & $checkPhp -l $checkFile.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { $checkFailures += "$($checkFile.FullName): $checkOutput" }
    $checkCount++
}
$checkJsFiles = @(Get-ChildItem -LiteralPath (Join-Path $checkRoot 'public/assets') -File -Filter '*.js')
$checkJsFiles += @(Get-ChildItem -LiteralPath (Join-Path $checkRoot 'tests') -File -Filter '*.cjs')
foreach ($checkFile in $checkJsFiles) {
    $checkOutput = & $checkNode --check $checkFile.FullName 2>&1
    if ($LASTEXITCODE -ne 0) { $checkFailures += "$($checkFile.FullName): $checkOutput" }
    $checkCount++
}
if ($checkFailures.Count -gt 0) { $checkFailures | Write-Output; exit 1 }
Write-Output "Sintaxis correcta en $checkCount archivos PHP/JavaScript."
