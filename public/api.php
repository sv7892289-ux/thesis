<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/init.php';
use ThesisGuard\{Auth,Projects,Documents,Jobs,Gemini,Reports,HttpError,Assignments,Dashboard};
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
Auth::start();
try {
    $action=$_GET['action']??'session';$method=$_SERVER['REQUEST_METHOD'];$id=(int)($_GET['id']??0);
    if(!in_array($method,['GET','POST','PATCH'],true))fail('Método no permitido.',405);
    if($method!=='GET')Auth::csrf();
    $data=$method!=='GET'?input():[];
    if($action==='session') {
        $u=null;try{$u=Auth::user();}catch(HttpError $e){if($e->getCode()!==401)throw $e;}
        json(['user'=>$u,'csrf'=>$_SESSION['csrf'],'institution'=>setting('institution')]);
    }
    if($action==='login' && $method==='POST')json(Auth::login($data));
    $u=Auth::user();
    if($action==='logout' && $method==='POST'){audit('Cierre de sesión');$_SESSION=[];session_destroy();json(['ok'=>true]);}
    if($action==='careers' && $method==='GET')json(['items'=>all('SELECT * FROM careers ORDER BY name')]);
    if($action==='dashboard' && $method==='GET')json(Dashboard::data($u));
    if($action==='assignments') {
        if($method==='GET')json(Assignments::options($id,$u));
        if($method==='POST'){Assignments::save($id,$data,$u);json(['ok'=>true]);}
    }
    if($action==='projects') {
        if($method==='GET')json(Projects::listing($u,$_GET));
        if($method==='POST')json(['id'=>Projects::save($data,$u)],201);
    }
    if($action==='project') {
        if($method==='PATCH')json(['id'=>Projects::save($data,$u,$id)]);
        if($method==='GET') {
            $p=Auth::project($id,$u);
            $docs=all('SELECT id,version,filename,sha256,size,status,error,created_at FROM documents WHERE project_id=? ORDER BY version DESC',[$id]);
            $analyses=all('SELECT id,status,model,error,created_at FROM analyses WHERE project_id=? ORDER BY id DESC',[$id]);
            json(['project'=>$p,'documents'=>$docs,'analyses'=>$analyses,'reviewers'=>Assignments::listing($id),'can_assign'=>Assignments::manager($p,$u),'can_review'=>Auth::canReview($p,$u)]);
        }
    }
    if($action==='upload' && $method==='POST') {
        $p=Auth::project($id,$u,true);
        $doc=Documents::upload($_FILES['file']??[],$p);
        session_write_close();ignore_user_abort(true);set_time_limit(120);ini_set('memory_limit','384M');
        Jobs::runOne(null,$doc);
        $d=one("SELECT status,error,extracted_text FROM documents WHERE id=?",[$doc]);
        $text=trim((string)($d['extracted_text']??''));
        json(['id'=>$doc,'status'=>$d['status']??'pendiente','error'=>$d['error']??null,'characters'=>mb_strlen($text),'excerpt'=>mb_substr($text,0,900)],201);
    }
    if($action==='document' && $method==='GET') {
        $d=one('SELECT * FROM documents WHERE id=?',[$id]);if(!$d)fail('Documento no encontrado.',404);Auth::project((int)$d['project_id'],$u);
        $excerpt=mb_substr(trim((string)($d['extracted_text']??'')),0,900);
        unset($d['path'],$d['extracted_text']);
        $d['sections']=!empty($_GET['brief'])?[]:json_decode($d['sections']??'{}',true);$d['excerpt']=$excerpt;json($d);
    }
    if($action==='download' && $method==='GET') {
        $type=$_GET['type']??'document';enum_value($type,['document','report']);
        $r=one('SELECT * FROM '.($type==='report'?'reports':'documents').' WHERE id=?',[$id]);if(!$r)fail('Archivo no encontrado.',404);
        if($type==='report')Auth::analysis((int)$r['analysis_id'],$u);else Auth::project((int)$r['project_id'],$u);
        $path=ROOT.'/storage/'.$r['path'];if(!is_file($path)||!hash_equals($r['sha256'],hash_file('sha256',$path)))fail('El archivo falta o no supera la verificación de integridad.',409);
        $name=$type==='report'?'ThesisGuard-informe-'.$id.'.pdf':$r['filename'];
        header('Content-Type: '.(str_ends_with($path,'.pdf')?'application/pdf':'application/vnd.openxmlformats-officedocument.wordprocessingml.document'));header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($name));header('Content-Length: '.filesize($path));session_write_close();readfile($path);exit;
    }
    if($action==='analyze' && $method==='POST') {
        if(($data['consent']??false)!==true)fail('Confirma el envío de extractos a Gemini.');
        Auth::project($id,$u,true);
        $aid=transaction(function()use($u,$id){
            query('SELECT id FROM users WHERE id=? FOR UPDATE',[$u['id']]);
            query('SELECT id FROM projects WHERE id=? FOR UPDATE',[$id]);
            $snapshot=Projects::snapshot($id,$u);
            $count=(int)one('SELECT COUNT(*) n FROM analyses WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)',[$u['id']])['n'];
            if($count>=20)fail('Límite de 20 análisis por hora alcanzado.',429);
            $existing=one("SELECT id FROM analyses WHERE project_id=? AND status IN ('pendiente','procesando')",[$id]);if($existing)fail('Este proyecto ya tiene un análisis en curso.',409);
            query('INSERT INTO analyses(project_id,user_id,document_id,model,snapshot) VALUES (?,?,?,?,?)',[$id,$u['id'],$snapshot['source']['document_id'],config('gemini_model'),json_encode($snapshot,JSON_UNESCAPED_UNICODE)]);
            $aid=(int)db()->lastInsertId();Jobs::enqueue('analysis',$aid);audit('Análisis solicitado; envío a Gemini autorizado',$aid);return $aid;
        });
        session_write_close();
        ignore_user_abort(true);
        set_time_limit(130);
        Jobs::runOne($aid);
        $status=one('SELECT status FROM analyses WHERE id=?',[$aid])['status']??'pendiente';
        json(['id'=>$aid,'status'=>$status],201);
    }
    if($action==='analyses' && $method==='GET') {
        [$scope,$args]=Auth::scope($u);
        $items=all("SELECT a.id,a.project_id,a.status,a.model,a.error,a.created_at,p.title,p.is_demo,u.name requester,(SELECT COUNT(*) FROM reviews r WHERE r.analysis_id=a.id) review_count FROM analyses a JOIN projects p ON p.id=a.project_id JOIN users u ON u.id=a.user_id WHERE $scope ORDER BY a.id DESC LIMIT 100",$args);json(['items'=>$items]);
    }
    if($action==='analysis' && $method==='GET') {
        $a=Auth::analysis($id,$u);
        if($a['status']==='pendiente'){
            session_write_close();
            ignore_user_abort(true);
            set_time_limit(130);
            Jobs::runOne($id);
            $a=Auth::analysis($id,$u);
        }
        $a['result']=json_decode($a['result']??'null',true);$a['snapshot']=json_decode($a['snapshot'],true);
        $a['project']=Auth::project((int)$a['project_id'],$u);$a['can_review']=Auth::canReview($a['project'],$u);$a['reviews']=all('SELECT r.*,u.name reviewer FROM reviews r JOIN users u ON u.id=r.user_id WHERE analysis_id=? ORDER BY r.id DESC',[$id]);
        $a['labels']=all('SELECT candidate_id,relevance FROM labels WHERE analysis_id=? AND user_id=?',[$id,$u['id']]);json($a);
    }
    if($action==='review' && $method==='POST') {
        Auth::roles($u,['admin','coordinator','reviewer']);$a=Auth::analysis($id,$u);Auth::review((int)$a['project_id'],$u);
        if($a['status']!=='completado')fail('El análisis no está completado.');
        $verdict=enum_value(field($data,'verdict'),['pendiente','observado','aprobado']);
        transaction(function()use($data,$id,$u,$a,$verdict){
            query('INSERT INTO reviews(analysis_id,user_id,verdict,observations,criteria,recommendations) VALUES (?,?,?,?,?,?)',[$id,$u['id'],$verdict,field($data,'observations',8000),field($data,'criteria',4000),field($data,'recommendations',4000,false)]);
            query('UPDATE projects SET status=? WHERE id=?',[$verdict==='pendiente'?'en_revision':$verdict,$a['project_id']]);audit('Revisión docente registrada',$id);
        });json(['ok'=>true]);
    }
    if($action==='label' && $method==='POST') {
        Auth::roles($u,['admin','coordinator','reviewer']);$a=Auth::analysis($id,$u);Auth::review((int)$a['project_id'],$u);
        $candidate=(int)($data['candidate_id']??0);$ids=array_column(json_decode($a['snapshot'],true)['candidates'],'project_id');if(!in_array($candidate,$ids))fail('Candidato no incluido en este análisis.');
        $relevance=filter_var($data['relevance']??null,FILTER_VALIDATE_INT);if($relevance===false || $relevance<0||$relevance>3)fail('Relevancia inválida.');
        query('INSERT INTO labels(analysis_id,candidate_id,user_id,relevance) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE relevance=VALUES(relevance),created_at=CURRENT_TIMESTAMP',[$id,$candidate,$u['id'],$relevance]);audit('Relevancia humana registrada',$id);json(['ok'=>true]);
    }
    if($action==='reports') {
        if($method==='POST')json(['id'=>Reports::create($id,$u)],201);
        if($method==='GET') {[$scope,$args]=Auth::scope($u);json(['items'=>all("SELECT r.id,r.analysis_id,r.institution,r.sha256,r.created_at,p.title,u.name author FROM reports r JOIN analyses a ON a.id=r.analysis_id JOIN projects p ON p.id=a.project_id JOIN users u ON u.id=r.user_id WHERE $scope ORDER BY r.id DESC LIMIT 100",$args)]);}
    }
    if($action==='jobs' && $method==='GET') {
        [$scope,$args]=Auth::scope($u);
        $items=all("SELECT j.*,p.title,p.owner_id,p.career_id FROM jobs j LEFT JOIN documents d ON j.kind='document' AND d.id=j.target_id LEFT JOIN analyses a ON j.kind='analysis' AND a.id=j.target_id JOIN projects p ON p.id=COALESCE(d.project_id,a.project_id) WHERE $scope ORDER BY j.id DESC LIMIT 100",$args);
        foreach($items as &$job){$job['can_process']=Auth::canEdit($job,$u);unset($job['owner_id'],$job['career_id']);}unset($job);
        json(['items'=>$items,'heartbeat'=>setting('worker_heartbeat')]);
    }
    if(in_array($action,['retry','process-job'],true) && $method==='POST'){
        session_write_close();ignore_user_abort(true);set_time_limit(130);ini_set('memory_limit','384M');
        json(Jobs::process($id,$u,$action==='retry'));
    }
    if($action==='users') {
        Auth::roles($u,['admin']);
        if($method==='GET')json(['items'=>all('SELECT u.id,u.name,u.email,u.role,u.career_id,u.active,u.created_at,c.name career FROM users u LEFT JOIN careers c ON c.id=u.career_id ORDER BY u.id DESC')]);
        if($method==='POST') {
            $email=mb_strtolower(field($data,'email',254));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Correo no válido.');
            $password=field($data,'password',200);if(mb_strlen($password)<12)fail('La contraseña debe tener al menos 12 caracteres.');
            $role=enum_value(field($data,'role'),['admin','coordinator','reviewer','student']);$career=(int)($data['career_id']??0);
            if(!one('SELECT id FROM careers WHERE id=?',[$career]))fail('Selecciona una carrera.');
            if(one('SELECT id FROM users WHERE email=?',[$email]))fail('Ya existe una cuenta con ese correo.',409);
            query('INSERT INTO users(name,email,password_hash,role,career_id) VALUES (?,?,?,?,?)',[field($data,'name',160),$email,password_hash($password,PASSWORD_DEFAULT),$role,$career]);audit('Usuario creado',(int)db()->lastInsertId());json(['ok'=>true],201);
        }
        if($method==='PATCH') {
            if($id==$u['id'])fail('No puedes desactivar tu propia cuenta.');
            if(!isset($data['active']) || !is_bool($data['active']))fail('Indica si la cuenta debe estar activa.');
            if(!one('SELECT id FROM users WHERE id=?',[$id]))fail('Usuario no encontrado.',404);
            query('UPDATE users SET active=? WHERE id=?',[(int)!empty($data['active']),$id]);audit('Estado de usuario actualizado',$id);json(['ok'=>true]);
        }
    }
    if($action==='profile' && $method==='PATCH') {
        $old=one('SELECT password_hash FROM users WHERE id=?',[$u['id']]);if(!password_verify(field($data,'current_password',200),$old['password_hash']))fail('La contraseña actual no coincide.');
        $new=field($data,'password',200);if(mb_strlen($new)<12)fail('Usa al menos 12 caracteres.');query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),$u['id']]);session_regenerate_id(true);audit('Contraseña actualizada');json(['ok'=>true]);
    }
    if($action==='settings') {
        Auth::roles($u,['admin']);
        if($method==='GET')json(['institution'=>setting('institution'),'model'=>config('gemini_model'),'key_configured'=>(bool)config('gemini_api_key'),'gemini_status'=>setting('gemini_status'),'php'=>PHP_VERSION,'database'=>one('SELECT VERSION() v')['v'],'worker_heartbeat'=>setting('worker_heartbeat'),'max_upload_mb'=>config('max_upload_mb'),'labels'=>(int)one('SELECT COUNT(*) n FROM labels')['n']]);
        if($method==='POST'){query("UPDATE settings SET value=? WHERE name='institution'",[field($data,'institution',200)]);audit('Institución actualizada');json(['ok'=>true]);}
    }
    if($action==='gemini-check' && $method==='POST') {
        Auth::roles($u,['admin']);session_write_close();
        try{$r=Gemini::check();query("UPDATE settings SET value=? WHERE name='gemini_status'",['Verificado · '.date('d/m/Y H:i')]);json($r);}catch(\Throwable $e){error_log(get_class($e).': '.$e->getMessage());query("UPDATE settings SET value=? WHERE name='gemini_status'",['Error de conexión · '.date('d/m/Y H:i')]);fail('No se pudo verificar la conexión con Gemini. Revisa el registro del servidor.',502);}
    }
    if($action==='audit' && $method==='GET') {
        Auth::roles($u,['admin']);$page=max(1,(int)($_GET['page']??1));$offset=($page-1)*25;
        json(['items'=>all("SELECT a.*,u.name actor FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 25 OFFSET $offset"),'total'=>(int)one('SELECT COUNT(*) n FROM audit_logs')['n'],'page'=>$page]);
    }
    fail('Operación no encontrada.',404);
} catch(HttpError $e) {json(['error'=>$e->getMessage()],$e->getCode()?:422);}
catch(Throwable $e) { error_log(get_class($e).': '.$e->getMessage());json(['error'=>'No se pudo completar la operación. Revisa el registro del servidor.'],500); }
