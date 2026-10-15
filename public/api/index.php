<?php
/**
 * API 단일 진입점: ?action=... 으로 분기하고 모든 요청에서 세션 → 역할 → 팀 범위를 서버가 검사한다.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
header_remove('X-Powered-By');

/** action => [메서드, 권한(public|login|admin), 처리 함수] */
const ROUTES = [
    'login'                  => ['POST', 'public', 'act_login'],
    'logout'                 => ['POST', 'login', 'act_logout'],
    'me'                     => ['GET', 'login', 'act_me'],
    'password_change'        => ['POST', 'login', 'act_password_change'],
    'consent'                => ['POST', 'login', 'act_consent'],
    'teams'                  => ['GET', 'admin', 'act_teams'],
    'team_create'            => ['POST', 'admin', 'act_team_create'],
    'team_update'            => ['POST', 'admin', 'act_team_update'],
    'team_delete'            => ['POST', 'admin', 'act_team_delete'],
    'team_select'            => ['POST', 'admin', 'act_team_select'],
    'team_data'              => ['GET', 'login', 'act_team_data'],
    'revision'               => ['GET', 'login', 'act_revision'],
    'department_create'      => ['POST', 'admin', 'act_department_create'],
    'department_update'      => ['POST', 'admin', 'act_department_update'],
    'department_delete'      => ['POST', 'admin', 'act_department_delete'],
    'person_create'          => ['POST', 'admin', 'act_person_create'],
    'person_update'          => ['POST', 'admin', 'act_person_update'],
    'person_delete'          => ['POST', 'admin', 'act_person_delete'],
    'account_create'         => ['POST', 'admin', 'act_account_create'],
    'account_reset_password' => ['POST', 'admin', 'act_account_reset_password'],
    'account_set_active'     => ['POST', 'admin', 'act_account_set_active'],
    'account_unlock'         => ['POST', 'admin', 'act_account_unlock'],
    'relationship_upsert'    => ['POST', 'login', 'act_relationship_upsert'],
    'relationship_delete'    => ['POST', 'login', 'act_relationship_delete'],
    'team_seed'              => ['POST', 'admin', 'act_team_seed'],
    'team_export'            => ['GET', 'admin', 'act_team_export'],
    'team_import'            => ['POST', 'admin', 'act_team_import'],
    'audit_log'              => ['GET', 'admin', 'act_audit_log'],
];

/** 임시 비밀번호 변경·개인정보 동의 전에도 허용되는 동작 */
const GATE_EXEMPT = ['logout', 'me', 'password_change', 'consent'];

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_body(string $action): array
{
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') {
        throw new ApiError(400, 'Content-Type은 application/json이어야 합니다.');
    }
    $limit = $action === 'team_import' ? IMPORT_MAX_BYTES : REQUEST_MAX_BYTES;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        throw new ApiError(400, '요청 본문이 너무 큽니다.');
    }
    $raw = file_get_contents('php://input', false, null, 0, $limit + 1);
    if ($raw === false || strlen($raw) > $limit) {
        throw new ApiError(400, '요청 본문이 너무 큽니다.');
    }
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true, 64);
    if (!is_array($data)) {
        throw new ApiError(400, '요청 본문이 올바른 JSON이 아닙니다.');
    }
    return $data;
}

