<?php
/**
 * 마이그레이션 도구 테스트: v1.0/1.1 형식의 data 폴더를 만들어 tools/migrate.php로 변환하고 검사한다.
 *
 *   php tests/migrate_test.php
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/gcba-migrate-test-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
register_shutdown_function(fn() => exec('rm -rf ' . escapeshellarg($tmp)));

$passed = 0;
$failed = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ✔ {$name}\n";
    } else {
        $failed++;
        echo "  ✘ {$name}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

function write(string $path, array $data): void
{
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function read(string $path): array
{
    return json_decode((string)file_get_contents($path), true);
}

function migrate(string $dataDir, string $args = ''): array
{
    global $root, $tmp;
    $cmd = 'GCBA_DATA_DIR=' . escapeshellarg($dataDir) . ' GCBA_BACKUP_DIR=' . escapeshellarg("{$tmp}/backups")
        . ' ' . PHP_BINARY . ' ' . escapeshellarg("{$root}/tools/migrate.php") . " {$args} 2>&1";
    exec($cmd, $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
}

/** v1.0/1.1 형식의 예전 데이터 */
function make_legacy(string $dir): void
{
    $hash = password_hash('OldPass123', PASSWORD_DEFAULT);
    write("{$dir}/users.json", ['schemaVersion' => 1, 'users' => [
        // 1.0.0 초기 형식처럼 일부 필드가 빠진 계정
        ['id' => 'u-admin', 'loginId' => 'admin', 'passwordHash' => $hash, 'role' => 'admin'],
        ['id' => 'u-m1', 'loginId' => 'Old.User', 'passwordHash' => $hash, 'role' => 'member', 'teamId' => 't-old', 'personId' => 'p-1', 'active' => true],
        ['id' => 'u-ghost', 'loginId' => 'ghost', 'passwordHash' => $hash, 'role' => 'member', 'teamId' => 't-old', 'personId' => 'p-gone', 'active' => true],
    ]]);
    write("{$dir}/teams.json", ['schemaVersion' => 1, 'teams' => [
        ['id' => 't-old', 'name' => '예전팀', 'memberViewScope' => 'team'],   // maxMembers·날짜 없음
    ]]);
    write("{$dir}/teams/t-old.json", [
        'schemaVersion' => 1, 'teamId' => 't-old', 'revision' => 7,
        'departments' => [
            ['id' => 'd-1', 'name' => '개발', 'color' => '#52C0A1'],   // 예전 10색
            ['id' => 'd-2', 'name' => '기획', 'color' => '#FF9A56'],
        ],
        'people' => [
            ['id' => 'p-1', 'name' => '김예전', 'departmentId' => 'd-1'],          // 직급 없음
            ['id' => 'p-2', 'name' => '이예전', 'departmentId' => 'd-1', 'position' => 'leader'],
            ['id' => 'p-3', 'name' => '박예전', 'departmentId' => 'd-2', 'position' => 'leader'], // 팀장 중복
        ],
        'relationships' => [
            // 당사자(p-1의 계정)가 마지막으로 수정 → p-1의 평가
            ['id' => 'p-1_p-2', 'a' => 'p-1', 'b' => 'p-2', 'status' => 'private', 'createdBy' => 'u-m1', 'updatedBy' => 'u-m1', 'updatedAt' => '2026-09-01T10:00:00+09:00'],
            // 관리자가 입력 → 관리자 평가
            ['id' => 'p-2_p-3', 'a' => 'p-2', 'b' => 'p-3', 'status' => 'social', 'createdBy' => 'u-admin', 'updatedBy' => 'u-admin', 'updatedAt' => '2026-09-02T10:00:00+09:00'],
            // 없는 부서원을 가리키는 관계 → 제거
            ['id' => 'p-1_p-x', 'a' => 'p-1', 'b' => 'p-x', 'status' => 'public', 'createdBy' => 'u-admin', 'updatedBy' => 'u-admin'],
        ],
    ]);
}

// --- 1) 제자리 마이그레이션 -------------------------------------------------
echo "제자리 마이그레이션\n";
$data = "{$tmp}/data";
make_legacy($data);
$beforeHash = md5_file("{$data}/teams/t-old.json") . md5_file("{$data}/users.json");

$r = migrate($data, '--dry-run');
check('미리 보기 성공', $r['code'] === 0, $r['out']);
check('미리 보기는 파일을 바꾸지 않음', md5_file("{$data}/teams/t-old.json") . md5_file("{$data}/users.json") === $beforeHash);
check('미리 보기 보고에 관계 변환 수', str_contains($r['out'], '관계 변환: 2개 → 당사자 평가 1개, 관리자 평가 1개'), $r['out']);

$r = migrate($data);
check('마이그레이션 성공', $r['code'] === 0, $r['out']);
check('마이그레이션 전 자동 백업', count(glob("{$tmp}/backups/gcba-data-premigrate-*.tar.gz")) === 1);

$users = array_column(read("{$data}/users.json")['users'], null, 'id');
check('계정 필드 보완 (활성·변경 요구·동의·생성일)', $users['u-admin']['active'] === true && $users['u-admin']['mustChangePassword'] === false
    && array_key_exists('consentedAt', $users['u-admin']) && isset($users['u-admin']['createdAt']));
check('로그인 ID 소문자화', $users['u-m1']['loginId'] === 'old.user');
check('비밀번호 해시 유지', password_verify('OldPass123', $users['u-m1']['passwordHash']));
check('팀 설정 보완 (인원 한도)', read("{$data}/teams.json")['teams'][0]['maxMembers'] === 30);

