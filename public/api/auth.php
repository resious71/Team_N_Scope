<?php
/**
 * 세션·로그인·CSRF·비밀번호·로그인 실패 제한.
 */
declare(strict_types=1);

require_once __DIR__ . '/storage.php';

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' && getenv('GCBA_TRUST_PROXY') === '1');
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (getenv('GCBA_TRUST_PROXY') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            $ip = $first;
        }
    }
    return $ip;
}

function session_cookie_options(): array
{
    return [
        'lifetime' => SESSION_IDLE_SECONDS,
        'path' => '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.gc_maxlifetime', (string)SESSION_IDLE_SECONDS);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('GCBA_SID');
    session_set_cookie_params(session_cookie_options());
    session_start();
}

/** 유휴 만료를 연장하도록 쿠키를 다시 보낸다. */
function refresh_session_cookie(): void
{
    $o = session_cookie_options();
    setcookie(session_name(), session_id(), [
        'expires' => time() + $o['lifetime'],
        'path' => $o['path'],
        'secure' => $o['secure'],
        'httponly' => $o['httponly'],
        'samesite' => $o['samesite'],
    ]);
}

function destroy_session(): void
{
    start_session();
    $_SESSION = [];
    $o = session_cookie_options();
    setcookie(session_name(), '', ['expires' => time() - 3600] + array_diff_key($o, ['lifetime' => 1]));
    session_destroy();
}

function find_user_by_id(array $usersDoc, string $id): ?array
{
    foreach ($usersDoc['users'] as $u) {
        if ($u['id'] === $id) {
            return $u;
        }
    }
    return null;
}

function find_user_by_login(array $usersDoc, string $loginId): ?array
{
    foreach ($usersDoc['users'] as $u) {
        if (strtolower($u['loginId']) === strtolower($loginId)) {
            return $u;
        }
    }
    return null;
}

function needs_consent(array $user): bool
{
    return empty($user['consentedAt']) || strtotime($user['consentedAt']) < strtotime(PRIVACY_NOTICE_VERSION);
}

/**
 * 세션을 읽고 users.json으로 계정 상태를 다시 확인한 뒤 세션 잠금을 해제한다.
 * 계정이 없거나 비활성·역할/소속 변경 시 세션을 파기하고 401.
 * 반환: ['user' => 계정, 'csrf' => 토큰]
 */
function require_session(): array
{
    start_session();
    $uid = $_SESSION['uid'] ?? null;
    if (!is_string($uid)) {
        session_write_close();
        throw new ApiError(401, '로그인이 필요합니다.');
    }
    if (time() - (int)($_SESSION['lastActivity'] ?? 0) > SESSION_IDLE_SECONDS) {
        destroy_session();
        throw new ApiError(401, '세션이 만료되었습니다. 다시 로그인하세요.');
    }
    $user = find_user_by_id(load_users(), $uid);
    $valid = $user !== null
        && !empty($user['active'])
        && $user['role'] === ($_SESSION['role'] ?? null)
        && ($user['teamId'] ?? null) === ($_SESSION['teamId'] ?? null)
        && ($user['personId'] ?? null) === ($_SESSION['personId'] ?? null);
    if ($valid && $user['role'] === 'member') {
        // 소속 팀·부서원이 실제로 존재하는지 확인 (중간 실패로 남은 고아 계정 차단)
        $team = find_team_meta((string)$user['teamId']) ? load_team((string)$user['teamId']) : null;
        $valid = $team !== null && find_index($team['people'], (string)$user['personId']) >= 0;
        if (!$valid) {
            error_log("[gcba] orphan account blocked: {$user['id']}");
        }
    }
    if (!$valid) {
        destroy_session();
        throw new ApiError(401, '계정 상태가 변경되어 다시 로그인해야 합니다.');
    }
    $_SESSION['lastActivity'] = time();
    $csrf = (string)($_SESSION['csrf'] ?? '');
    refresh_session_cookie();
    session_write_close();
    return ['user' => $user, 'csrf' => $csrf];
}

function check_csrf(string $expected): void
{
    $got = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($expected === '' || !is_string($got) || !hash_equals($expected, $got)) {
        throw new ApiError(403, '보안 토큰이 올바르지 않습니다. 페이지를 새로고침하세요.', 'CSRF');
    }
}

// ---------------------------------------------------------------------------
// 로그인 실패 제한 (로그인 ID + IP 조합)
// ---------------------------------------------------------------------------
function failure_key(string $loginId, string $ip): string
{
    return strtolower($loginId) . '|' . $ip;
}

function clean_failures(array &$failures): void
{
    $now = time();
    foreach ($failures as $k => $f) {
        $locked = !empty($f['lockedUntil']) && strtotime($f['lockedUntil']) > $now;
        if (!$locked && $now - strtotime($f['lastFailedAt'] ?? '1970-01-01') > 86400) {
            unset($failures[$k]);
        }
    }
}

