<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/app/init.php';
[$script,$source,$target,$dest]=array_pad($argv,4,null);
if(!$source||!$target||!$dest)exit("Uso: php scripts/restore.php RUTA_RESPALDO NUEVA_BASE CARPETA_VACIA\n");
if(!preg_match('/^[a-zA-Z0-9_]+$/',$target)||$target===config('db_name'))throw new RuntimeException('Usa un nombre nuevo de base de datos.');
$source=realpath($source);if(!$source)throw new RuntimeException('Respaldo inexistente.');
$manifest=json_decode(file_get_contents($source.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
if(($manifest['format']??'')!=='thesisguard-backup-v1'||!in_array($manifest['schema']??'',['001_initial','002_project_reviewers'],true))throw new RuntimeException('Formato de respaldo incompatible.');
if(!hash_equals($manifest['database_sha256'],hash_file('sha256',$source.'/database.json')))throw new RuntimeException('Falló la integridad de la base.');
$data=json_decode(file_get_contents($source.'/database.json'),true,512,JSON_THROW_ON_ERROR);
$tables=['migrations','careers','users','projects','documents','analyses','jobs','reviews','labels','reports','settings','audit_logs','login_attempts'];
if(($manifest['schema']??'')==='002_project_reviewers')$tables[]='project_reviewers';
if(array_keys($data)!==$tables)throw new RuntimeException('Tablas del respaldo no válidas.');
foreach($manifest['files'] as $path=>$hash){
    if(!preg_match('~^(documents|reports)/[a-f0-9]{40}\.(pdf|docx)$~',$path)||!is_file($source.'/'.$path)||!hash_equals($hash,hash_file('sha256',$source.'/'.$path)))throw new RuntimeException('Archivo inválido en el respaldo.');
}
foreach([...$data['documents'],...$data['reports']] as $row)if(($manifest['files'][$row['path']]??null)!==$row['sha256'])throw new RuntimeException('El manifiesto no cubre todos los archivos.');
if(is_dir($dest)&&count(scandir($dest))>2)throw new RuntimeException('La carpeta de destino debe estar vacía.');
$server=new PDO('mysql:host='.config('db_host').';port='.config('db_port').';charset=utf8mb4',config('db_user'),config('db_password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$check=$server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=?');$check->execute([$target]);if($check->fetch())throw new RuntimeException('La base de destino ya existe. No se sobrescribe.');
$server->exec("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$server->exec("USE `$target`");
// Solo usa la estructura del SQL unificado; los datos proceden del respaldo elegido.
foreach(explode(';',file_get_contents(ROOT.'/database/thesisguard.sql')) as $statement)if(str_starts_with(trim($statement),'CREATE TABLE'))$server->exec($statement);
$server->beginTransaction();
try {
    foreach($tables as $table){
        $columns=$server->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        foreach($data[$table] as $row){
            if(array_diff(array_keys($row),$columns))throw new RuntimeException('Columnas no válidas en '.$table);
            $keys=array_keys($row);$sql="INSERT INTO `$table` (`".implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')';
            $server->prepare($sql)->execute(array_values($row));
        }
    }
    $server->exec("INSERT IGNORE INTO migrations(version) VALUES ('002_project_reviewers')");
    if(!is_dir($dest))mkdir($dest,0770,true);
    foreach($manifest['files'] as $path=>$hash){if(!is_dir(dirname($dest.'/'.$path)))mkdir(dirname($dest.'/'.$path),0770,true);if(!copy($source.'/'.$path,$dest.'/'.$path)||!hash_equals($hash,hash_file('sha256',$dest.'/'.$path)))throw new RuntimeException('No se pudo restaurar un archivo.');}
    $server->commit();
}catch(Throwable $e){$server->rollBack();throw $e;}
echo "Restauración verificada en $target y $dest. La instalación habitual no se modificó.\n";