$team = read("{$data}/teams/t-old.json");
$colors = array_column($team['departments'], 'color');
check('부서 색상을 파스텔 16색으로 재배정 (겹치지 않게)', $colors === ['#8FD6C2', '#FFC49B'], json_encode($colors));
$pos = array_column($team['people'], 'position', 'id');
check('직급 기본값 부서원, 중복 팀장 정리', $pos === ['p-1' => 'member', 'p-2' => 'leader', 'p-3' => 'member'], json_encode($pos));
$rels = array_column($team['relationships'], null, 'id');
check('없는 부서원을 가리키는 관계 제거', !isset($rels['p-1_p-x']) && count($rels) === 2);
check('당사자가 수정한 관계 → 당사자 평가', array_keys($rels['p-1_p-2']['evals']) === ['p-1'] && $rels['p-1_p-2']['status'] === 'private');
check('관리자가 입력한 관계 → 관리자 평가', array_keys($rels['p-2_p-3']['evals']) === ['mgr'] && $rels['p-2_p-3']['status'] === 'social');
check('revision 증가 (열려 있는 화면 갱신)', $team['revision'] === 8);
check('고아 계정 보고', str_contains($r['out'], 'ghost'), $r['out']);
check('마이그레이션 기록', count(read("{$data}/migrations.json")['runs']) === 1);

$r = migrate($data);
$team2 = read("{$data}/teams/t-old.json");
check('다시 실행해도 결과 같음 (멱등)', $r['code'] === 0 && str_contains($r['out'], '관계 변환: 0개')
    && array_column($team2['relationships'], 'evals', 'id') === array_column($team['relationships'], 'evals', 'id'), $r['out']);

// --- 2) --from: 예전 서버 데이터·백업 파일 가져오기 -------------------------
echo "--from 가져오기\n";
$old = "{$tmp}/old-server/data";
make_legacy($old);
$new = "{$tmp}/new-data";
$r = migrate($new, '--from ' . escapeshellarg($old));
check('예전 data 폴더를 빈 새 폴더로 가져와 변환', $r['code'] === 0 && isset(read("{$new}/teams/t-old.json")['relationships'][0]['evals']), $r['out']);
$r = migrate($new, '--from ' . escapeshellarg($old));
check('대상에 데이터가 있으면 --force 없이 거부', $r['code'] !== 0 && str_contains($r['out'], '--force'));

// 예전 백업 파일 (tools/backup.php 형식: data/... 가 든 tar.gz)
$tarPath = "{$tmp}/old-backup.tar";
$tar = new PharData($tarPath);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($old, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $tar->addFile($f->getPathname(), 'data/' . substr($f->getPathname(), strlen($old) + 1));
}
$tar->compress(Phar::GZ);
unset($tar);
$fromArchive = "{$tmp}/from-archive";
$r = migrate($fromArchive, '--from ' . escapeshellarg("{$tarPath}.gz") . ' --dry-run');
check('백업 파일 미리 보기는 대상에 아무것도 쓰지 않음', $r['code'] === 0 && !file_exists("{$fromArchive}/users.json"), $r['out']);
$r = migrate($fromArchive, '--from ' . escapeshellarg("{$tarPath}.gz"));
$t = read("{$fromArchive}/teams/t-old.json");
check('백업 파일(.tar.gz)에서 가져와 변환', $r['code'] === 0 && count($t['relationships']) === 2 && isset($t['relationships'][0]['evals']), $r['out']);
$r = migrate($new, '--from ' . escapeshellarg($old) . ' --force');
check('--force로 덮어쓰기 (덮어쓰기 전 백업)', $r['code'] === 0 && str_contains($r['out'], '현재 데이터 백업'), $r['out']);

// --- 3) 마이그레이션한 데이터로 실제 로그인 ---------------------------------
echo "변환한 데이터로 로그인\n";
$port = 19000 + random_int(0, 999);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$root}/public"],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, null, array_merge(getenv(), ['GCBA_DATA_DIR' => $data]));
register_shutdown_function(fn() => proc_terminate($proc));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
    usleep(100000);
}
$jar = tempnam($tmp, 'jar');
$post = function (string $action, array $body, string $csrf = '') use ($port, $jar) {
    $ch = curl_init("http://127.0.0.1:{$port}/api/index.php?action={$action}");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body), CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-CSRF-Token: {$csrf}"], CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
    $res = json_decode((string)curl_exec($ch), true);
    return $res;
};
$login = $post('login', ['loginId' => 'OLD.USER', 'password' => 'OldPass123']);
check('예전 계정·비밀번호로 로그인', ($login['ok'] ?? false) === true, json_encode($login, JSON_UNESCAPED_UNICODE));
check('개정된 개인정보 안내에 재동의 요구', ($login['data']['user']['needsConsent'] ?? null) === true);
$csrf = $login['data']['csrfToken'] ?? '';
$post('consent', [], $csrf);
$ch = curl_init("http://127.0.0.1:{$port}/api/index.php?action=team_data");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar]);
$td = json_decode((string)curl_exec($ch), true);
check('부서원은 본인이 등록한 평가만 봄 (변환된 관계)', ($td['ok'] ?? false) && count($td['data']['relationships']) === 1
    && $td['data']['relationships'][0]['id'] === 'p-1_p-2', json_encode($td, JSON_UNESCAPED_UNICODE));

echo "\n결과: {$passed} 통과, {$failed} 실패\n";
exit($failed > 0 ? 1 : 0);
