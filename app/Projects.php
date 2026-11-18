<?php
namespace ThesisGuard;
class Projects {
    public static function save(array $data,array $u,?int $id=null): int {
        $old=$id?Auth::project($id,$u,true):null;
        $values=$old?array_replace($old,$data):$data;
        $title=field($values,'title',500); $authors=field($values,'authors',500);
        $abstract=field($values,'abstract',12000); $keywords=field($values,'keywords',500,false);
        $career=(int)($values['career_id']??0); $year=(int)($values['year']??date('Y'));
        if(!one('SELECT id FROM careers WHERE id=?',[$career]))fail('Selecciona una carrera válida.');
        if($u['role']!=='admin' && $career!=$u['career_id'])fail('Solo puedes registrar proyectos de tu carrera.',403);
        if($old && $career!=(int)$old['career_id'] && one('SELECT project_id FROM project_reviewers WHERE project_id=? LIMIT 1',[$id]))fail('Retira los revisores antes de cambiar la carrera del proyecto.');
        if($year<1950 || $year>2100)fail('El año debe estar entre 1950 y 2100.');
        $status=enum_value($values['status']??'registrado',['registrado','en_revision','observado','aprobado','archivado']);
        if(!in_array($u['role'],['admin','coordinator'],true) && $status!==($old['status']??'registrado'))fail('El estado académico lo establece un revisor.',403);
        $visibility=enum_value($values['visibility']??'career',['career','institution','private']);
        $modality=field($values+['modality'=>'Tesis de grado'],'modality',100);
        return transaction(function()use($id,$title,$authors,$abstract,$keywords,$career,$year,$status,$visibility,$modality,$u){
            $args=[$title,$authors,$career,$year,$modality,$status,$visibility,$abstract,$keywords];
            if($id)query('UPDATE projects SET title=?,authors=?,career_id=?,year=?,modality=?,status=?,visibility=?,abstract=?,keywords=? WHERE id=?',[...$args,$id]);
            else { query('INSERT INTO projects(title,authors,career_id,year,modality,status,visibility,abstract,keywords,owner_id) VALUES (?,?,?,?,?,?,?,?,?,?)',[...$args,$u['id']]); $id=(int)db()->lastInsertId(); }
            audit('Proyecto '.(isset($_GET['id'])?'actualizado':'guardado'),$id); return $id;
        });
    }
    public static function listing(array $u,array $filters=[]): array {
        [$scope,$args]=Auth::scope($u); $where=[$scope];
        if(!empty($filters['workspace'])){[$work,$workArgs]=Dashboard::scope($u);$where[]=$work;array_push($args,...$workArgs);}
        $q=mb_substr(trim($filters['q']??''),0,200);
        if($q!=='') { $where[]='(p.title LIKE ? OR p.authors LIKE ? OR p.keywords LIKE ? OR p.abstract LIKE ?)'; $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%'; $where[count($where)-1]=str_replace('LIKE ?','LIKE ? ESCAPE \'!\'',$where[count($where)-1]); array_push($args,$term,$term,$term,$term); }
        if(!empty($filters['status'])){$where[]='p.status=?';$args[]=$filters['status'];}
        if(!empty($filters['career_id'])){$where[]='p.career_id=?';$args[]=(int)$filters['career_id'];}
        $where=implode(' AND ',$where); $page=max(1,(int)($filters['page']??1));$offset=($page-1)*12;
        $total=(int)one("SELECT COUNT(*) n FROM projects p WHERE $where",$args)['n'];
        $items=all("SELECT p.*,c.name career,u.name owner,(SELECT COUNT(*) FROM documents d WHERE d.project_id=p.id) document_count FROM projects p JOIN careers c ON c.id=p.career_id JOIN users u ON u.id=p.owner_id WHERE $where ORDER BY p.updated_at DESC,p.id DESC LIMIT 12 OFFSET $offset",$args);
        return compact('items','total','page');
    }
    public static function snapshot(int $id,array $u): array {
        $p=Auth::project($id,$u,true);
        $d=one("SELECT * FROM documents WHERE project_id=? ORDER BY version DESC LIMIT 1",[$id]);
        if(!$d)fail('Carga y procesa un documento antes de analizarlo.');
        if($d['status']!=='completado')fail('La última versión del documento no está lista. Completa su procesamiento desde Tareas antes de analizarla.',409);
        [$scope,$args]=Auth::scope($u);
        $candidates=all("SELECT p.id project_id,p.title,d.id document_id,d.extracted_text FROM projects p JOIN documents d ON d.project_id=p.id AND d.status='completado' WHERE p.id<>? AND p.status<>'archivado' AND $scope AND d.version=(SELECT MAX(v.version) FROM documents v WHERE v.project_id=p.id AND v.status='completado') ORDER BY p.updated_at DESC LIMIT 200",[$id,...$args]);
        $source=self::excerpt($d['extracted_text'],24000);
        foreach($candidates as &$c) {
            $c['rank']=self::similarity($source,$c['extracted_text']);
            $c['text']=self::excerpt($c['extracted_text'],6000); unset($c['extracted_text']);
        } unset($c);
        usort($candidates,fn($a,$b)=>$b['rank']<=>$a['rank']);
        return ['pipeline'=>'php-lexical-gemini-v1','source'=>['project_id'=>$id,'title'=>$p['title'],'document_id'=>$d['id'],'version'=>$d['version'],'sha256'=>$d['sha256'],'text'=>$source,'total_characters'=>mb_strlen($d['extracted_text']),'sent_characters'=>mb_strlen($source)],'candidates'=>array_slice($candidates,0,6),'examined'=>count($candidates),'retrieval'=>'Coincidencia léxica local; máximo 200 documentos recientes y 6 candidatos. Extractos distribuidos en el documento.'];
    }
    public static function excerpt(string $text,int $limit): string {
        $len=mb_strlen($text); if($len<=$limit)return $text;
        $piece=(int)floor(($limit-60)/3);
        return mb_substr($text,0,$piece)."\n[… extracto intermedio …]\n".mb_substr($text,(int)(($len-$piece)/2),$piece)."\n[… extracto final …]\n".mb_substr($text,-$piece);
    }
    public static function similarity(string $a,string $b): float {
        $tokens=fn($s)=>array_count_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($s)),fn($x)=>mb_strlen($x)>4));
        $a=$tokens($a);$b=$tokens($b);$dot=0;$aa=0;$bb=0;
        foreach($a as $w=>$n){$dot+=$n*($b[$w]??0);$aa+=$n*$n;}foreach($b as $n)$bb+=$n*$n;
        return $aa&&$bb?$dot/sqrt($aa*$bb):0;
    }
}
