<?php
/**
 * 프로젝트 폴더 진입점: 브라우저에서 이 폴더(예: https://example.com/Team_N_Scope/)를 열면 앱 화면을 보여준다.
 * 화면 파일은 public/에 있으며 <base href="public/">로 CSS·JS·API 경로를 public/ 기준으로 맞춘다.
 * data/, tools/, tests/ 등은 .htaccess(Apache) 또는 router.php(PHP 내장 서버)로 웹 접근을 차단한다.
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header_remove('X-Powered-By');

$html = file_get_contents(__DIR__ . '/public/index.html');
if ($html === false) {
    http_response_code(500);
    exit('index.html을 찾을 수 없습니다.');
}
echo preg_replace('/<head>/i', "<head>\n  <base href=\"public/\">", $html, 1);
