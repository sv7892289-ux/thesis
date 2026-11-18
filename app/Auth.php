<?php
namespace ThesisGuard;
class Auth {
    public static function start(): void {
        session_name('thesisguard_session');
        session_set_cookie_params(['httponly'=>true,'secure'=>(bool)config('secure_cookies'),'samesite'=>'Strict','path'=>'/']);
        ini_set('session.use_strict_mode','1');
        session_start();
        if (isset($_SESSION['last']) && time()-$_SESSION['last']>7200) $_SESSION=[];
        $_SESSION['last']=time();
        $_SESSION['csrf']??=bin2hex(random_bytes(32));
    }
    public static function csrf(): void {
        if (!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??'')) fail('La sesión del formulario expiró. Actualiza la página.',403);
    }
    public static function user(): array {
        $u=one('SELECT id,name,email,role,career_id,active FROM users WHERE id=? AND active=1',[$_SESSION['uid']??0]);
        if (!$u) fail('Inicia sesión para continuar.',401);
        return $u;
    }
    public static function roles(array $u,array $roles): void { if(!in_array($u['role'],$roles,true)) fail('No tienes permisos para esta operación.',403); }
    public static function scope(array $u,string $alias='p'): array {
        if($u['role']==='admin')return ['1=1',[]];
        if($u['role']==='reviewer')return ["($alias.owner_id=? OR $alias.visibility='institution' OR ($alias.career_id=? AND $alias.visibility='career') OR EXISTS (SELECT 1 FROM project_reviewers pr WHERE pr.project_id=$alias.id AND pr.reviewer_id=?))",[$u['id'],$u['career_id']??0,$u['id']]];
        $staff=$u['role']==='coordinator';
        return ["($alias.owner_id=? OR $alias.visibility='institution' OR ($alias.career_id=? AND ".($staff?'1=1':"$alias.visibility='career'").'))',[$u['id'],$u['career_id']??0]];
    }
    public static function project(int $id,array $u,bool $write=false): array {
        [$scope,$params]=self::scope($u);
        $p=one("SELECT p.*,c.name career FROM projects p JOIN careers c ON c.id=p.career_id WHERE p.id=? AND $scope",[$id,...$params]);
        if(!$p)fail('Proyecto no encontrado o sin acceso.',404);
        if($write && !self::canEdit($p,$u))fail('No puedes editar este proyecto.',403);
        return $p;
    }
    public static function canEdit(array $p,array $u): bool {
        return $u['role']==='admin' || $p['owner_id']==$u['id'] || ($u['role']==='coordinator' && $p['career_id']==$u['career_id']);
    }
    public static function canReview(array $p,array $u): bool {
        if($u['role']==='admin')return true;
        if($u['role']==='coordinator')return (int)$p['career_id']===(int)$u['career_id'];
        return $u['role']==='reviewer' && (int)$p['career_id']===(int)$u['career_id'] && (bool)one('SELECT project_id FROM project_reviewers WHERE project_id=? AND reviewer_id=?',[$p['id'],$u['id']]);
    }
    public static function review(int $id,array $u): array {
        $p=self::project($id,$u);
        if(!self::canReview($p,$u))fail('No tienes asignada la revisión de este proyecto.',403);
        return $p;
    }
    public static function analysis(int $id,array $u): array {
        $a=one('SELECT * FROM analyses WHERE id=?',[$id]);
        if(!$a)fail('Análisis no encontrado.',404);
        self::project((int)$a['project_id'],$u);
        $snapshot=json_decode($a['snapshot'],true);
        foreach($snapshot['candidates']??[] as $c) self::project((int)$c['project_id'],$u);
        return $a;
    }
    public static function login(array $data): array {
        $email=mb_strtolower(field($data,'email',254)); $pass=field($data,'password',200);
        $ip=hash('sha256',$_SERVER['REMOTE_ADDR']??'local'); $eh=hash('sha256',$email);
        query('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(),INTERVAL 1 DAY)');
        $n=one('SELECT COUNT(*) n FROM login_attempts WHERE created_at>DATE_SUB(NOW(),INTERVAL 15 MINUTE) AND (ip_hash=? OR email_hash=?)',[$ip,$eh]);
        if($n['n']>=10)fail('Demasiados intentos. Vuelve a intentarlo en 15 minutos.',429);
        $u=one('SELECT * FROM users WHERE email=? AND active=1',[$email]);
        if(!$u || !password_verify($pass,$u['password_hash'])) {
            query('INSERT INTO login_attempts(ip_hash,email_hash) VALUES (?,?)',[$ip,$eh]);
            fail('Correo o contraseña incorrectos.',401);
        }
        session_regenerate_id(true); $_SESSION['uid']=$u['id']; $_SESSION['csrf']=bin2hex(random_bytes(32));
        query('DELETE FROM login_attempts WHERE email_hash=?',[$eh]);
        audit('Inicio de sesión',null,(int)$u['id']); unset($u['password_hash']);
        return ['user'=>$u,'csrf'=>$_SESSION['csrf']];
    }
}
