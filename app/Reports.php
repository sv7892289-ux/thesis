<?php
namespace ThesisGuard;
class Reports {
    public static function create(int $analysisId,array $u): int {
        $a=Auth::analysis($analysisId,$u); if($a['status']!=='completado')fail('El análisis todavía no está completado.');
        $p=Auth::project((int)$a['project_id'],$u); $result=json_decode($a['result'],true);$snapshot=json_decode($a['snapshot'],true);
        $institution=setting('institution');$reviews=all('SELECT r.*,u.name reviewer FROM reviews r JOIN users u ON u.id=r.user_id WHERE analysis_id=? ORDER BY r.id',[$analysisId]);
        $html='<html><head><meta charset="UTF-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#243247;font-size:10px;line-height:1.65}h1{font-size:24px;color:#104c43}h2{font-size:14px;margin-top:24px;color:#104c43}h3{font-size:11px}p{white-space:pre-wrap}.muted{color:#64748b}blockquote{border-left:3px solid #c7dbd5;padding:10px;background:#f4f8f6}.tag{font-size:9px;color:#167765}footer{margin-top:30px;border-top:1px solid #ddd;padding-top:10px}</style></head><body>';
        $html.='<span class="tag">THESISGUARD · INFORME DE APOYO ACADÉMICO</span><h1>'.esc($institution).'</h1><h2>'.esc($p['title']).'</h2><p>'.esc($p['authors']).' · '.esc($p['career']).' · '.esc($p['year']).'</p><p class="muted">Análisis #'.$analysisId.' · Documento v'.esc($snapshot['source']['version']).' · Emitido '.date('d/m/Y H:i').' (America/La_Paz)<br>Modelo: '.esc($result['model']??$a['model']).'<br>SHA-256 del documento: '.esc($snapshot['source']['sha256']).'</p>';
        $html.='<h2>Síntesis académica</h2><p>'.esc($result['summary']).'</p>';
        foreach(['strengths'=>'Fortalezas','gaps'=>'Aspectos por revisar','recommendations'=>'Recomendaciones'] as $key=>$title){$html.='<h2>'.$title.'</h2><ul>';foreach($result[$key] as $v)$html.='<li>'.esc($v).'</li>';$html.='</ul>';}
        $html.='<h2>Antecedentes y evidencia</h2>';
        foreach($result['comparisons'] as $c)$html.='<h3>'.esc($c['title']).' · Relevancia '.esc($c['relevance']).'</h3><p>'.esc($c['explanation']).'</p><blockquote>Documento: '.esc($c['source_quote']).'<br>Antecedente: '.esc($c['candidate_quote']).'</blockquote>';
        if(!empty($result['verdict'])) {
            $verdict=$result['verdict']; $match=$result['match']??null;
            $labels=['sin_indicios'=>'Sin indicios de plagio','indicios_menores'=>'Indicios leves de plagio','indicios_relevantes'=>'Indicios relevantes de plagio'];
            $html.='<h2>Resultado integral</h2><p><strong>'.esc($labels[$verdict['result']]??$verdict['result']).' · '.esc($verdict['headline']).'</strong><br>Nivel de riesgo: '.esc($verdict['risk']).'</p><p>'.esc($verdict['assessment']).'</p>';
            if($match)$html.='<p class="muted">Índice de coincidencia verificada: '.esc($match['index']).'% (orientativo; no es un porcentaje de plagio). '.esc($match['verified']).' coincidencias verificadas de '.esc($match['examined']).' antecedentes comparados.</p>';
            if(!empty($result['dimensions'])){$html.='<h3>Dimensiones evaluadas</h3><ul>';foreach($result['dimensions'] as $d)$html.='<li><strong>'.esc($d['name']).' — '.esc($d['score']).'/5.</strong> '.esc($d['assessment']).'</li>';$html.='</ul>';}
            if(!empty($result['conclusion']))$html.='<h3>Conclusión</h3><p>'.esc($result['conclusion']).'</p>';
            if(!empty($verdict['actions'])){$html.='<h3>Acciones recomendadas</h3><ul>';foreach($verdict['actions'] as $a)$html.='<li>'.esc($a).'</li>';$html.='</ul>';}
        }
        $html.='<h2>Revisión docente</h2>';
        if(!$reviews)$html.='<p>Pendiente de revisión humana. Este informe no representa aprobación académica.</p>';
        foreach($reviews as $r)$html.='<h3>'.esc($r['reviewer']).' · '.esc($r['verdict']).' · '.esc($r['created_at']).'</h3><p>Observaciones: '.esc($r['observations']).'<br>Criterios: '.esc($r['criteria']).'<br>Recomendaciones: '.esc($r['recommendations']).'</p>';
        $html.='<h2>Alcance y limitaciones</h2><p>'.esc($snapshot['retrieval']).'<br>'.esc($snapshot['source']['sent_characters']).' de '.esc($snapshot['source']['total_characters']).' caracteres del documento enviados; '.count($snapshot['candidates']).' candidatos seleccionados de '.$snapshot['examined'].' examinados.<br>'.esc($result['limitations']).'</p><footer>La IA proporciona observaciones y un veredicto orientativo de indicios de plagio limitado al repositorio consultado. La determinación de plagio requiere revisión documental y criterio humano. No se consulta toda la web.</footer></body></html>';
        $options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('chroot',ROOT.'/storage');
        $pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
        $path='reports/'.bin2hex(random_bytes(20)).'.pdf';
        if(file_put_contents(ROOT.'/storage/'.$path,$pdf->output())===false)fail('No se pudo guardar el informe.',500);
        try {return transaction(function()use($analysisId,$u,$institution,$path){
            query('INSERT INTO reports(analysis_id,user_id,institution,path,sha256) VALUES (?,?,?,?,?)',[$analysisId,$u['id'],$institution,$path,hash_file('sha256',ROOT.'/storage/'.$path)]);
            $id=(int)db()->lastInsertId(); audit('Informe PDF emitido',$id); return $id;
        });}catch(\Throwable $e){unlink(ROOT.'/storage/'.$path);throw $e;}
    }
}
