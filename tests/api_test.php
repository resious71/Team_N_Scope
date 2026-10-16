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
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', getenv('GCBA_TEST_LOG') ?: '/dev/null', 'a']],
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

// --- 1단계: 기본 관리자·CLI ---------------------------------------------------
echo "1단계: 저장소·CLI\n";
$admin = new Client($base);
$r = $admin->login('admin', 'admin');
check('기본 관리자(admin/admin) 자동 생성·로그인, 비밀번호 변경 강제 없음',
    $r['status'] === 200 && $r['data']['user']['mustChangePassword'] === false,
    json_encode($r['json'], JSON_UNESCAPED_UNICODE));
$out = shell_exec(PHP_BINARY . ' ' . escapeshellarg("{$root}/tools/admin_cli.php") . ' create-admin admin2 --generate 2>&1');
preg_match('/임시 비밀번호: (\S+)/u', (string)$out, $m);
check('CLI로 관리자 추가 생성', isset($m[1]), (string)$out);
$admin2Temp = $m[1] ?? '';
$tempAdmin = new Client($base);
check('CLI로 만든 관리자 로그인', $tempAdmin->login('admin2', $admin2Temp)['status'] === 200);
$r = $tempAdmin->call('teams');
check('임시 비밀번호 상태에서 다른 기능 차단(403)', $r['status'] === 403 && ($r['json']['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED');
$r = $tempAdmin->call('password_change', ['currentPassword' => $admin2Temp, 'newPassword' => 'short']);
check('비밀번호 정책 위반 거부(400)', $r['status'] === 400);
$r = $tempAdmin->call('password_change', ['currentPassword' => $admin2Temp, 'newPassword' => 'Admin2345']);
check('비밀번호 변경', $r['status'] === 200);
$admin->call('password_change', ['currentPassword' => 'admin', 'newPassword' => 'Admin1234']);
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
check('부서 생성 + 첫 팔레트 색 배정', ($r['data']['department']['color'] ?? '') === '#8FD6C2');
$r2 = $admin->call('department_create', ['teamId' => $teamId, 'name' => '기획']);
check('두 번째 부서는 다음 색', ($r2['data']['department']['color'] ?? '') === '#FFC49B');
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

// 관리자가 비밀번호 지정
$r = $admin->call('person_create', ['teamId' => $teamId, 'name' => '비번지정', 'departmentId' => $deptId, 'loginId' => 'pw.set', 'password' => 'short']);
check('지정 비밀번호도 정책 검사 (400)', $r['status'] === 400);
check('로그인 ID 없이 비밀번호만 지정 시 400', $admin->call('person_create', ['teamId' => $teamId, 'name' => '비번지정', 'departmentId' => $deptId, 'password' => 'Given1234'])['status'] === 400);
$r = $admin->call('person_create', ['teamId' => $teamId, 'name' => '비번지정', 'departmentId' => $deptId, 'loginId' => 'pw.set', 'password' => 'Given1234']);
check('비밀번호를 지정해 계정 발급 (임시 비밀번호 없음)', $r['status'] === 200 && $r['data']['tempPassword'] === null);
$pwPerson = $r['data']['person']['id'];
$pwClient = new Client($base);
$r = $pwClient->login('pw.set', 'Given1234');
check('지정한 비밀번호로 로그인, 변경 강제 없음', $r['status'] === 200 && $r['data']['user']['mustChangePassword'] === false);
check('부서원 목록에서 비밀번호 변경', $admin->call('account_set_password', ['teamId' => $teamId, 'personId' => $pwPerson, 'password' => 'Changed99'])['status'] === 200);
check('바뀐 비밀번호로 로그인', (new Client($base))->login('pw.set', 'Changed99')['status'] === 200);
check('부서원은 비밀번호 지정 API 403', $pwClient->call('account_set_password', ['teamId' => $teamId, 'personId' => $pwPerson, 'password' => 'Hacked999'])['status'] === 403);
$noAcc = $admin->call('person_create', ['teamId' => $teamId, 'name' => '계정없음', 'departmentId' => $deptId])['data']['person']['id'];
check('계정 없는 사람은 로그인 ID 없이 비밀번호 지정 시 400', $admin->call('account_set_password', ['teamId' => $teamId, 'personId' => $noAcc, 'password' => 'NewPass11'])['status'] === 400);
$r = $admin->call('account_set_password', ['teamId' => $teamId, 'personId' => $noAcc, 'password' => 'NewPass11', 'loginId' => 'no.acc', 'mustChangePassword' => true]);
check('계정 없는 사람에게 계정 만들며 비밀번호 지정', $r['status'] === 200 && $r['data']['created'] === true);
check('변경 요구 옵션 적용', (new Client($base))->login('no.acc', 'NewPass11')['data']['user']['mustChangePassword'] === true);
$admin->call('person_delete', ['teamId' => $teamId, 'personIds' => [$pwPerson, $noAcc]]);

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
// 부서: 개발(p1, p2, p6) / 기획(p3, p4, p5)
$p6 = $admin->call('person_create', ['teamId' => $teamId, 'name' => '한소희', 'departmentId' => $deptId])['data']['person']['id'];
$rid = fn($x, $y) => strcmp($x, $y) < 0 ? "{$x}_{$y}" : "{$y}_{$x}";
check('잘못된 상태 400', $member->call('relationship_upsert', ['otherId' => $p2, 'status' => 'love'])['status'] === 400);
check('자기 자신 400', $member->call('relationship_upsert', ['otherId' => $p1, 'status' => 'private'])['status'] === 400);
$r = $member->call('relationship_upsert', ['otherId' => $p2, 'status' => 'private', 'a' => $p3]);
check('부서원 관계 추가 (본인은 세션으로 결정)', $r['status'] === 200 && $r['data']['created'] === true && $r['data']['id'] === $rid($p1, $p2));
check('부서원이 다른 부서 사람과 관계 추가 시 403', $member->call('relationship_upsert', ['otherId' => $p3, 'status' => 'private'])['status'] === 403);
$r = $member->call('relationship_upsert', ['otherId' => $p2, 'status' => 'private']);
check('기존 관계는 수정(upsert)', $r['data']['created'] === false);
// 관리자는 부서와 무관하게 입력 가능
$admin->call('relationship_upsert', ['teamId' => $teamId, 'a' => $p1, 'b' => $p3, 'status' => 'social']);
$admin->call('relationship_upsert', ['teamId' => $teamId, 'a' => $p1, 'b' => $p4, 'status' => 'public']);
$admin->call('relationship_upsert', ['teamId' => $teamId, 'a' => $p3, 'b' => $p4, 'status' => 'social']);
$admin->call('relationship_upsert', ['teamId' => $teamId, 'a' => $p4, 'b' => $p5, 'status' => 'private']);
$admin->call('relationship_upsert', ['teamId' => $teamId, 'a' => $p2, 'b' => $p6, 'status' => 'public']);

$r = $member->call('team_data');
$rels = $r['data']['relationships'];
check('부서원에게는 자기 부서 사람·부서만', count($r['data']['people']) === 3 && count($r['data']['departments']) === 1);
check('own 범위: 자기 부서 안의 본인 관계만', count($rels) === 1 && $rels[0]['id'] === $rid($p1, $p2));
check('부서원 응답에 타인 점수 없음', !isset($r['data']['people'][0]['score']));
check('부서원 응답에 입력자 정보 없음', !isset($rels[0]['createdBy']));
check('부서원에게는 본인 평가가 보임', $rels[0]['status'] === 'private' && $rels[0]['mine'] === 'private');
check('내 점수는 다른 부서 관계도 반영 (+1 -1 +0 = 0)', $r['data']['me']['score'] === 0);

$jieun = new Client($base);
$jieun->login('jieun', $jieunTemp);
$jieun->call('password_change', ['currentPassword' => $jieunTemp, 'newPassword' => 'Jieun1234']);
$jieun->call('consent', []);
check('보이지 않는 관계 삭제 403', $jieun->call('relationship_delete', ['ids' => [$rid($p3, $p4)]])['status'] === 403);
$p1p2 = $rid($p1, $p2);
$r = $jieun->call('relationship_upsert', ['otherId' => $p1, 'status' => 'social']);
check('상대방 평가는 따로 저장 (상대방 기준 새 평가)', $r['data']['created'] === true && $r['data']['id'] === $p1p2 && $r['data']['effective'] === 'social');
$r = $member->call('team_data');
check('점수는 쌍의 낮은 값 반영: 김민수 -2', $r['data']['me']['score'] === -2);
check('상대방 평가는 부서원에게 보이지 않음 (본인 평가 유지)', count($r['data']['relationships']) === 1 && $r['data']['relationships'][0]['status'] === 'private');
$r = $jieun->call('team_data');
check('다른 사람만 평가한 관계는 본인이 포함돼도 안 보임', count($r['data']['relationships']) === 1
    && $r['data']['relationships'][0]['id'] === $p1p2 && $r['data']['relationships'][0]['status'] === 'social');
$ad = array_values(array_filter($admin->call('team_data', null, ['teamId' => $teamId])['data']['relationships'], fn($x) => $x['id'] === $p1p2))[0];
$evP1 = strcmp($p1, $p2) < 0 ? $ad['evalA'] : $ad['evalB'];
$evP2 = strcmp($p1, $p2) < 0 ? $ad['evalB'] : $ad['evalA'];
check('관리자는 각자의 평가와 낮은 값을 봄', $ad['status'] === 'social' && $evP1 === 'private' && $evP2 === 'social' && $ad['evalMgr'] === null);

// 점수 (관리자)
$scores = [];
foreach ($admin->call('team_data', null, ['teamId' => $teamId])['data']['people'] as $p) {
    $scores[$p['id']] = $p['score'];
}
// p1: p2(-1) p3(-1) p4(0) = -2 / p2: p1(-1) p6(0) = -1 / p3: p1(-1) p4(-1) = -2 / p4: 0 -1 +1 = 0 / p5: +1 / p6: 0
check('관리자 점수 계산이 2.6 규칙과 일치',
    [$scores[$p1], $scores[$p2], $scores[$p3], $scores[$p4], $scores[$p5], $scores[$p6]] === [-2, -1, -2, 0, 1, 0], json_encode($scores));

// 이력·추이
$h = $admin->call('relationship_history', null, ['teamId' => $teamId, 'relId' => $p1p2])['data']['entries'];
check('관계 변경 이력 (평가자별, 유효 상태 변화)', count($h) === 2 && $h[0]['from'] === 'private' && $h[0]['to'] === 'social'
    && str_contains((string)$h[0]['slotLabel'], '이지은') && $h[0]['slotTo'] === 'social');
$trend = $admin->call('score_trend', null, ['teamId' => $teamId])['data'];
$s1 = array_values(array_filter($trend['series'], fn($x) => $x['personId'] === $p1))[0]['scores'];
check('점수 추이: 12개월, 이번 달 값 = 현재 점수', count($trend['months']) === 12 && end($s1) === -2);
check('부서원은 이력 API 403', $member->call('relationship_history', null, ['teamId' => $teamId, 'relId' => $p1p2])['status'] === 403);

// revision
$rev1 = $member->call('revision')['data']['revision'];
$jieun->call('relationship_delete', ['ids' => [$p1p2]]);
$rev2 = $member->call('revision')['data']['revision'];
check('변경 시 revision 증가', $rev2 > $rev1);
$h = $admin->call('relationship_history', null, ['teamId' => $teamId, 'relId' => $p1p2])['data']['entries'];
check('당사자 삭제는 본인 평가만, 이력에 기록', $h[0]['slotFrom'] === 'social' && $h[0]['slotTo'] === null && $h[0]['to'] === 'private');
check('상대방이 지워도 내 평가는 남음', count($member->call('team_data')['data']['relationships']) === 1);

// 비활성화 → 기존 세션 401
$admin->call('account_set_active', ['teamId' => $teamId, 'personId' => $p2, 'active' => false]);
check('비활성화한 계정의 기존 세션은 다음 요청에서 401', $jieun->call('team_data')['status'] === 401);
check('비활성 계정 로그인 불가', $jieun->call('login', ['loginId' => 'jieun', 'password' => 'Jieun1234'])['status'] === 403);

// 직급: 팀장(p3, 기획) / 부서장(p4, 기획)
echo "직급 권한\n";
check('잘못된 직급 400', $admin->call('person_update', ['teamId' => $teamId, 'personId' => $p3, 'position' => 'boss'])['status'] === 400);
$admin->call('person_update', ['teamId' => $teamId, 'personId' => $p3, 'position' => 'leader']);
$admin->call('person_update', ['teamId' => $teamId, 'personId' => $p4, 'position' => 'head']);
check('팀장은 팀당 1명 (409)', $admin->call('person_update', ['teamId' => $teamId, 'personId' => $p5, 'position' => 'leader'])['status'] === 409);
check('부서장은 부서당 1명 (409)', $admin->call('person_update', ['teamId' => $teamId, 'personId' => $p5, 'position' => 'head'])['status'] === 409);
check('다른 부서에는 부서장 지정 가능', $admin->call('person_update', ['teamId' => $teamId, 'personId' => $p6, 'position' => 'head'])['status'] === 200);
check('부서장을 이미 부서장이 있는 부서로 이동 시 409', $admin->call('person_update', ['teamId' => $teamId, 'personId' => $p6, 'departmentId' => $dept2])['status'] === 409);
$mkUser = function (string $pid, string $login) use ($admin, $teamId, $base): Client {
    $tmp = $admin->call('account_create', ['teamId' => $teamId, 'personId' => $pid, 'loginId' => $login])['data']['tempPassword'];
    $c = new Client($base);
    $c->login($login, $tmp);
    $c->call('password_change', ['currentPassword' => $tmp, 'newPassword' => 'Pass12345']);
    $c->call('consent', []);
    return $c;
};
$leader = $mkUser($p3, 'leader1');
$head = $mkUser($p4, 'head1');
$ownCount = fn(string $pid) => count(array_filter($admin->call('team_data', null, ['teamId' => $teamId])['data']['relationships'],
    fn($x) => ($x['a'] === $pid && $x['evalA'] !== null) || ($x['b'] === $pid && $x['evalB'] !== null)));
$r = $leader->call('team_data');
check('팀장은 팀 전체 사람·점수와 본인 평가 관계만 받음', count($r['data']['relationships']) === $ownCount($p3) && $r['data']['me']['position'] === 'leader'
    && count($r['data']['people']) === 6 && isset($r['data']['people'][0]['score'])
    && !array_filter($r['data']['relationships'], fn($x) => ($x['a'] !== $p3 && $x['b'] !== $p3) || array_key_exists('evalMgr', $x)));
check('팀장이 다른 두 사람 관계 추가 시 403', $leader->call('relationship_upsert', ['a' => $p1, 'b' => $p2, 'status' => 'public'])['status'] === 403);
check('팀장이 다른 두 사람 관계 삭제 시 403', $leader->call('relationship_delete', ['ids' => [$p1p2]])['status'] === 403);
check('팀장이 본인↔타 부서원 관계 추가', $leader->call('relationship_upsert', ['otherId' => $p1, 'status' => 'private'])['status'] === 200);
$p1p3 = $p1 < $p3 ? "{$p1}_{$p3}" : "{$p3}_{$p1}";
$admin->call('relationship_upsert', ['teamId' => $teamId, 'a' => $p1, 'b' => $p3, 'status' => 'social']);
$lrel = array_values(array_filter($leader->call('team_data')['data']['relationships'], fn($x) => $x['id'] === $p1p3))[0] ?? null;
$arel = array_values(array_filter($admin->call('team_data', null, ['teamId' => $teamId])['data']['relationships'], fn($x) => $x['id'] === $p1p3))[0];
check('팀장은 관리자 평가가 있어도 본인 평가만 봄 (관리자는 낮은 값)', $lrel && $lrel['status'] === 'private' && $arel['status'] === 'social');
check('상대 부서원에게는 팀장의 평가가 보이지 않음', !array_filter($member->call('team_data')['data']['relationships'], fn($x) => $x['id'] === $p1p3));
check('부서장이 본인↔자기 부서원 관계 추가', $head->call('relationship_upsert', ['a' => $p4, 'b' => $p5, 'status' => 'private'])['status'] === 200);
check('부서장이 자기 부서원끼리 관계 추가 시 403', $head->call('relationship_upsert', ['a' => $p5, 'b' => $p3, 'status' => 'private'])['status'] === 403);
check('부서장이 타 부서원끼리 관계 추가 시 403', $head->call('relationship_upsert', ['a' => $p1, 'b' => $p2, 'status' => 'social'])['status'] === 403);
check('부서장이 본인↔타 부서원 관계 추가 시 403', $head->call('relationship_upsert', ['otherId' => $p1, 'status' => 'social'])['status'] === 403);
$r = $head->call('team_data');
check('부서장은 자기 부서 사람·점수와 본인 평가 관계만 받음', count($r['data']['people']) === 3 && isset($r['data']['people'][0]['score'])
    && count($r['data']['relationships']) === $ownCount($p4)
    && !array_filter($r['data']['relationships'], fn($x) => $x['a'] !== $p4 && $x['b'] !== $p4));
check('일반 부서원이 남의 관계 추가 시 403', $member->call('relationship_upsert', ['a' => $p2, 'b' => $p6, 'status' => 'social'])['status'] === 403);
check('관리자가 관계 삭제', $admin->call('relationship_delete', ['teamId' => $teamId, 'ids' => [$p1p2, $p1p3]])['status'] === 200);
check('당사자가 아닌 삭제는 쌍의 평가 전체 삭제', !array_filter($admin->call('team_data', null, ['teamId' => $teamId])['data']['relationships'], fn($x) => in_array($x['id'], [$p1p2, $p1p3], true))
    && count($member->call('team_data')['data']['relationships']) === 0);
$exp = $admin->call('team_export', null, ['teamId' => $teamId])['data'];
check('내보내기에 직급 포함', in_array('leader', array_column($exp['people'], 'position'), true));

// 관리자 계정 관리 (웹)
echo "관리자 계정\n";
$list = $admin->call('admin_list')['data']['admins'];
check('관리자 목록', count($list) === 2);
$admin2Id = array_values(array_filter($list, fn($x) => $x['loginId'] === 'admin2'))[0]['id'];
$selfId = array_values(array_filter($list, fn($x) => $x['isSelf']))[0]['id'];
$r = $admin->call('admin_create', ['loginId' => 'admin3']);
check('관리자 추가 (임시 비밀번호)', $r['status'] === 200 && strlen($r['data']['tempPassword']) >= 8);
check('본인 비활성화 불가', $admin->call('admin_set_active', ['userId' => $selfId, 'active' => false])['status'] === 400);
check('다른 관리자 비활성화', $admin->call('admin_set_active', ['userId' => $admin2Id, 'active' => false])['status'] === 200);
check('비활성화된 관리자 세션 401', $tempAdmin->call('teams')['status'] === 401);
check('부서원은 관리자 계정 API 403', $member->call('admin_list')['status'] === 403);

// --- 5단계: 내보내기/가져오기·로그 -------------------------------------------
echo "5단계: 내보내기/가져오기·로그\n";
$export = $admin->call('team_export', null, ['teamId' => $teamId])['data'];
check('내보내기', count($export['people']) === 6 && !isset($export['relationships'][0]['createdBy']));
check('같은 이름으로 가져오기 409', $admin->call('team_import', ['data' => $export])['status'] === 409);
$r = $admin->call('team_import', ['data' => $export, 'name' => '개발1팀 복사본']);
check('새 팀으로 가져오기', $r['status'] === 200);
$imported = $admin->call('team_data', null, ['teamId' => $r['data']['team']['id']])['data'];
check('가져오기 시 ID 재발급·계정 없음', $imported['people'][0]['id'] !== $export['people'][0]['id'] && $imported['people'][0]['account'] === null);
$evalCount = fn($rels) => array_sum(array_map(fn($x) => ($x['evalA'] !== null) + ($x['evalB'] !== null) + ($x['evalMgr'] !== null), $rels));
$origRels = $admin->call('team_data', null, ['teamId' => $teamId])['data']['relationships'];
check('내보내기·가져오기에서 평가자별 평가 보존', $evalCount($imported['relationships']) === $evalCount($origRels)
    && array_count_values(array_column($imported['relationships'], 'status')) == array_count_values(array_column($origRels, 'status')));
$legacy = $export;
foreach ($legacy['relationships'] as &$lr) { unset($lr['evals']); }
unset($lr);
$r = $admin->call('team_import', ['data' => $legacy, 'name' => '예전형식팀']);
$lt = $admin->call('team_data', null, ['teamId' => $r['data']['team']['id']])['data']['relationships'];
check('예전 형식(평가 1개) 파일은 관리자 평가로 가져옴', $r['status'] === 200 && $lt && !array_filter($lt, fn($x) => $x['evalMgr'] === null || $x['evalA'] !== null || $x['evalB'] !== null));
$broken = $export;
$broken['relationships'][] = ['a' => $export['people'][0]['id'], 'b' => 'p-missing', 'status' => 'private'];
check('참조 무결성 위반 거부', $admin->call('team_import', ['data' => $broken, 'name' => '깨진팀'])['status'] === 400);
$dup = $export;
$dup['relationships'][] = ['a' => $export['relationships'][0]['b'], 'b' => $export['relationships'][0]['a'], 'status' => 'social'];
check('중복 쌍 거부', $admin->call('team_import', ['data' => $dup, 'name' => '중복팀'])['status'] === 400);
check('실패한 가져오기는 저장되지 않음', count($admin->call('teams')['data']['teams']) === 4);
// 예전 색상표 색(#52C0A1)으로 만든 파일도 새 파스텔 색으로 바꿔 가져온다
$big = ['schemaVersion' => 1, 'departments' => [['id' => 'd1', 'name' => 'A', 'color' => '#52C0A1']], 'people' => [], 'relationships' => []];
for ($i = 0; $i < 35; $i++) {
    $big['people'][] = ['id' => "p{$i}", 'name' => "사람{$i}", 'departmentId' => 'd1'];
}
check('30명 초과 파일은 maxMembers 없으면 거부', $admin->call('team_import', ['data' => $big, 'name' => '대형팀'])['status'] === 409);
$r = $admin->call('team_import', ['data' => $big, 'name' => '대형팀', 'maxMembers' => 40]);
check('maxMembers 지정 시 가져오기', $r['status'] === 200);
check('예전 색상은 새 파스텔 색으로 변환', $admin->call('team_data', null, ['teamId' => $r['data']['team']['id']])['data']['departments'][0]['color'] === '#8FD6C2');
check('색상표 16색', count($admin->call('me')['data']['palette']) === 16);

$log = $admin->call('audit_log')['data']['entries'];
$actions = array_column($log, 'action');
check('활동 로그에 관계 변경 기록', in_array('relationship_delete', $actions, true) && in_array('relationship_update', $actions, true));

// 일괄 등록
$r = $admin->call('person_bulk_create', ['teamId' => $teamId, 'rows' => [
    ['name' => '새사람1', 'department' => '디자인', 'position' => '부서장', 'loginId' => 'new.one'],
    ['name' => '새사람2', 'department' => '개발', 'position' => '', 'loginId' => ''],
]]);
check('일괄 등록 (새 부서 자동 생성, 계정 발급)', $r['status'] === 200 && $r['data']['created'] === 2 && count($r['data']['accounts']) === 1
    && $r['data']['departmentsCreated'] === ['디자인'], json_encode($r['json'], JSON_UNESCAPED_UNICODE));
$before = count($admin->call('team_data', null, ['teamId' => $teamId])['data']['people']);
$r = $admin->call('person_bulk_create', ['teamId' => $teamId, 'rows' => [
    ['name' => '새사람3', 'department' => '개발'],
    ['name' => '새사람1', 'department' => '개발'],
]]);
check('일괄 등록 오류는 행 번호와 함께 전부 거부', $r['status'] === 409 && str_starts_with($r['json']['error'], '2행')
    && count($admin->call('team_data', null, ['teamId' => $teamId])['data']['people']) === $before);

// 활동 로그 검색
$r = $admin->call('audit_log', null, ['category' => 'relationship', 'pageSize' => 10]);
check('활동 로그 분류 필터·페이지', $r['status'] === 200 && $r['data']['total'] > 10 && count($r['data']['entries']) === 10
    && !array_filter($r['data']['entries'], fn($e) => !str_starts_with($e['action'], 'relationship_')));
$r = $admin->call('audit_log', null, ['q' => '새사람1']);
check('활동 로그 검색어', $r['data']['total'] >= 1);

// 부서원 삭제 → 관계·계정 삭제
$admin->call('person_delete', ['teamId' => $teamId, 'personIds' => [$p1]]);
check('삭제된 부서원의 세션 401', $member->call('team_data')['status'] === 401);
$after = $admin->call('team_data', null, ['teamId' => $teamId])['data'];
check('삭제된 부서원의 관계 제거', count(array_filter($after['relationships'], fn($x) => $x['a'] === $p1 || $x['b'] === $p1)) === 0);
$hist = (string)@file_get_contents("{$dataDir}/history/{$teamId}.jsonl");
check('삭제된 부서원의 관계 이력 제거', $hist !== '' && !str_contains($hist, $p1));

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
check('관리자가 있으면 기본 관리자를 다시 만들지 않음', count(array_filter(json_decode(file_get_contents("{$dataDir}/users.json"), true)['users'], fn($u) => $u['loginId'] === 'admin')) === 1);
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
