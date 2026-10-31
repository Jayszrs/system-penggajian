<?php
// Only used by the isolated PHP test server. Apache uses .htaccess.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)??'/');
if(preg_match('~^/public/assets/[a-zA-Z0-9_./-]+\.(?:css|js|svg|woff2?)$~',$path) && !str_contains($path,'..') && is_file(dirname(__DIR__).$path))return false;
if(preg_match('~^/(?:app|config|database|docs|scripts|storage|tests|node_modules)(?:/|$)|(?:^|/)\.|\.(?:env|json|lock|md|sql|log|ps1|py|dat|exe)$~i',$path)){http_response_code(403);exit('Forbidden');}
require dirname(__DIR__).'/index.php';