function login(array $input): array
{
    $loginIdRaw = is_string($input['loginId'] ?? null) ? strtolower(trim($input['loginId'])) : '';
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    $generic = 'ID 또는 비밀번호가 올바르지 않습니다.';
    if ($loginIdRaw === '' || $password === '' || strlen($loginIdRaw) > 40 || strlen($password) > 200) {
        throw new ApiError(400, $generic);
    }
    $ip = client_ip();
    $key = failure_key($loginIdRaw, $ip);

    $state = load_user_state();
    $fail = $state['loginFailures'][$key] ?? null;
    if ($fail && !empty($fail['lockedUntil']) && strtotime($fail['lockedUntil']) > time()) {
        audit(null, 'login_locked', null, $loginIdRaw, "IP {$ip}");
        throw new ApiError(423, '로그인 시도가 너무 많아 잠시 잠겼습니다. 10분 후 다시 시도하세요.');
    }

    $user = find_user_by_login(load_users(), $loginIdRaw);
    // 계정 존재 여부와 무관하게 비슷한 시간이 걸리도록 더미 해시도 검증
    $hash = $user['passwordHash'] ?? '$2y$10$FG71fD/mezeJ/CLSdNFGru8NNu6kPQ5GqpIDO5M.jKtriTN0VvUYK';
    $ok = password_verify($password, $hash) && $user !== null;

    if (!$ok) {
        $count = with_lock(function () use ($key) {
            $doc = load_user_state();
            clean_failures($doc['loginFailures']);
            $f = $doc['loginFailures'][$key] ?? ['count' => 0, 'lockedUntil' => null, 'lastFailedAt' => null];
            if ($f['lastFailedAt'] && time() - strtotime($f['lastFailedAt']) > LOGIN_FAIL_WINDOW) {
                $f['count'] = 0;
            }
            $f['count'] = (int)$f['count'] + 1;
            $f['lastFailedAt'] = now_iso();
            $f['lockedUntil'] = $f['count'] >= LOGIN_FAIL_LIMIT ? date('c', time() + LOGIN_LOCK_SECONDS) : null;
            $doc['loginFailures'][$key] = $f;
            save_user_state($doc);
            return $f['count'];
        });
        audit($user['id'] ?? null, 'login_failed', $user['teamId'] ?? null, $loginIdRaw, "IP {$ip}, {$count}회");
        // 실패가 늘수록 응답 지연을 점진적으로 늘림 (최대 2초)
        usleep(min($count, 5) * 400000);
        if ($count >= LOGIN_FAIL_LIMIT) {
            throw new ApiError(423, '로그인 시도가 너무 많아 잠시 잠겼습니다. 10분 후 다시 시도하세요.');
        }
        throw new ApiError(401, $generic);
    }
    if (empty($user['active'])) {
        audit($user['id'], 'login_inactive', $user['teamId'] ?? null, $loginIdRaw, "IP {$ip}");
        throw new ApiError(403, '비활성화된 계정입니다. 관리자에게 문의하세요.');
    }
    if ($user['role'] === 'member' && find_team_meta((string)$user['teamId']) === null) {
        throw new ApiError(403, '소속 팀을 찾을 수 없습니다. 관리자에게 문의하세요.');
    }

    if (password_needs_rehash($user['passwordHash'], PASSWORD_DEFAULT)) {
        with_lock(function () use ($user, $password) {
            $doc = load_users();
            foreach ($doc['users'] as &$u) {
                if ($u['id'] === $user['id']) {
                    $u['passwordHash'] = hash_password($password);
                }
            }
            save_users($doc);
        });
    }

    with_lock(function () use ($key, $user) {
        $doc = load_user_state();
        unset($doc['loginFailures'][$key]);
        clean_failures($doc['loginFailures']);
        $s = (array)($doc['states'][$user['id']] ?? []);
        $s['lastLoginAt'] = now_iso();
        $doc['states'][$user['id']] = $s;
        save_user_state($doc);
    });

    start_session();
    session_regenerate_id(true);
    $_SESSION = [
        'uid' => $user['id'],
        'role' => $user['role'],
        'teamId' => $user['teamId'] ?? null,
        'personId' => $user['personId'] ?? null,
        'csrf' => bin2hex(random_bytes(32)),
        'lastActivity' => time(),
    ];
    $csrf = $_SESSION['csrf'];
    session_write_close();
    audit($user['id'], 'login', $user['teamId'] ?? null, $user['loginId'], "IP {$ip}");
    return ['user' => $user, 'csrf' => $csrf];
}

function change_password(array $user, array $input): void
{
    $current = $input['currentPassword'] ?? '';
    if (!is_string($current) || !password_verify($current, $user['passwordHash'])) {
        throw new ApiError(400, '현재 비밀번호가 올바르지 않습니다.');
    }
    $new = validate_password_policy($input['newPassword'] ?? null);
    if (hash_equals($current, $new)) {
        throw new ApiError(400, '새 비밀번호는 현재 비밀번호와 달라야 합니다.');
    }
    with_lock(function () use ($user, $new) {
        $doc = load_users();
        foreach ($doc['users'] as &$u) {
            if ($u['id'] === $user['id']) {
                $u['passwordHash'] = hash_password($new);
                $u['mustChangePassword'] = false;
            }
        }
        save_users($doc);
    });
    audit($user['id'], 'password_change', $user['teamId'] ?? null, $user['loginId']);
}

function record_consent(array $user): void
{
    with_lock(function () use ($user) {
        $doc = load_users();
        foreach ($doc['users'] as &$u) {
            if ($u['id'] === $user['id']) {
                $u['consentedAt'] = now_iso();
            }
        }
        save_users($doc);
    });
    audit($user['id'], 'consent', $user['teamId'] ?? null, $user['loginId']);
}
