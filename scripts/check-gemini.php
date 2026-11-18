<?php
if(PHP_SAPI!=='cli')exit;
require dirname(__DIR__).'/app/init.php';
try{echo json_encode(ThesisGuard\Gemini::check(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
