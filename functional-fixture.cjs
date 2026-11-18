// Run PHP as an argument, without a shell or persistent web-accessible setup script.
const {spawn}=require('node:child_process');
const path=require('node:path');
const php=String.raw`
require getcwd().'/app/init.php';
$mode=$argv[1]??'setup';$fixture=$argv[2]??'';
if($mode!=='setup') {
    $fixture=realpath($fixture)?:'';
    if(!preg_match('~[/\\\\]thesisguard_functional_[a-f0-9]{8}$~',$fixture))throw new RuntimeException('Ruta de pruebas inválida.');
    $cfg=require $fixture.'/config/local.php';
    if(!preg_match('/^thesisguard_functional_[a-f0-9]{8}$/',$cfg['db_name'])||$cfg['db_name']===config('db_name'))throw new RuntimeException('Base de pruebas inválida.');
    $pdo=new PDO('mysql:host='.$cfg['db_host'].';port='.$cfg['db_port'].';dbname='.$cfg['db_name'],$cfg['db_user'],$cfg['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if($mode==='cleanup'){$pdo->exec('DROP DATABASE '.$cfg['db_name']);echo "Base de pruebas eliminada.\n";exit;}
    if($mode==='lock'){$acquired=$pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(), '_worker'),0)")->fetchColumn();if(!$acquired)throw new RuntimeException('No se obtuvo el bloqueo.');echo "LOCKED\n";flush();fgets(STDIN);$pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '_worker'))");exit;}
    throw new RuntimeException('Modo inválido.');
}
$name='thesisguard_functional_'.bin2hex(random_bytes(4));$fixture=dirname(ROOT).'/'.$name;
if(file_exists($fixture))throw new RuntimeException('La carpeta ya existe.');
$pdo=new PDO('mysql:host='.config('db_host').';port='.config('db_port').';charset=utf8mb4',config('db_user'),config('db_password'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$pdo->exec('USE '.$name);
foreach(explode(';',file_get_contents(ROOT.'/database/thesisguard.sql')) as $sql)if(str_starts_with(trim($sql),'CREATE TABLE'))$pdo->exec($sql);
$exec=function($sql,$args=[])use($pdo){$s=$pdo->prepare($sql);$s->execute($args);return (int)$pdo->lastInsertId();};
$copy=function($from,$to)use(&$copy){if(is_dir($from)){mkdir($to,0770,true);foreach(scandir($from) as $f)if($f!=='.'&&$f!=='..')$copy($from.'/'.$f,$to.'/'.$f);}elseif(!copy($from,$to))throw new RuntimeException('No se pudo copiar '.$from);};
mkdir($fixture);
foreach(['app','public','vendor'] as $dir)$copy(ROOT.'/'.$dir,$fixture.'/'.$dir);
foreach(['index.php','api.php','.htaccess'] as $f)copy(ROOT.'/'.$f,$fixture.'/'.$f);
file_put_contents($fixture.'/.htaccess',"Require local\n".file_get_contents($fixture.'/.htaccess'));
mkdir($fixture.'/config');copy(ROOT.'/config/example.php',$fixture.'/config/example.php');
$cfg=['db_host'=>config('db_host'),'db_port'=>config('db_port'),'db_name'=>$name,'db_user'=>config('db_user'),'db_password'=>config('db_password'),'gemini_api_key'=>'','secure_cookies'=>false];
file_put_contents($fixture.'/config/local.php',"<?php\nreturn ".var_export($cfg,true).";\n");
foreach(['documents','reports','logs'] as $dir)mkdir($fixture.'/storage/'.$dir,0770,true);
$exec("INSERT INTO careers(id,name) VALUES (1,'Sistemas de prueba'),(2,'Otra carrera')");
$password=bin2hex(random_bytes(12));$users=[];
foreach(['admin','coordinator','reviewer','student'] as $role)$users[$role]=$exec('INSERT INTO users(name,email,password_hash,role,career_id) VALUES (?,?,?,?,1)',[$role,$role.'@test.local',password_hash($password,PASSWORD_DEFAULT),$role]);
$other=$exec("INSERT INTO users(name,email,password_hash,role,career_id) VALUES ('Otro revisor','other@test.local',?,'reviewer',2)",[password_hash($password,PASSWORD_DEFAULT)]);
$projects=[];
foreach([['Privado estudiante',1,$users['student'],'private'],['Institucional',2,$users['admin'],'institution'],['Carrera',1,$users['admin'],'career']] as [$title,$career,$owner,$visibility])$projects[]=$exec('INSERT INTO projects(title,authors,career_id,owner_id,year,visibility,abstract) VALUES (?,?,?,?,2026,?,?)',[$title,'Autor de prueba',$career,$owner,$visibility,str_repeat('Resumen académico. ',20)]);
$text=str_repeat('Documento de prueba para evaluar la extracción, los objetivos y la metodología de investigación. ',8);
$pdf=new Dompdf\Dompdf();$pdf->loadHtml('<html><body><p>'.$text.'</p></body></html>');$pdf->render();$bytes=$pdf->output();$docs=[];$jobs=[];
foreach(['completado','error','pendiente','procesando'] as $i=>$status){
    $path='documents/'.bin2hex(random_bytes(20)).'.pdf';file_put_contents($fixture.'/storage/'.$path,$bytes);
    $docs[]=$exec('INSERT INTO documents(project_id,version,filename,path,sha256,size,status,extracted_text,sections,error) VALUES (?,?,?,?,?,?,?,?,?,?)',[$projects[0],$i+1,'prueba.pdf',$path,hash('sha256',$bytes),strlen($bytes),$status,$i===0?$text:null,'{}',$status==='error'?'Interrupción de prueba':null]);
    $jobs[]=$exec("INSERT INTO jobs(kind,target_id,status,attempts) VALUES ('document',?,?,?)",[$docs[$i],$status,$i===0?1:0]);
}
$snapshot=['source'=>['project_id'=>$projects[0],'document_id'=>$docs[0],'title'=>'Privado estudiante','version'=>1,'sha256'=>hash('sha256',$bytes),'text'=>$text,'total_characters'=>mb_strlen($text),'sent_characters'=>mb_strlen($text)],'candidates'=>[],'examined'=>0,'retrieval'=>'Fixture local sin IA.'];
$result=['summary'=>'Resultado sintético exclusivamente para pruebas.','strengths'=>['Objetivos presentes'],'gaps'=>['Precisar metodología'],'recommendations'=>['Revisar objetivos'],'comparisons'=>[],'limitations'=>'Fixture local sin IA.'];
$analysis=$exec("INSERT INTO analyses(project_id,user_id,document_id,status,model,snapshot,result) VALUES (?,?,?,'completado','fixture',?,?)",[$projects[0],$users['student'],$docs[0],json_encode($snapshot),json_encode($result)]);
$exec("INSERT INTO settings(name,value) VALUES ('institution','Institución de pruebas'),('worker_heartbeat',''),('gemini_status','No probado')");
$state=compact('password','users','other','projects','analysis','docs','jobs','fixture');$state['url']='http://localhost/'.$name.'/';
file_put_contents($fixture.'/state.json',json_encode($state));
echo json_encode(['fixture'=>$fixture,'state'=>$fixture.'/state.json','url'=>$state['url']]);
`;
function startFixture(mode='setup',fixture=''){
 return spawn(process.env.PHP_BINARY||'C:/xampp/php/php.exe',['-r',php,mode,fixture],{cwd:path.resolve(__dirname,'..'),stdio:['pipe','pipe','pipe'],windowsHide:true});
}
module.exports={startFixture};
if(require.main===module){const child=startFixture(process.argv[2],process.argv[3]);child.stdout.pipe(process.stdout);child.stderr.pipe(process.stderr);child.on('error',e=>{console.error(e);process.exitCode=1;});child.on('exit',code=>process.exitCode=code??1);}
