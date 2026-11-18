<?php
namespace ThesisGuard;

class Assignments {
    public static function listing(int $projectId): array {
        return all('SELECT u.id,u.name,u.email,u.active,pr.assigned_at FROM project_reviewers pr JOIN users u ON u.id=pr.reviewer_id WHERE pr.project_id=? ORDER BY u.name', [$projectId]);
    }
    public static function manager(array $p, array $u): bool {
        return $u['role']==='admin' || ($u['role']==='coordinator' && (int)$p['career_id']===(int)$u['career_id']);
    }
    public static function options(int $id,array $u): array {
        $p=Auth::project($id,$u);
        if(!self::manager($p,$u)) fail('Solo administración o coordinación de la carrera puede asignar revisores.',403);
        return ['assigned'=>self::listing($id),'available'=>all("SELECT id,name,email FROM users WHERE role='reviewer' AND active=1 AND career_id=? ORDER BY name",[$p['career_id']])];
    }
    public static function save(int $id,array $data,array $u): void {
        $p=Auth::project($id,$u);
        if(!self::manager($p,$u)) fail('Solo administración o coordinación de la carrera puede asignar revisores.',403);
        $reviewer=filter_var($data['reviewer_id']??null,FILTER_VALIDATE_INT);
        if(!$reviewer)fail('Selecciona un docente revisor.');
        $remove=($data['remove']??false)===true;
        transaction(function()use($id,$p,$u,$reviewer,$remove){
            query('SELECT id FROM projects WHERE id=? FOR UPDATE',[$id]);
            if($remove){
                query('DELETE FROM project_reviewers WHERE project_id=? AND reviewer_id=?',[$id,$reviewer]);
            } else {
                if(!one("SELECT id FROM users WHERE id=? AND role='reviewer' AND active=1 AND career_id=? FOR UPDATE",[$reviewer,$p['career_id']]))fail('El revisor debe estar activo y pertenecer a la misma carrera.');
                query('INSERT INTO project_reviewers(project_id,reviewer_id,assigned_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE assigned_by=VALUES(assigned_by),assigned_at=CURRENT_TIMESTAMP',[$id,$reviewer,$u['id']]);
            }
            audit($remove?'Revisor retirado del proyecto':'Revisor asignado al proyecto',$id,(int)$u['id']);
        });
    }
}
