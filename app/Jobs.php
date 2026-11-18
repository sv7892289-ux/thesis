<?php
namespace ThesisGuard;
class Jobs {
    public static function enqueue(string $kind,int $target): void {
        if(!db()->inTransaction())throw new \LogicException('El destino y su trabajo deben guardarse en la misma transacción.');
        query('INSERT INTO jobs(kind,target_id) VALUES (?,?)',[$kind,$target]);
    }
    public static function recover(): void {
        transaction(function(){
            foreach(['document'=>'documents','analysis'=>'analyses'] as $kind=>$table) {
                query("INSERT IGNORE INTO jobs(kind,target_id) SELECT ?,t.id FROM $table t LEFT JOIN jobs j ON j.kind=? AND j.target_id=t.id WHERE t.status IN ('pendiente','procesando') AND j.id IS NULL",[$kind,$kind]);
                // Called only after obtaining the worker lock: no other active worker is interrupted.
                query("UPDATE $table t JOIN jobs j ON j.target_id=t.id AND j.kind=? SET t.status='pendiente', j.status='pendiente', j.started_at=NULL WHERE j.status='procesando'",[$kind]);
            }
        });
    }
    public static function runOne(?int $analysisId=null, ?int $documentId=null): ?array {
        if(!(int)one("SELECT GET_LOCK(CONCAT(DATABASE(), '_worker'),0) acquired")['acquired'])return null;
        try {
            query("UPDATE settings SET value=? WHERE name='worker_heartbeat'",[date('c')]);
            self::recover();
            $job=transaction(function() use ($analysisId,$documentId){
                if($analysisId)
                    $j=one("SELECT * FROM jobs WHERE kind='analysis' AND target_id=? AND status='pendiente' ORDER BY id LIMIT 1 FOR UPDATE",[$analysisId]);
                elseif($documentId)
                    $j=one("SELECT * FROM jobs WHERE kind='document' AND target_id=? AND status='pendiente' ORDER BY id LIMIT 1 FOR UPDATE",[$documentId]);
                else
                    $j=one("SELECT * FROM jobs WHERE status='pendiente' ORDER BY kind='analysis' DESC, id ASC LIMIT 1 FOR UPDATE");
                if(!$j)return null;
                if($j['attempts']>=3) {
                    $table=$j['kind']==='document'?'documents':'analyses';
                    query("UPDATE $table SET status='error',error='Se agotaron tres intentos. Revisa el archivo o servicio y reintenta.' WHERE id=?",[$j['target_id']]);
                    query("UPDATE jobs SET status='error',error='Límite de intentos alcanzado',finished_at=NOW() WHERE id=?",[$j['id']]);
                    return null;
                }
                query("UPDATE jobs SET status='procesando',attempts=attempts+1,started_at=NOW(),error=NULL WHERE id=?",[$j['id']]);
                $table=$j['kind']==='document'?'documents':'analyses';
                query("UPDATE $table SET status='procesando',error=NULL WHERE id=?",[$j['target_id']]);
                return $j;
            });
            if(!$job)return null;
            try {
                if($job['kind']==='document') {
                    $d=one('SELECT * FROM documents WHERE id=?',[$job['target_id']]);
                    $path=ROOT.'/storage/'.$d['path'];
                    if(!is_file($path) || !hash_equals($d['sha256'],hash_file('sha256',$path)))throw new \RuntimeException('El original falta o su huella SHA-256 cambió.');
                    $result=Documents::extract($path,strtolower(pathinfo($path,PATHINFO_EXTENSION)));
                    transaction(function()use($job,$result){
                        query("UPDATE documents SET status='completado',extracted_text=?,sections=?,error=NULL WHERE id=?",[$result['text'],json_encode($result['sections'],JSON_UNESCAPED_UNICODE),$job['target_id']]);
                        self::complete($job);
                    });
                } else {
                    $a=one('SELECT * FROM analyses WHERE id=?',[$job['target_id']]);
                    $u=one('SELECT * FROM users WHERE id=? AND active=1',[$a['user_id']]);
                    if(!$u)throw new \RuntimeException('La cuenta que solicitó el análisis ya no está activa.');
                    Auth::analysis((int)$a['id'],$u);
                    $result=Gemini::analyze(json_decode($a['snapshot'],true));
                    transaction(function()use($job,$result){
                        query("UPDATE analyses SET status='completado',result=?,finished_at=NOW(),error=NULL WHERE id=?",[json_encode($result,JSON_UNESCAPED_UNICODE),$job['target_id']]);
                        self::complete($job);
                    });
                }
            } catch(\Throwable $e) {
                $error=$e instanceof \PDOException?'No se pudo completar la operación de base de datos.':mb_substr($e->getMessage(),0,1000);
                transaction(function()use($job,$error){
                    $table=$job['kind']==='document'?'documents':'analyses';
                    query("UPDATE $table SET status='error',error=? WHERE id=?",[$error,$job['target_id']]);
                    query("UPDATE jobs SET status='error',error=?,finished_at=NOW() WHERE id=?",[$error,$job['id']]);
                });
            }
            return one('SELECT id,kind,target_id,status,error FROM jobs WHERE id=?',[$job['id']]);
        } finally { query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '_worker'))"); }
    }
    private static function complete(array $job): void { query("UPDATE jobs SET status='completado',finished_at=NOW(),error=NULL WHERE id=?",[$job['id']]); }
    public static function process(int $id,array $u,bool $retry=false): array {
        $j=one('SELECT * FROM jobs WHERE id=?',[$id]);
        if(!$j)fail('Tarea no encontrada.',404);
        $table=$j['kind']==='document'?'documents':'analyses';
        $target=one("SELECT project_id FROM $table WHERE id=?",[$j['target_id']]);
        if(!$target)fail('El destino de la tarea ya no existe.',404);
        Auth::project((int)$target['project_id'],$u,true);
        if($retry)self::retry($id,$u);
        elseif(!in_array($j['status'],['pendiente','procesando'],true))fail('La tarea no está pendiente de procesamiento.',409);
        // The worker lock prevents concurrent processing and safely recovers interrupted jobs.
        self::runOne($j['kind']==='analysis'?(int)$j['target_id']:null,$j['kind']==='document'?(int)$j['target_id']:null);
        return one('SELECT id,kind,target_id,status,error FROM jobs WHERE id=?',[$id]);
    }
    public static function retry(int $id,array $u): void {
        transaction(function()use($id,$u){
            $j=one('SELECT * FROM jobs WHERE id=? FOR UPDATE',[$id]); if(!$j)fail('Tarea no encontrada.',404);
            $table=$j['kind']==='document'?'documents':'analyses';
            $t=one("SELECT * FROM $table WHERE id=?",[$j['target_id']]); Auth::project((int)$t['project_id'],$u,true);
            if($j['status']!=='error')fail('Solo se pueden reintentar tareas fallidas.',409);
            query("UPDATE jobs SET status='pendiente',attempts=0,error=NULL,started_at=NULL,finished_at=NULL WHERE id=?",[$id]);
            query("UPDATE $table SET status='pendiente',error=NULL WHERE id=?",[$j['target_id']]); audit('Tarea reintentada',$id);
        });
    }
}
