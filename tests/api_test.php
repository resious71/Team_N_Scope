<?php
/**
 * API 통합 테스트: 임시 data 폴더로 PHP 내장 서버를 띄우고 수용 기준(12장)을 검사한다.
 *
 *   php tests/api_test.php
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$dataDir = sys_get_temp_dir() . '/gcba-test-' . bin2hex(random_bytes(4));
$port = 18000 + random_int(0, 999);
$base = "http://127.0.0.1:{$port}/api/index.php";

putenv("GCBA_DATA_DIR={$dataDir}");
$env = array_merge(getenv(), ['GCBA_DATA_DIR' => $dataDir]);
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$root}/public"],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, null, $env
);
register_shutdown_function(function () use ($proc, $dataDir) {
    proc_terminate($proc);
    exec('rm -rf ' . escapeshellarg($dataDir));
});
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

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

final class Client
{
    public string $csrf = '';
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'gcba-jar');
    }

    public function call(string $action, ?array $body = null, array $params = [], bool $withCsrf = true): array
    {
        $url = $this->base . '?' . http_build_query(['action' => $action] + $params);
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            if ($withCsrf) {
                $headers[] = 'X-CSRF-Token: ' . $this->csrf;
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode((string)$raw, true) ?? ['raw' => $raw];
        if (isset($json['data']['csrfToken'])) {
            $this->csrf = $json['data']['csrfToken'];
        }
        return ['status' => $status, 'json' => $json, 'data' => $json['data'] ?? null];
    }

    public function login(string $id, string $pw): array
    {
        $r = $this->call('login', ['loginId' => $id, 'password' => $pw]);
        if ($r['status'] === 200) {
            $this->call('me');
        }
        return $r;
    }
}

// --- 1단계: CLI로 관리자 생성 ------------------------------------------------
echo "1단계: 저장소·CLI\n";
$out = shell_exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/tools/admin_cli.php") . ' create-admin admin --generate 2>&1');
preg_match('/임시 비밀번호: (\S+)/u', (string)$out, $m);
check('CLI로 관리자 생성', isset($m[1]), (string)$out);
$adminTemp = $m[1] ?? '';

$admin = new Client($base);
$r = $admin->login('admin', $adminTemp);
check('관리자 로그인', $r['status'] === 200, json_encode($r['json'], JSON_UNESCAPED_UNICODE));
$r = $admin->call('teams');
check('임시 비밀번호 상태에서 다른 기능 차단(403)', $r['status'] === 403 && ($r['json']['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED');
$r = $admin->call('password_change', ['currentPassword' => $adminTemp, 'newPassword' => 'short']);
check('비밀번호 정책 위반 거부(400)', $r['status'] === 400);
$r = $admin->call('password_change', ['currentPassword' => $adminTemp, 'newPassword' => 'Admin1234']);
check('비밀번호 변경', $r['status'] === 200);
$r = $admin->call('teams');
check('미동의 상태에서 다른 기능 차단(403)', $r['status'] === 403 && ($r['json']['code'] ?? '') === 'CONSENT_REQUIRED');
$admin->call('consent', []);
$r = $admin->call('teams');
check('동의 후 팀 목록 조회', $r['status'] === 200 && $r['data']['teams'] === []);
$r = $admin->call('team_create', ['name' => 'X'], [], false);
check('CSRF 토큰 없으면 403', $r['status'] === 403);

// --- 3단계: 관리자 흐름 ------------------------------------------------------
echo "3단계: 관리자 흐름\n";
$r = $admin->call('team_create', ['name' => '개발1팀']);
$teamId = $r['data']['team']['id'] ?? '';
check('팀 생성', $r['status'] === 200 && $teamId !== '');
check('팀 이름 중복 409', $admin->call('team_create', ['name' => '개발1팀'])['status'] === 409);
$r = $admin->call('department_create', ['teamId' => $teamId, 'name' => '개발']);
$deptId = $r['data']['department']['id'] ?? '';
check('부서 생성 + 첫 팔레트 색 배정', ($r['data']['department']['color'] ?? '') === '#52C0A1');
$r2 = $admin->call('department_create', ['teamId' => $teamId, 'name' => '기획']);
check('두 번째 부서는 다음 색', ($r2['data']['department']['color'] ?? '') === '#FF9A56');
$dept2 = $r2['data']['department']['id'];

$r = $admin->call('person_create', ['teamId' => $teamId, 'name' => '김민수', 'departmentId' => $deptId, 'loginId' => 'minsu.kim']);
$p1 = $r['data']['person']['id'] ?? '';
$memberTemp = $r['data']['tempPassword'] ?? '';
check('부서원+계정 발급(임시 비밀번호 반환)', $p1 !== '' && strlen($memberTemp) >= 8);
$r = $admin->call('person_create', ['teamId' => $teamId, 'name' => '이지은', 'departmentId' => $deptId, 'loginId' => 'jieun']);
$p2 = $r['data']['person']['id'];
$jieunTemp = $r['data']['tempPassword'];
$p3 = $admin->call('person_create', ['teamId' => $teamId, 'name' => '박서준', 'departmentId' => $dept2])['data']['person']['id'];
$p4 = $admin->call('person_create', ['teamId' => $teamId, 'name' => '최유진', 'departmentId' => $dept2])['data']['person']['id'];
check('같은 이름 부서원 409', $admin->call('person_create', ['teamId' => $teamId, 'name' => '김민수', 'departmentId' => $deptId])['status'] === 409);
check('로그인 ID 중복 409', $admin->call('person_create', ['teamId' => $teamId, 'name' => '새사람', 'departmentId' => $deptId, 'loginId' => 'MINSU.KIM'])['status'] === 409);
check('소속 인원이 있는 부서 삭제 409', $admin->call('department_delete', ['teamId' => $teamId, 'departmentIds' => [$deptId]])['status'] === 409);

// 인원 한도
check('현재 인원보다 낮은 한도 409', $admin->call('team_update', ['teamId' => $teamId, 'maxMembers' => 3])['status'] === 409);
check('한도를 4로 변경', $admin->call('team_update', ['teamId' => $teamId, 'maxMembers' => 4])['status'] === 200);
check('한도 도달 시 부서원 추가 409', $admin->call('person_create', ['teamId' => $teamId, 'name' => '추가', 'departmentId' => $deptId])['status'] === 409);
check('한도 도달 시 샘플 생성 409', $admin->call('team_seed', ['teamId' => $teamId])['status'] === 409);
$admin->call('team_update', ['teamId' => $teamId, 'maxMembers' => 30]);
$r = $admin->call('person_create', ['teamId' => $teamId, 'name' => '정하늘', 'departmentId' => $dept2]);
check('한도를 올리면 다시 추가 가능', $r['status'] === 200);
$p5 = $r['data']['person']['id'];
check('시스템 상한 초과 400', $admin->call('team_update', ['teamId' => $teamId, 'maxMembers' => 101])['status'] === 400);

// --- 2단계: 부서원 인증·권한 -------------------------------------------------
echo "2단계: 부서원 인증·권한\n";
$member = new Client($base);
$r = $member->login('minsu.kim', $memberTemp);
check('부서원 로그인', $r['status'] === 200);
$member->call('password_change', ['currentPassword' => $memberTemp, 'newPassword' => 'Minsu1234']);
$member->call('consent', []);
check('부서원이 관리자 API 호출 시 403', $member->call('teams')['status'] === 403);
check('부서원이 부서원 추가 시 403', $member->call('person_create', ['teamId' => $teamId, 'name' => 'x', 'departmentId' => $deptId])['status'] === 403);

$other = $admin->call('team_create', ['name' => '타팀', 'seed' => true]);
$otherTeam = $other['data']['team']['id'];
$r = $member->call('team_data', null, ['teamId' => $otherTeam]);
check('부서원이 타 팀 teamId를 보내도 본인 팀 데이터만', $r['status'] === 200 && $r['data']['team']['id'] === $teamId);
$otherData = $admin->call('team_data', null, ['teamId' => $otherTeam])['data'];
check('샘플 데이터 20명 생성', count($otherData['people']) === 20);
$foreign = $otherData['people'][0]['id'];
check('타 팀 사람과 관계 생성 404', $member->call('relationship_upsert', ['otherId' => $foreign, 'status' => 'private'])['status'] === 404);

// 로그인 실패 제한 (로그인 ID + IP)
$bad = new Client($base);
$codes = [];
for ($i = 0; $i < 5; $i++) {
    $codes[] = $bad->call('login', ['loginId' => 'jieun', 'password' => 'wrong-pass1'])['status'];
}
check('5회 실패 후 잠금(423)', end($codes) === 423, implode(',', $codes));
check('잠금 중에는 올바른 비밀번호도 423', $bad->call('login', ['loginId' => 'jieun', 'password' => $jieunTemp])['status'] === 423);
check('없는 ID와 틀린 비밀번호의 메시지가 동일', $bad->call('login', ['loginId' => 'nobody', 'password' => 'x'])['json']['error'] === 'ID 또는 비밀번호가 올바르지 않습니다.');
$adminTeam = $admin->call('team_data', null, ['teamId' => $teamId])['data'];
$jieunAcc = array_values(array_filter($adminTeam['people'], fn($p) => $p['id'] === $p2))[0]['account'];
check('관리자 화면에 잠김 표시', $jieunAcc['locked'] === true);
$admin->call('account_unlock', ['teamId' => $teamId, 'personId' => $p2]);
check('관리자 잠금 해제 후 로그인', $bad->login('jieun', $jieunTemp)['status'] === 200);

// --- 4단계: 관계 -------------------------------------------------------------
echo "4단계: 관계\n";
check('잘못된 상태 400', $member->call('relationship_upsert', ['otherId' => $p2, 'status' => 'love'])['status'] === 400);
check('자기 자신 400', $member->call('relationship_upsert', ['otherId' => $p1, 'status' => 'private'])['status'] === 400);
$r = $member->call('relationship_upsert', ['otherId' => $p2, 'status' => 'private', 'a' => $p3]);
check('부서원 관계 추가 (본인은 세션으로 결정)', $r['status'] === 200 && $r['data']['created'] === true && $r['data']['id'] === (strcmp($p1, $p2) < 0 ? "{$p1}_{$p2}" : "{$p2}_{$p1}"));
$member->call('relationship_upsert', ['otherId' => $p3, 'status' => 'social']);
$member->call('relationship_upsert', ['otherId' => $p4, 'status' => 'public']);
$r = $member->call('relationship_upsert', ['otherId' => $p3, 'status' => 'private']);
check('기존 관계는 수정(upsert)', $r['data']['created'] === false);
$admin->call('relationship_upsert', ['teamId' => $teamId, 'a' => $p3, 'b' => $p4, 'status' => 'social']);
$admin->call('relationship_upsert', ['teamId' => $teamId, 'a' => $p4, 'b' => $p5, 'status' => 'private']);

$r = $member->call('team_data');
$rels = $r['data']['relationships'];
$involves = array_filter($rels, fn($x) => $x['a'] === $p1 || $x['b'] === $p1);
check("own 범위: 타인 관계가 응답에 없음", count($rels) === 3 && count($involves) === 3);
check('부서원 응답에 타인 점수 없음', !isset($r['data']['people'][0]['score']));
check('부서원 응답에 입력자 정보 없음', !isset($rels[0]['createdBy']));
check('내 점수 = +1 +1 +0 = 2', $r['data']['me']['score'] === 2);

$jieun = new Client($base);
$jieun->login('jieun', $jieunTemp);
$jieun->call('password_change', ['currentPassword' => $jieunTemp, 'newPassword' => 'Jieun1234']);
$jieun->call('consent', []);
$p3p4 = strcmp($p3, $p4) < 0 ? "{$p3}_{$p4}" : "{$p4}_{$p3}";
check('타인 관계 삭제 403', $jieun->call('relationship_delete', ['ids' => [$p3p4]])['status'] === 403);
$p1p2 = strcmp($p1, $p2) < 0 ? "{$p1}_{$p2}" : "{$p2}_{$p1}";
$r = $jieun->call('relationship_upsert', ['otherId' => $p1, 'status' => 'social']);
check('상대방도 같은 관계 수정 가능', $r['data']['created'] === false && $r['data']['id'] === $p1p2);
check('수정 후 김민수 점수 0', $member->call('team_data')['data']['me']['score'] === 0);

$admin->call('team_update', ['teamId' => $teamId, 'memberViewScope' => 'team']);
$r = $member->call('team_data');
check('team 범위: 팀 전체 관계', count($r['data']['relationships']) === 5);

// 점수 (관리자)
$scores = [];
foreach ($admin->call('team_data', null, ['teamId' => $teamId])['data']['people'] as $p) {
    $scores[$p['id']] = $p['score'];
}
// p1: p2 social(-1), p3 private(+1), p4 public(0) = 0 / p2: -1 / p3: +1 -1 = 0 / p4: 0 -1 +1 = 0 / p5: +1
check('관리자 점수 계산이 2.6 규칙과 일치', $scores[$p1] === 0 && $scores[$p2] === -1 && $scores[$p3] === 0 && $scores[$p4] === 0 && $scores[$p5] === 1, json_encode($scores));

// revision
$rev1 = $member->call('revision')['data']['revision'];
$jieun->call('relationship_delete', ['ids' => [$p1p2]]);
$rev2 = $member->call('revision')['data']['revision'];
check('변경 시 revision 증가', $rev2 > $rev1);

// 비활성화 → 기존 세션 401
$admin->call('account_set_active', ['teamId' => $teamId, 'personId' => $p2, 'active' => false]);
check('비활성화한 계정의 기존 세션은 다음 요청에서 401', $jieun->call('team_data')['status'] === 401);
check('비활성 계정 로그인 불가', $jieun->call('login', ['loginId' => 'jieun', 'password' => 'Jieun1234'])['status'] === 403);

// --- 5단계: 내보내기/가져오기·로그 -------------------------------------------
echo "5단계: 내보내기/가져오기·로그\n";
$export = $admin->call('team_export', null, ['teamId' => $teamId])['data'];
check('내보내기', count($export['people']) === 5 && !isset($export['relationships'][0]['createdBy']));
check('같은 이름으로 가져오기 409', $admin->call('team_import', ['data' => $export])['status'] === 409);
$r = $admin->call('team_import', ['data' => $export, 'name' => '개발1팀 복사본']);
check('새 팀으로 가져오기', $r['status'] === 200);
$imported = $admin->call('team_data', null, ['teamId' => $r['data']['team']['id']])['data'];
check('가져오기 시 ID 재발급·계정 없음', $imported['people'][0]['id'] !== $export['people'][0]['id'] && $imported['people'][0]['account'] === null);
$broken = $export;
$broken['relationships'][] = ['a' => $export['people'][0]['id'], 'b' => 'p-missing', 'status' => 'private'];
check('참조 무결성 위반 거부', $admin->call('team_import', ['data' => $broken, 'name' => '깨진팀'])['status'] === 400);
$dup = $export;
$dup['relationships'][] = ['a' => $export['relationships'][0]['b'], 'b' => $export['relationships'][0]['a'], 'status' => 'social'];
check('중복 쌍 거부', $admin->call('team_import', ['data' => $dup, 'name' => '중복팀'])['status'] === 400);
check('실패한 가져오기는 저장되지 않음', count($admin->call('teams')['data']['teams']) === 3);
$big = ['schemaVersion' => 1, 'departments' => [['id' => 'd1', 'name' => 'A', 'color' => '#52C0A1']], 'people' => [], 'relationships' => []];
for ($i = 0; $i < 35; $i++) {
    $big['people'][] = ['id' => "p{$i}", 'name' => "사람{$i}", 'departmentId' => 'd1'];
}
check('30명 초과 파일은 maxMembers 없으면 거부', $admin->call('team_import', ['data' => $big, 'name' => '대형팀'])['status'] === 409);
check('maxMembers 지정 시 가져오기', $admin->call('team_import', ['data' => $big, 'name' => '대형팀', 'maxMembers' => 40])['status'] === 200);

$log = $admin->call('audit_log')['data']['entries'];
$actions = array_column($log, 'action');
check('활동 로그에 관계 변경 기록', in_array('relationship_delete', $actions, true) && in_array('relationship_update', $actions, true));

// 부서원 삭제 → 관계·계정 삭제
$admin->call('person_delete', ['teamId' => $teamId, 'personIds' => [$p1]]);
check('삭제된 부서원의 세션 401', $member->call('team_data')['status'] === 401);
$after = $admin->call('team_data', null, ['teamId' => $teamId])['data'];
check('삭제된 부서원의 관계 제거', count(array_filter($after['relationships'], fn($x) => $x['a'] === $p1 || $x['b'] === $p1)) === 0);

// 팀 삭제 → trash 이동, 계정 삭제
check('확인 이름 불일치 시 삭제 거부', $admin->call('team_delete', ['teamId' => $teamId, 'confirmName' => '틀림'])['status'] === 400);
check('팀 삭제', $admin->call('team_delete', ['teamId' => $teamId, 'confirmName' => '개발1팀'])['status'] === 200);
check('삭제된 팀 파일이 trash로 이동', count(glob("{$dataDir}/trash/{$teamId}_*.json")) === 1 && !is_file("{$dataDir}/teams/{$teamId}.json"));
$users = json_decode(file_get_contents("{$dataDir}/users.json"), true)['users'];
check('삭제된 팀의 계정 제거', count(array_filter($users, fn($u) => $u['teamId'] === $teamId)) === 0);
$trash = json_decode(file_get_contents(glob("{$dataDir}/trash/{$teamId}_*.json")[0]), true);
check('trash 파일로 복구(가져오기)', $admin->call('team_import', ['data' => $trash, 'name' => '복구팀'])['status'] === 200);

// 동시 쓰기: 여러 요청을 병렬로 보내도 JSON이 깨지지 않음
echo "동시성\n";
$t = $admin->call('team_create', ['name' => '동시성팀', 'seed' => true])['data']['team']['id'];
$people = array_column($admin->call('team_data', null, ['teamId' => $t])['data']['people'], 'id');
$mh = curl_multi_init();
$handles = [];
$jarFile = tempnam(sys_get_temp_dir(), 'jar');
$admin2 = new Client($base);
$admin2->login('admin', 'Admin1234');
$ref = new ReflectionProperty(Client::class, 'jar');
$jar = $ref->getValue($admin2);
for ($i = 0; $i < 40; $i++) {
    $a = $people[$i % 20];
    $b = $people[($i * 7 + 3) % 20];
    if ($a === $b) {
        continue;
    }
    $ch = curl_init("{$base}?action=relationship_upsert");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['teamId' => $t, 'a' => $a, 'b' => $b, 'status' => ['private', 'public', 'social'][$i % 3]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $admin2->csrf],
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$okCount = 0;
foreach ($handles as $ch) {
    $okCount += curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 ? 1 : 0;
}
$decoded = json_decode((string)file_get_contents("{$dataDir}/teams/{$t}.json"), true);
check('병렬 요청 모두 성공', $okCount === count($handles), "{$okCount}/" . count($handles));
check('병렬 쓰기 후 JSON 정상', is_array($decoded) && count(glob("{$dataDir}/teams/*.tmp.*")) === 0);
$ids = array_column($decoded['relationships'], 'id');
check('관계 ID 중복 없음', count($ids) === count(array_unique($ids)));

// 백업
echo "백업\n";
$backupDir = "{$dataDir}-backups";
$out = shell_exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/tools/backup.php") . ' ' . escapeshellarg($backupDir) . ' 2>&1');
$archives = glob("{$backupDir}/gcba-data-*.tar.gz");
check('백업 생성', count($archives) === 1, (string)$out);
if ($archives) {
    $restore = "{$backupDir}/restore";
    mkdir($restore);
    exec('tar xzf ' . escapeshellarg($archives[0]) . ' -C ' . escapeshellarg($restore), $o, $code);
    check('백업 압축 해제(복구) 가능', $code === 0 && is_file("{$restore}/data/users.json") && is_file("{$restore}/data/teams/{$t}.json"));
}
exec('rm -rf ' . escapeshellarg($backupDir));

echo "\n결과: {$passed} 통과, {$failed} 실패\n";
exit($failed > 0 ? 1 : 0);