// ---------------------------------------------------------------------------
// 디스패치
// ---------------------------------------------------------------------------
try {
    $action = $_GET['action'] ?? '';
    if (!is_string($action) || !isset(ROUTES[$action])) {
        throw new ApiError(404, '알 수 없는 요청입니다.');
    }
    [$method, $access, $handler] = ROUTES[$action];
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        throw new ApiError(400, "이 요청은 {$method} 메서드만 허용합니다.");
    }
    ensure_data_dirs();
    $input = $method === 'POST' ? read_body($action) : $_GET;

    if ($access === 'public') {
        respond(200, ['ok' => true, 'data' => $handler($input)]);
    }

    $ctx = require_session();
    if ($method === 'POST') {
        check_csrf($ctx['csrf']);
    }
    $user = $ctx['user'];
    if (!in_array($action, GATE_EXEMPT, true)) {
        if (!empty($user['mustChangePassword'])) {
            throw new ApiError(403, '비밀번호를 먼저 변경해야 합니다.', 'PASSWORD_CHANGE_REQUIRED');
        }
        if (needs_consent($user)) {
            throw new ApiError(403, '개인정보 안내에 동의해야 이용할 수 있습니다.', 'CONSENT_REQUIRED');
        }
    }
    if ($access === 'admin' && $user['role'] !== 'admin') {
        throw new ApiError(403, '권한이 없습니다.');
    }
    $result = $handler($input, $ctx);
    respond(200, ['ok' => true, 'data' => $result]);
} catch (ApiError $e) {
    $body = ['ok' => false, 'error' => $e->getMessage()];
    if ($e->errorCode !== null) {
        $body['code'] = $e->errorCode;
    }
    respond($e->status, $body);
} catch (Throwable $e) {
    error_log('[gcba] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(500, ['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
}

// ---------------------------------------------------------------------------
// 공통 헬퍼
// ---------------------------------------------------------------------------
function is_admin(array $ctx): bool
{
    return $ctx['user']['role'] === 'admin';
}

/** 부서원은 요청의 teamId와 무관하게 항상 본인 소속 팀. 관리자는 teamId 필수. */
function resolve_team_id(array $ctx, array $input): string
{
    if (!is_admin($ctx)) {
        return (string)$ctx['user']['teamId'];
    }
    $teamId = assert_id($input['teamId'] ?? null, '팀 ID');
    if (find_team_meta($teamId) === null) {
        throw new ApiError(404, '팀을 찾을 수 없습니다.');
    }
    return $teamId;
}

function require_team(string $teamId): array
{
    $team = load_team($teamId);
    if ($team === null) {
        throw new ApiError(404, '팀 데이터를 찾을 수 없습니다.');
    }
    return sanitize_team($team);
}

function user_brief(array $user): array
{
    $name = null;
    if ($user['role'] === 'member' && $user['teamId']) {
        $team = load_team($user['teamId']);
        $i = $team ? find_index($team['people'], (string)$user['personId']) : -1;
        $name = $i >= 0 ? $team['people'][$i]['name'] : null;
    }
    return [
        'id' => $user['id'],
        'loginId' => $user['loginId'],
        'role' => $user['role'],
        'teamId' => $user['teamId'] ?? null,
        'personId' => $user['personId'] ?? null,
        'name' => $name ?? $user['loginId'],
        'mustChangePassword' => !empty($user['mustChangePassword']),
        'needsConsent' => needs_consent($user),
    ];
}

function person_name(array $team, string $personId): string
{
    $i = find_index($team['people'], $personId);
    return $i >= 0 ? $team['people'][$i]['name'] : $personId;
}

function bool_input(array $input, string $key): bool
{
    $v = $input[$key] ?? null;
    if (!is_bool($v)) {
        throw new ApiError(400, "{$key} 값은 true/false여야 합니다.");
    }
    return $v;
}

function id_list(array $input, string $key, string $label): array
{
    $ids = $input[$key] ?? null;
    if (!is_array($ids) || $ids === [] || count($ids) > 1000) {
        throw new ApiError(400, $label . '을(를) 선택하세요.');
    }
    return array_values(array_unique(array_map(fn($id) => assert_id($id, $label), $ids)));
}

function max_members_input(mixed $v): int
{
    if (!is_int($v) || $v < 1 || $v > SYSTEM_MAX_MEMBERS) {
        throw new ApiError(400, '인원 한도는 1~' . SYSTEM_MAX_MEMBERS . ' 사이의 정수여야 합니다.');
    }
    return $v;
}

function team_name_taken(string $name, ?string $exceptId = null): bool
{
    foreach (load_teams()['teams'] as $t) {
        if ($t['id'] !== $exceptId && same_name($t['name'], $name)) {
            return true;
        }
    }
    return false;
}

/** 부서원 계정을 users.json에 추가한다 (with_lock 안에서 호출). 임시 비밀번호를 반환. */
function add_member_account(string $teamId, string $personId, string $loginId): array
{
    $doc = load_users();
    if (find_user_by_login($doc, $loginId) !== null) {
        throw new ApiError(409, '이미 사용 중인 로그인 ID입니다.');
    }
    foreach ($doc['users'] as $u) {
        if (($u['personId'] ?? null) === $personId && ($u['teamId'] ?? null) === $teamId) {
            throw new ApiError(409, '이미 계정이 있는 부서원입니다.');
        }
    }
    $temp = generate_temp_password();
    $user = [
        'id' => new_id('u'),
        'loginId' => $loginId,
        'passwordHash' => hash_password($temp),
        'role' => 'member',
        'teamId' => $teamId,
        'personId' => $personId,
        'active' => true,
        'mustChangePassword' => true,
        'consentedAt' => null,
        'createdAt' => now_iso(),
    ];
    $doc['users'][] = $user;
    save_users($doc);
    return ['user' => $user, 'tempPassword' => $temp];
}

/** 팀 $teamId의 부서원 $personId 계정을 찾는다. */
function member_account(string $teamId, string $personId): array
{
    foreach (load_users()['users'] as $u) {
        if ($u['role'] === 'member' && $u['teamId'] === $teamId && $u['personId'] === $personId) {
            return $u;
        }
    }
    throw new ApiError(404, '계정이 없는 부서원입니다.');
}

function remove_users_and_state(callable $predicate): array
{
    $doc = load_users();
    $removed = [];
    $doc['users'] = array_values(array_filter($doc['users'], function ($u) use ($predicate, &$removed) {
        if ($predicate($u)) {
            $removed[] = $u;
            return false;
        }
        return true;
    }));
    if ($removed) {
        save_users($doc);
        $state = load_user_state();
        foreach ($removed as $u) {
            unset($state['states'][$u['id']]);
            foreach (array_keys($state['loginFailures']) as $k) {
                if (str_starts_with($k, strtolower($u['loginId']) . '|')) {
                    unset($state['loginFailures'][$k]);
                }
            }
        }
        save_user_state($state);
    }
    return $removed;
}

function unlock_login(string $loginId): void
{
    with_lock(function () use ($loginId) {
        $state = load_user_state();
        foreach (array_keys($state['loginFailures']) as $k) {
            if (str_starts_with($k, strtolower($loginId) . '|')) {
                unset($state['loginFailures'][$k]);
            }
        }
        save_user_state($state);
    });
}

function locked_login_ids(): array
{
    $out = [];
    foreach (load_user_state()['loginFailures'] as $k => $f) {
        if (!empty($f['lockedUntil']) && strtotime($f['lockedUntil']) > time()) {
            $out[explode('|', $k)[0]] = true;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// 인증
// ---------------------------------------------------------------------------
function act_login(array $input): array
{
    $r = login($input);
    return ['user' => user_brief($r['user']), 'csrfToken' => $r['csrf']];
}

function act_logout(array $input, array $ctx): array
{
    audit($ctx['user']['id'], 'logout', $ctx['user']['teamId'] ?? null, $ctx['user']['loginId']);
    destroy_session();
    return [];
}

function act_me(array $input, array $ctx): array
{
    $user = $ctx['user'];
    $out = [
        'user' => user_brief($user),
        'csrfToken' => $ctx['csrf'],
        'privacyNotice' => ['version' => PRIVACY_NOTICE_VERSION, 'paragraphs' => PRIVACY_NOTICE],
        'palette' => PALETTE,
        'limits' => ['defaultMaxMembers' => DEFAULT_MAX_MEMBERS, 'systemMaxMembers' => SYSTEM_MAX_MEMBERS],
    ];
    if ($user['role'] === 'member') {
        $meta = find_team_meta((string)$user['teamId']);
        $out['team'] = $meta ? ['id' => $meta['id'], 'name' => $meta['name']] : null;
    } else {
        $state = load_user_state()['states'][$user['id']] ?? [];
        $out['lastTeamId'] = $state['lastTeamId'] ?? null;
    }
    return $out;
}

function act_password_change(array $input, array $ctx): array
{
    change_password($ctx['user'], $input);
    return [];
}

function act_consent(array $input, array $ctx): array
{
    record_consent($ctx['user']);
    return [];
}

// ---------------------------------------------------------------------------
// 팀
// ---------------------------------------------------------------------------
function act_teams(array $input, array $ctx): array
{
    $teams = [];
    foreach (load_teams()['teams'] as $t) {
        $doc = load_team($t['id']);
        if ($doc === null) {
            error_log("[gcba] team file missing: {$t['id']}");
            continue;
        }
        $t['memberCount'] = count($doc['people']);
        $teams[] = $t;
    }
    $state = load_user_state()['states'][$ctx['user']['id']] ?? [];
    return ['teams' => $teams, 'lastTeamId' => $state['lastTeamId'] ?? null];
}

function act_team_create(array $input, array $ctx): array
{
    $name = clean_name($input['name'] ?? null, '팀 이름', 30);
    $seed = !empty($input['seed']);
    $uid = $ctx['user']['id'];
    $meta = with_lock(function () use ($name, $seed, $uid) {
        if (team_name_taken($name)) {
            throw new ApiError(409, '같은 이름의 팀이 이미 있습니다.');
        }
        $now = now_iso();
        $meta = [
            'id' => new_id('t'),
            'name' => $name,
            'memberViewScope' => 'own',
            'maxMembers' => DEFAULT_MAX_MEMBERS,
            'createdAt' => $now,
            'updatedAt' => $now,
        ];
        $team = new_team_document($meta['id']);
        if ($seed) {
            seed_team($team, $meta['maxMembers'], $uid);
        }
        // 순서: 팀 파일 작성 → teams.json 갱신
        save_team($team);
        $doc = load_teams();
        $doc['teams'][] = $meta;
        save_teams($doc);
        return $meta;
    });
    audit($uid, 'team_create', $meta['id'], $meta['name'], $seed ? '샘플 데이터 포함' : '');
    return ['team' => $meta];
}

function act_team_update(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $name = array_key_exists('name', $input) ? clean_name($input['name'], '팀 이름', 30) : null;
    $scope = $input['memberViewScope'] ?? null;
    if ($scope !== null && !in_array($scope, ['own', 'team'], true)) {
        throw new ApiError(400, '공개 범위는 own 또는 team이어야 합니다.');
    }
    $max = array_key_exists('maxMembers', $input) ? max_members_input($input['maxMembers']) : null;

    $meta = with_lock(function () use ($teamId, $name, $scope, $max) {
        $team = require_team($teamId);
        if ($max !== null && $max < count($team['people'])) {
            throw new ApiError(409, '인원 한도는 현재 부서원 수(' . count($team['people']) . '명)보다 낮게 설정할 수 없습니다.');
        }
        if ($name !== null && team_name_taken($name, $teamId)) {
            throw new ApiError(409, '같은 이름의 팀이 이미 있습니다.');
        }
        $doc = load_teams();
        foreach ($doc['teams'] as &$t) {
            if ($t['id'] === $teamId) {
                if ($name !== null) {
                    $t['name'] = $name;
                }
                if ($scope !== null) {
                    $t['memberViewScope'] = $scope;
                }
                if ($max !== null) {
                    $t['maxMembers'] = $max;
                }
                $t['updatedAt'] = now_iso();
                $updated = $t;
            }
        }
        unset($t);
        save_teams($doc);
        // 공개 범위 변경이 부서원 화면에 반영되도록 revision 증가
        mutate_team($teamId, fn() => null);
        return $updated;
    });
    audit($ctx['user']['id'], 'team_update', $teamId, $meta['name'],
        "공개 범위 {$meta['memberViewScope']}, 한도 {$meta['maxMembers']}");
    return ['team' => $meta];
}

function act_team_delete(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $confirm = is_string($input['confirmName'] ?? null) ? trim($input['confirmName']) : '';
    $result = with_lock(function () use ($teamId, $confirm) {
        $meta = find_team_meta($teamId);
        if ($meta === null) {
            throw new ApiError(404, '팀을 찾을 수 없습니다.');
        }
        if ($confirm !== $meta['name']) {
            throw new ApiError(400, '확인용 팀 이름이 일치하지 않습니다.');
        }
        // 순서: teams.json·users.json에서 제거 → 팀 파일을 trash/로 이동
        $doc = load_teams();
        $doc['teams'] = array_values(array_filter($doc['teams'], fn($t) => $t['id'] !== $teamId));
        save_teams($doc);
        $removed = remove_users_and_state(fn($u) => ($u['teamId'] ?? null) === $teamId);
        $state = load_user_state();
        foreach ($state['states'] as $uid => $s) {
            if (($s['lastTeamId'] ?? null) === $teamId) {
                $state['states'][$uid]['lastTeamId'] = null;
            }
        }
        save_user_state($state);
        $src = team_path($teamId);
        if (is_file($src)) {
            $dest = data_path('trash/' . $teamId . '_' . date('Ymd-His') . '.json');
            if (!@rename($src, $dest)) {
                error_log("[gcba] failed to move team file to trash: {$teamId}");
            }
        }
        return ['name' => $meta['name'], 'accounts' => count($removed)];
    });
    audit($ctx['user']['id'], 'team_delete', $teamId, $result['name'], "계정 {$result['accounts']}개 삭제");
    return $result;
}

function act_team_select(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    update_user_state($ctx['user']['id'], function ($s) use ($teamId) {
        $s['lastTeamId'] = $teamId;
        return $s;
    });
    return [];
}

/** 역할에 맞게 필터링된 팀 데이터. 부서원에게는 타인 점수·입력자 정보를 내려보내지 않는다. */
function act_team_data(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $meta = find_team_meta($teamId);
    if ($meta === null) {
        throw new ApiError(404, '팀을 찾을 수 없습니다.');
    }
    $team = require_team($teamId);
    $scores = compute_scores($team);
    $out = [
        'team' => [
            'id' => $meta['id'],
            'name' => $meta['name'],
            'memberViewScope' => $meta['memberViewScope'],
            'maxMembers' => (int)$meta['maxMembers'],
        ],
        'revision' => (int)$team['revision'],
        'departments' => $team['departments'],
    ];

    if (is_admin($ctx)) {
        $users = load_users()['users'];
        $locked = locked_login_ids();
        $accounts = [];
        $userLabels = [];
        foreach ($users as $u) {
            $userLabels[$u['id']] = $u['loginId'];
            if (($u['teamId'] ?? null) === $teamId && $u['role'] === 'member') {
                $accounts[$u['personId']] = [
                    'userId' => $u['id'],
                    'loginId' => $u['loginId'],
                    'active' => !empty($u['active']),
                    'locked' => isset($locked[strtolower($u['loginId'])]),
                ];
            }
        }
        foreach ($team['people'] as $p) {
            if (isset($accounts[$p['id']])) {
                $userLabels[$accounts[$p['id']]['userId']] = $p['name'] . ' (' . $accounts[$p['id']]['loginId'] . ')';
            }
        }
        $out['people'] = array_map(fn($p) => $p + [
            'score' => $scores[$p['id']],
            'account' => $accounts[$p['id']] ?? null,
        ], $team['people']);
        $out['relationships'] = array_map(fn($r) => $r + [
            'createdByLabel' => $userLabels[$r['createdBy']] ?? '(삭제된 계정)',
            'updatedByLabel' => $userLabels[$r['updatedBy']] ?? '(삭제된 계정)',
        ], $team['relationships']);
        return $out;
    }

    $me = (string)$ctx['user']['personId'];
    $out['people'] = array_map(fn($p) => [
        'id' => $p['id'], 'name' => $p['name'], 'departmentId' => $p['departmentId'],
    ], $team['people']);
    $rels = $team['relationships'];
    if ($meta['memberViewScope'] !== 'team') {
        $rels = array_filter($rels, fn($r) => $r['a'] === $me || $r['b'] === $me);
    }
    $out['relationships'] = array_values(array_map(fn($r) => [
        'id' => $r['id'], 'a' => $r['a'], 'b' => $r['b'], 'status' => $r['status'], 'updatedAt' => $r['updatedAt'],
    ], $rels));
    $out['me'] = ['personId' => $me, 'score' => $scores[$me] ?? 0];
    return $out;
}

function act_revision(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $team = load_team($teamId);
    if ($team === null) {
        throw new ApiError(404, '팀을 찾을 수 없습니다.');
    }
    return ['revision' => (int)$team['revision']];
}

// ---------------------------------------------------------------------------
// 부서
// ---------------------------------------------------------------------------
function act_department_create(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $name = clean_name($input['name'] ?? null, '부서명', 20);
    $dept = mutate_team($teamId, function (array &$team) use ($name) {
        foreach ($team['departments'] as $d) {
            if (same_name($d['name'], $name)) {
                throw new ApiError(409, '같은 이름의 부서가 이미 있습니다.');
            }
        }
        $dept = ['id' => new_id('d'), 'name' => $name, 'color' => next_department_color($team['departments'])];
        $team['departments'][] = $dept;
        return $dept;
    });
    audit($ctx['user']['id'], 'department_create', $teamId, $dept['id'], $name);
    return ['department' => $dept];
}

function act_department_update(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $id = assert_id($input['departmentId'] ?? null, '부서 ID');
    $name = array_key_exists('name', $input) ? clean_name($input['name'], '부서명', 20) : null;
    $color = array_key_exists('color', $input) ? valid_color($input['color']) : null;
    $dept = mutate_team($teamId, function (array &$team) use ($id, $name, $color) {
        $i = find_index($team['departments'], $id);
        if ($i < 0) {
            throw new ApiError(404, '부서를 찾을 수 없습니다.');
        }
        if ($name !== null) {
            foreach ($team['departments'] as $d) {
                if ($d['id'] !== $id && same_name($d['name'], $name)) {
                    throw new ApiError(409, '같은 이름의 부서가 이미 있습니다.');
                }
            }
            $team['departments'][$i]['name'] = $name;
        }
        if ($color !== null) {
            $team['departments'][$i]['color'] = $color;
        }
        return $team['departments'][$i];
    });
    audit($ctx['user']['id'], 'department_update', $teamId, $id, $dept['name'] . ' ' . $dept['color']);
    return ['department' => $dept];
}

function act_department_delete(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $ids = id_list($input, 'departmentIds', '부서');
    $names = mutate_team($teamId, function (array &$team) use ($ids) {
        $names = [];
        foreach ($ids as $id) {
            $i = find_index($team['departments'], $id);
            if ($i < 0) {
                throw new ApiError(404, '부서를 찾을 수 없습니다.');
            }
            foreach ($team['people'] as $p) {
                if ($p['departmentId'] === $id) {
                    throw new ApiError(409, "'{$team['departments'][$i]['name']}' 부서에 소속 부서원이 있어 삭제할 수 없습니다. 먼저 부서원을 다른 부서로 이동하세요.");
                }
            }
            $names[] = $team['departments'][$i]['name'];
        }
        $team['departments'] = array_values(array_filter($team['departments'], fn($d) => !in_array($d['id'], $ids, true)));
        return $names;
    });
    audit($ctx['user']['id'], 'department_delete', $teamId, implode(',', $ids), implode(', ', $names));
    return ['deleted' => count($ids)];
}

// ---------------------------------------------------------------------------
// 부서원·계정
// ---------------------------------------------------------------------------
function act_person_create(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $name = clean_name($input['name'] ?? null, '이름', 20);
    $deptId = assert_id($input['departmentId'] ?? null, '부서');
    $loginRaw = $input['loginId'] ?? '';
    $loginId = (is_string($loginRaw) && trim($loginRaw) !== '') ? clean_login_id($loginRaw) : null;

    $result = with_lock(function () use ($teamId, $name, $deptId, $loginId) {
        $meta = find_team_meta($teamId);
        if ($loginId !== null && find_user_by_login(load_users(), $loginId) !== null) {
            throw new ApiError(409, '이미 사용 중인 로그인 ID입니다.');
        }
        $person = mutate_team($teamId, function (array &$team) use ($meta, $name, $deptId) {
            if (count($team['people']) >= (int)$meta['maxMembers']) {
                throw new ApiError(409, '팀 인원 한도(' . $meta['maxMembers'] . '명)에 도달했습니다. 팀 관리에서 한도를 변경할 수 있습니다.');
            }
            if (find_index($team['departments'], $deptId) < 0) {
                throw new ApiError(400, '부서를 찾을 수 없습니다.');
            }
            foreach ($team['people'] as $p) {
                if (same_name($p['name'], $name)) {
                    throw new ApiError(409, '팀 내에 같은 이름의 부서원이 이미 있습니다.');
                }
            }
            $person = ['id' => new_id('p'), 'name' => $name, 'departmentId' => $deptId, 'createdAt' => now_iso()];
            $team['people'][] = $person;
            return $person;
        });
        $account = $loginId !== null ? add_member_account($teamId, $person['id'], $loginId) : null;
        return ['person' => $person, 'account' => $account];
    });
    $uid = $ctx['user']['id'];
    audit($uid, 'person_create', $teamId, $result['person']['id'], $name);
    $out = ['person' => $result['person']];
    if ($result['account']) {
        audit($uid, 'account_create', $teamId, $loginId, $name);
        $out['loginId'] = $loginId;
        $out['tempPassword'] = $result['account']['tempPassword'];
    }
    return $out;
}

function act_person_update(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $id = assert_id($input['personId'] ?? null, '부서원 ID');
    $name = array_key_exists('name', $input) ? clean_name($input['name'], '이름', 20) : null;
    $deptId = array_key_exists('departmentId', $input) ? assert_id($input['departmentId'], '부서') : null;
    $person = mutate_team($teamId, function (array &$team) use ($id, $name, $deptId) {
        $i = find_index($team['people'], $id);
        if ($i < 0) {
            throw new ApiError(404, '부서원을 찾을 수 없습니다.');
        }
        if ($name !== null) {
            foreach ($team['people'] as $p) {
                if ($p['id'] !== $id && same_name($p['name'], $name)) {
                    throw new ApiError(409, '팀 내에 같은 이름의 부서원이 이미 있습니다.');
                }
            }
            $team['people'][$i]['name'] = $name;
        }
        if ($deptId !== null) {
            if (find_index($team['departments'], $deptId) < 0) {
                throw new ApiError(400, '부서를 찾을 수 없습니다.');
            }
            $team['people'][$i]['departmentId'] = $deptId;
        }
        return $team['people'][$i];
    });
    audit($ctx['user']['id'], 'person_update', $teamId, $id, $person['name']);
    return ['person' => $person];
}

function act_person_delete(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $ids = id_list($input, 'personIds', '부서원');
    $result = with_lock(function () use ($teamId, $ids) {
        // 순서: 팀 파일에서 부서원·관계 제거 → users.json에서 계정 제거
        $info = mutate_team($teamId, function (array &$team) use ($ids) {
            $names = [];
            foreach ($ids as $id) {
                $i = find_index($team['people'], $id);
                if ($i < 0) {
                    throw new ApiError(404, '부서원을 찾을 수 없습니다.');
                }
                $names[] = $team['people'][$i]['name'];
            }
            $team['people'] = array_values(array_filter($team['people'], fn($p) => !in_array($p['id'], $ids, true)));
            $before = count($team['relationships']);
            $team['relationships'] = array_values(array_filter(
                $team['relationships'],
                fn($r) => !in_array($r['a'], $ids, true) && !in_array($r['b'], $ids, true)
            ));
            return ['names' => $names, 'relationships' => $before - count($team['relationships'])];
        });
        $removed = remove_users_and_state(fn($u) => ($u['teamId'] ?? null) === $teamId && in_array($u['personId'] ?? null, $ids, true));
        return $info + ['accounts' => count($removed)];
    });
    audit($ctx['user']['id'], 'person_delete', $teamId, implode(',', $ids),
        implode(', ', $result['names']) . " (관계 {$result['relationships']}개, 계정 {$result['accounts']}개 삭제)");
    return ['deleted' => count($ids), 'relationships' => $result['relationships'], 'accounts' => $result['accounts']];
}

function act_account_create(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $personId = assert_id($input['personId'] ?? null, '부서원 ID');
    $loginId = clean_login_id($input['loginId'] ?? null);
    $result = with_lock(function () use ($teamId, $personId, $loginId) {
        $team = require_team($teamId);
        if (find_index($team['people'], $personId) < 0) {
            throw new ApiError(404, '부서원을 찾을 수 없습니다.');
        }
        $r = add_member_account($teamId, $personId, $loginId);
        mutate_team($teamId, fn() => null);
        return $r;
    });
    audit($ctx['user']['id'], 'account_create', $teamId, $loginId, person_name(require_team($teamId), $personId));
    return ['loginId' => $loginId, 'tempPassword' => $result['tempPassword']];
}

function act_account_reset_password(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $personId = assert_id($input['personId'] ?? null, '부서원 ID');
    $temp = generate_temp_password();
    $loginId = with_lock(function () use ($teamId, $personId, $temp) {
        $acc = member_account($teamId, $personId);
        $doc = load_users();
        foreach ($doc['users'] as &$u) {
            if ($u['id'] === $acc['id']) {
                $u['passwordHash'] = hash_password($temp);
                $u['mustChangePassword'] = true;
            }
        }
        save_users($doc);
        return $acc['loginId'];
    });
    unlock_login($loginId);
    audit($ctx['user']['id'], 'account_reset_password', $teamId, $loginId);
    return ['loginId' => $loginId, 'tempPassword' => $temp];
}

function act_account_set_active(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $personId = assert_id($input['personId'] ?? null, '부서원 ID');
    $active = bool_input($input, 'active');
    $loginId = with_lock(function () use ($teamId, $personId, $active) {
        $acc = member_account($teamId, $personId);
        $doc = load_users();
        foreach ($doc['users'] as &$u) {
            if ($u['id'] === $acc['id']) {
                $u['active'] = $active;
            }
        }
        save_users($doc);
        mutate_team($teamId, fn() => null);
        return $acc['loginId'];
    });
    audit($ctx['user']['id'], $active ? 'account_activate' : 'account_deactivate', $teamId, $loginId);
    return ['active' => $active];
}

function act_account_unlock(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $personId = assert_id($input['personId'] ?? null, '부서원 ID');
    $acc = member_account($teamId, $personId);
    unlock_login($acc['loginId']);
    with_lock(fn() => mutate_team($teamId, fn() => null));
    audit($ctx['user']['id'], 'account_unlock', $teamId, $acc['loginId']);
    return [];
}

// ---------------------------------------------------------------------------
// 관계
// ---------------------------------------------------------------------------
function act_relationship_upsert(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $status = valid_status($input['status'] ?? null);
    if (is_admin($ctx)) {
        $a = assert_id($input['a'] ?? null, '부서원 ID');
        $b = assert_id($input['b'] ?? null, '부서원 ID');
    } else {
        // 본인은 세션에서 결정 — 클라이언트가 지정할 수 없음
        $a = (string)$ctx['user']['personId'];
        $b = assert_id($input['otherId'] ?? null, '상대 부서원 ID');
    }
    if ($a === $b) {
        throw new ApiError(400, '자기 자신과는 관계를 만들 수 없습니다.');
    }
    $uid = $ctx['user']['id'];
    $res = mutate_team($teamId, function (array &$team) use ($a, $b, $status, $uid) {
        if (find_index($team['people'], $a) < 0 || find_index($team['people'], $b) < 0) {
            throw new ApiError(404, '같은 팀의 부서원이 아닙니다.');
        }
        $id = relationship_id($a, $b);
        $now = now_iso();
        $i = find_index($team['relationships'], $id);
        if ($i >= 0) {
            $old = $team['relationships'][$i]['status'];
            $team['relationships'][$i]['status'] = $status;
            $team['relationships'][$i]['updatedBy'] = $uid;
            $team['relationships'][$i]['updatedAt'] = $now;
            return ['id' => $id, 'created' => false, 'old' => $old];
        }
        [$x, $y] = strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
        $team['relationships'][] = [
            'id' => $id, 'a' => $x, 'b' => $y, 'status' => $status,
            'createdBy' => $uid, 'createdAt' => $now, 'updatedBy' => $uid, 'updatedAt' => $now,
        ];
        return ['id' => $id, 'created' => true, 'old' => null];
    });
    $team = require_team($teamId);
    $label = person_name($team, $a) . ' ↔ ' . person_name($team, $b);
    audit($uid, $res['created'] ? 'relationship_create' : 'relationship_update', $teamId, $res['id'],
        $label . ': ' . ($res['old'] ? "{$res['old']} → " : '') . $status);
    return ['id' => $res['id'], 'created' => $res['created']];
}

function act_relationship_delete(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $ids = id_list($input, 'ids', '관계');
    $me = is_admin($ctx) ? null : (string)$ctx['user']['personId'];
    $labels = mutate_team($teamId, function (array &$team) use ($ids, $me) {
        $labels = [];
        foreach ($team['relationships'] as $r) {
            if (!in_array($r['id'], $ids, true)) {
                continue;
            }
            if ($me !== null && $r['a'] !== $me && $r['b'] !== $me) {
                throw new ApiError(403, '본인이 포함된 관계만 삭제할 수 있습니다.');
            }
            $labels[] = person_name($team, $r['a']) . ' ↔ ' . person_name($team, $r['b']) . " ({$r['status']})";
        }
        // 이미 삭제된 관계는 무시 (양 당사자가 동시에 삭제하는 경우)
        $team['relationships'] = array_values(array_filter($team['relationships'], fn($r) => !in_array($r['id'], $ids, true)));
        return $labels;
    });
    if ($labels) {
        audit($ctx['user']['id'], 'relationship_delete', $teamId, implode(',', $ids), implode(', ', $labels));
    }
    return ['deleted' => count($labels)];
}

// ---------------------------------------------------------------------------
// 샘플·내보내기·가져오기·로그
// ---------------------------------------------------------------------------
function act_team_seed(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $uid = $ctx['user']['id'];
    $added = with_lock(function () use ($teamId, $uid) {
        $meta = find_team_meta($teamId);
        return mutate_team($teamId, function (array &$team) use ($meta, $uid) {
            if (count($team['people']) >= (int)$meta['maxMembers']) {
                throw new ApiError(409, '팀 인원 한도에 도달하여 샘플 데이터를 추가할 수 없습니다.');
            }
            return seed_team($team, (int)$meta['maxMembers'], $uid);
        });
    });
    audit($uid, 'team_seed', $teamId, null, "샘플 부서원 {$added}명 추가");
    return ['added' => $added];
}

function act_team_export(array $input, array $ctx): array
{
    $teamId = resolve_team_id($ctx, $input);
    $meta = find_team_meta($teamId);
    $team = require_team($teamId);
    audit($ctx['user']['id'], 'team_export', $teamId, $meta['name']);
    return [
        'schemaVersion' => SCHEMA_VERSION,
        'exportedAt' => now_iso(),
        'team' => [
            'name' => $meta['name'],
            'memberViewScope' => $meta['memberViewScope'],
            'maxMembers' => (int)$meta['maxMembers'],
        ],
        'departments' => array_map(fn($d) => ['id' => $d['id'], 'name' => $d['name'], 'color' => $d['color']], $team['departments']),
        'people' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'departmentId' => $p['departmentId']], $team['people']),
        'relationships' => array_map(fn($r) => ['a' => $r['a'], 'b' => $r['b'], 'status' => $r['status']], $team['relationships']),
    ];
}

/**
 * 4.4 규칙: 항상 새 팀으로 생성, 스키마·참조 무결성·문자열 재검증, ID 재발급, 계정 미포함, 전부 성공 또는 전부 실패.
 * 내보내기 파일과 trash/의 팀 파일(teams/{id}.json 형식)을 모두 받는다.
 */
function act_team_import(array $input, array $ctx): array
{
    $data = $input['data'] ?? null;
    if (!is_array($data)) {
        throw new ApiError(400, '가져올 데이터가 없습니다.');
    }
    if (($data['schemaVersion'] ?? null) !== SCHEMA_VERSION) {
        throw new ApiError(400, '지원하지 않는 schemaVersion입니다.');
    }
    $fileTeam = is_array($data['team'] ?? null) ? $data['team'] : [];
    $nameSource = (is_string($input['name'] ?? null) && trim($input['name']) !== '') ? $input['name'] : ($fileTeam['name'] ?? null);
    if ($nameSource === null) {
        throw new ApiError(400, '새 팀 이름을 입력하세요.');
    }
    $name = clean_name($nameSource, '팀 이름', 30);
    $scope = $fileTeam['memberViewScope'] ?? 'own';
    if (!in_array($scope, ['own', 'team'], true)) {
        throw new ApiError(400, '공개 범위 값이 올바르지 않습니다.');
    }
    foreach (['departments', 'people', 'relationships'] as $k) {
        if (!is_array($data[$k] ?? null) || !array_is_list($data[$k])) {
            throw new ApiError(400, "{$k} 항목이 배열이 아닙니다.");
        }
    }

    // 부서
    $deptMap = [];
    $departments = [];
    foreach ($data['departments'] as $i => $d) {
        if (!is_array($d)) {
            throw new ApiError(400, "부서 #" . ($i + 1) . " 형식이 올바르지 않습니다.");
        }
        $oldId = assert_id($d['id'] ?? null, '부서 ID');
        if (isset($deptMap[$oldId])) {
            throw new ApiError(400, "부서 ID가 중복됩니다: {$oldId}");
        }
        $dname = clean_name($d['name'] ?? null, '부서명', 20);
        foreach ($departments as $x) {
            if (same_name($x['name'], $dname)) {
                throw new ApiError(400, "부서명이 중복됩니다: {$dname}");
            }
        }
        $color = isset($d['color']) ? valid_color($d['color']) : next_department_color($departments);
        $deptMap[$oldId] = new_id('d');
        $departments[] = ['id' => $deptMap[$oldId], 'name' => $dname, 'color' => $color];
    }

    // 부서원
    $personMap = [];
    $people = [];
    $now = now_iso();
    foreach ($data['people'] as $i => $p) {
        if (!is_array($p)) {
            throw new ApiError(400, "부서원 #" . ($i + 1) . " 형식이 올바르지 않습니다.");
        }
        $oldId = assert_id($p['id'] ?? null, '부서원 ID');
        if (isset($personMap[$oldId])) {
            throw new ApiError(400, "부서원 ID가 중복됩니다: {$oldId}");
        }
        $pname = clean_name($p['name'] ?? null, '이름', 20);
        foreach ($people as $x) {
            if (same_name($x['name'], $pname)) {
                throw new ApiError(400, "부서원 이름이 중복됩니다: {$pname}");
            }
        }
        $dept = $p['departmentId'] ?? null;
        if (!is_string($dept) || !isset($deptMap[$dept])) {
            throw new ApiError(400, "부서원 '{$pname}'의 부서가 존재하지 않습니다.");
        }
        $personMap[$oldId] = new_id('p');
        $people[] = ['id' => $personMap[$oldId], 'name' => $pname, 'departmentId' => $deptMap[$dept], 'createdAt' => $now];
    }

    // 인원 한도
    $count = count($people);
    $max = DEFAULT_MAX_MEMBERS;
    if (array_key_exists('maxMembers', $input) && $input['maxMembers'] !== null) {
        $max = max_members_input($input['maxMembers']);
    } elseif (isset($fileTeam['maxMembers']) && is_int($fileTeam['maxMembers'])
        && $fileTeam['maxMembers'] >= 1 && $fileTeam['maxMembers'] <= DEFAULT_MAX_MEMBERS) {
        $max = $fileTeam['maxMembers'];
    }
    if ($count > $max) {
        throw new ApiError(409, "부서원 수({$count}명)가 인원 한도({$max}명)를 넘습니다. 인원 한도(최대 " . SYSTEM_MAX_MEMBERS . "명)를 함께 지정하세요.");
    }

    // 관계
    $rels = [];
    $uid = $ctx['user']['id'];
    foreach ($data['relationships'] as $i => $r) {
        if (!is_array($r)) {
            throw new ApiError(400, "관계 #" . ($i + 1) . " 형식이 올바르지 않습니다.");
        }
        $a = $r['a'] ?? null;
        $b = $r['b'] ?? null;
        if (!is_string($a) || !is_string($b) || !isset($personMap[$a], $personMap[$b])) {
            throw new ApiError(400, "관계 #" . ($i + 1) . "의 당사자가 존재하지 않습니다.");
        }
        if ($a === $b) {
            throw new ApiError(400, "관계 #" . ($i + 1) . "의 두 당사자가 같습니다.");
        }
        $status = valid_status($r['status'] ?? null);
        $na = $personMap[$a];
        $nb = $personMap[$b];
        $id = relationship_id($na, $nb);
        if (isset($rels[$id])) {
            throw new ApiError(400, "관계 #" . ($i + 1) . "가 중복된 쌍입니다.");
        }
        [$x, $y] = strcmp($na, $nb) < 0 ? [$na, $nb] : [$nb, $na];
        $rels[$id] = [
            'id' => $id, 'a' => $x, 'b' => $y, 'status' => $status,
            'createdBy' => $uid, 'createdAt' => $now, 'updatedBy' => $uid, 'updatedAt' => $now,
        ];
    }

    $meta = with_lock(function () use ($name, $scope, $max, $departments, $people, $rels) {
        if (team_name_taken($name)) {
            throw new ApiError(409, '같은 이름의 팀이 이미 있습니다. 다른 이름을 입력하세요.');
        }
        $meta = [
            'id' => new_id('t'), 'name' => $name, 'memberViewScope' => $scope, 'maxMembers' => $max,
            'createdAt' => now_iso(), 'updatedAt' => now_iso(),
        ];
        $team = new_team_document($meta['id']);
        $team['departments'] = $departments;
        $team['people'] = $people;
        $team['relationships'] = array_values($rels);
        save_team($team);
        $doc = load_teams();
        $doc['teams'][] = $meta;
        save_teams($doc);
        return $meta;
    });
    audit($uid, 'team_import', $meta['id'], $name, "부서 " . count($departments) . "개, 부서원 {$count}명, 관계 " . count($rels) . "개");
    return ['team' => $meta];
}

function act_audit_log(array $input, array $ctx): array
{
    $labels = [];
    foreach (load_users()['users'] as $u) {
        $labels[$u['id']] = $u['loginId'];
    }
    $teams = [];
    foreach (load_teams()['teams'] as $t) {
        $teams[$t['id']] = $t['name'];
    }
    return ['entries' => array_map(fn($e) => $e + [
        'userLabel' => $e['userId'] ? ($labels[$e['userId']] ?? '(삭제된 계정)') : '-',
        'teamName' => $e['teamId'] ? ($teams[$e['teamId']] ?? '(삭제된 팀)') : '-',
    ], read_audit())];
}
