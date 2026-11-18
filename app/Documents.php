<?php
namespace ThesisGuard;
use DOMDocument;
use DOMXPath;
class Documents {
    public static function extract(string $path,string $extension): array {
        if($extension==='docx') $text=self::docx($path);
        elseif($extension==='pdf') {
            $parser=new \Smalot\PdfParser\Parser([], new \Smalot\PdfParser\Config());
            $pdf=$parser->parseFile($path);
            if(count($pdf->getPages())>300)throw new \RuntimeException('El PDF supera el límite de 300 páginas.');
            $text=$pdf->getText();
        } else throw new \RuntimeException('Formato no admitido. Usa PDF o DOCX.');
        $text=trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',mb_convert_encoding($text,'UTF-8','UTF-8')));
        if(mb_strlen($text)<80)throw new \RuntimeException('No se encontró suficiente texto. Los PDF escaneados necesitan OCR antes de cargarse.');
        if(mb_strlen($text)>250000)throw new \RuntimeException('El documento supera el límite de 250.000 caracteres extraídos.');
        return ['text'=>$text,'sections'=>self::sections($text)];
    }
    public static function docx(string $path): string {
        // PharData reads ZIP without requiring the optional php_zip extension.
        $archive=new \PharData($path);
        $size=0; $entries=0;
        foreach(new \RecursiveIteratorIterator($archive) as $entry) {
            $size+=$entry->getSize(); $entries++;
            if($size>50000000 || $entries>2000)throw new \RuntimeException('El DOCX descomprimido excede los límites de seguridad.');
        }
        if(!isset($archive['word/document.xml']))throw new \RuntimeException('El archivo no es un documento DOCX válido.');
        $xml=$archive['word/document.xml']->getContent();
        if(stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false)throw new \RuntimeException('El documento contiene entidades XML no admitidas.');
        $dom=new DOMDocument();
        $previous=libxml_use_internal_errors(true);
        $ok=$dom->loadXML($xml,LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($previous);
        if(!$ok)throw new \RuntimeException('No se pudo leer la estructura del documento Word.');
        $xp=new DOMXPath($dom); $xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $blocks=[];
        // Paragraphs, including those in nested tables, are visited in XML order once.
        foreach($xp->query('//w:body//w:p') as $paragraph) {
            $line='';
            foreach($xp->query('.//w:t|.//w:tab|.//w:br',$paragraph) as $node) $line.=$node->localName==='t'?$node->textContent:($node->localName==='tab'?"\t":"\n");
            if(trim($line)!=='')$blocks[]=trim($line);
        }
        return implode("\n",$blocks);
    }
    public static function sections(string $text): array {
        $sections=['resumen'=>'','problema'=>'','objetivos'=>'','metodologia'=>'','resultados'=>'','referencias'=>'']; $current='resumen';
        foreach(explode("\n",$text) as $line) {
            $norm=mb_strtolower(trim($line));
            if(mb_strlen($norm)<110) {
                foreach(['problema'=>'/(planteamiento|formulaci[oó]n) del problema/','objetivos'=>'/^(?:[\d.\s]+)?objetivo(?:s| general| espec[ií]ficos)?\s*[:.]?$/','metodologia'=>'/^(?:[\d.\s]+)?(?:metodolog[ií]a|diseño metodol[oó]gico)\s*[:.]?$/','resultados'=>'/^(?:[\d.\s]+)?(?:resultados|conclusiones)\s*[:.]?$/','referencias'=>'/^(?:[\d.\s]+)?(?:referencias(?: bibliogr[aá]ficas)?|bibliograf[ií]a)\s*[:.]?$/'] as $kind=>$pattern) {
                    if(preg_match($pattern,$norm)) { $current=$kind; break; }
                }
            }
            $sections[$current].=$line."\n";
        }
        return array_map('trim',$sections);
    }
    public static function upload(array $file,array $project): int {
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail('No se recibió el archivo. Revisa el límite de carga de PHP.');
        if($file['size']>config('max_upload_mb')*1024*1024)fail('El archivo supera los '.config('max_upload_mb').' MB.',413);
        $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
        enum_value($ext,['pdf','docx']);
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if(!in_array($mime,['application/pdf','application/zip','application/octet-stream','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],true))fail('El contenido no corresponde a PDF o DOCX.');
        $head=file_get_contents($file['tmp_name'],false,null,0,5);
        if(($ext==='pdf' && $head!=='%PDF-') || ($ext==='docx' && !str_starts_with($head,'PK')))fail('El formato del archivo es inválido.');
        $name=mb_substr(basename(str_replace('\\','/',$file['name'])),0,240);
        $path='documents/'.bin2hex(random_bytes(20)).'.'.$ext;
        if(!move_uploaded_file($file['tmp_name'],ROOT.'/storage/'.$path))fail('No se pudo guardar el archivo.',500);
        try {
            return transaction(function()use($project,$path,$file,$name){
                query('SELECT id FROM projects WHERE id=? FOR UPDATE',[$project['id']]);
                $version=(int)one('SELECT COALESCE(MAX(version),0)+1 n FROM documents WHERE project_id=?',[$project['id']])['n'];
                query('INSERT INTO documents(project_id,version,filename,path,sha256,size) VALUES (?,?,?,?,?,?)',[$project['id'],$version,$name,$path,hash_file('sha256',ROOT.'/storage/'.$path),$file['size']]);
                $id=(int)db()->lastInsertId();
                Jobs::enqueue('document',$id); audit('Documento cargado · versión '.$version,$id);
                return $id;
            });
        } catch(\Throwable $e) { unlink(ROOT.'/storage/'.$path); throw $e; }
    }
}
