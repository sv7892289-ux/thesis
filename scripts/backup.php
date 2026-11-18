<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/app/init.php';
$dir=ROOT.'/storage/backups/'.date('Ymd-His').'-'.bin2hex(random_bytes(3));mkdir($dir,0770,true);
$hasReviewerAssignments=(int)one(
    "SELECT COUNT(*) AS n
     FROM information_schema.tables
     WHERE table_schema=DATABASE()
     AND table_name='project_reviewers'"
)['n']>0;
$tables=['migrations','careers','users','projects','documents','analyses','jobs','reviews','labels','reports','settings','audit_logs','login_attempts'];
if($hasReviewerAssignments)$tables[]='project_reviewers';
$data=[];db()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');db()->beginTransaction();
try {
    foreach($tables as $table)$data[$table]=all("SELECT * FROM `$table`");
    db()->commit();
}catch(Throwable $e){db()->rollBack();throw $e;}
file_put_contents($dir.'/database.json',json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$files=[];
foreach([...$data['documents'],...$data['reports']] as $row){
    $path=$row['path'];if(!preg_match('~^(documents|reports)/[a-f0-9]{40}\.(pdf|docx)$~',$path))throw new RuntimeException('Ruta de archivo no válida.');
    $source=ROOT.'/storage/'.$path;
    if(!is_file($source)||!hash_equals($row['sha256'],hash_file('sha256',$source)))throw new RuntimeException('Archivo ausente o alterado: '.$path);
    if(!is_dir(dirname($dir.'/'.$path)))mkdir(dirname($dir.'/'.$path),0770,true);
    if(!copy($source,$dir.'/'.$path))throw new RuntimeException('No se pudo copiar '.$path);
    $files[$path]=$row['sha256'];
}
$manifest=['format'=>'thesisguard-backup-v1','created_at'=>date('c'),'database'=>config('db_name'),'schema'=>$hasReviewerAssignments?'002_project_reviewers':'001_initial','database_sha256'=>hash_file('sha256',$dir.'/database.json'),'files'=>$files];
file_put_contents($dir.'/manifest.json',json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo "Respaldo completo: $dir\n";
