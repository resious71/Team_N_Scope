<?php
/**
 * PHP 내장 서버용 라우터 (개발용): php -S localhost:8000 router.php
 * 내장 서버는 .htaccess를 읽지 않으므로 여기서 data/·tools/ 등의 접근을 차단한다.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#(^|/)(data|tools|tests|backups|docs|\.git)(/|$)|(^|/)\.|/(auth|storage)\.php$|^/(router\.php|README\.md)$#', $path)) {
    http_response_code(404);
    exit;
}
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}
return false;
