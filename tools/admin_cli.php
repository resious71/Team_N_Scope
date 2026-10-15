<?php
/**
 * 관리자 계정 도구 (서버 셸 전용).
 *
 *   php tools/admin_cli.php create-admin <loginId> [--generate]
 *   php tools/admin_cli.php reset-password <loginId> [--generate]
 *   php tools/admin_cli.php unlock <loginId>
 *   php tools/admin_cli.php list-admins
 *
 * --generate: 비밀번호를 입력받지 않고 임시 비밀번호를 생성 (최초 로그인 시 변경 강제)
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/public/api/storage.php';

function out(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg): never
{
    fwrite(STDERR, '오류: ' . $msg . PHP_EOL);
    exit(1);
}

function prompt_hidden(string $label): string
{
    fwrite(STDOUT, $label);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return rtrim((string)$line, "\r\n");
}

/** [비밀번호, 변경 강제 여부] */
function obtain_password(bool $generate): array
{
    if ($generate) {
        return [generate_temp_password(), true];
    }
    $pw = prompt_hidden('비밀번호: ');
    try {
        validate_password_policy($pw);
    } catch (ApiError $e) {
        fail($e->getMessage());
    }
    if (prompt_hidden('비밀번호 확인: ') !== $pw) {
        fail('비밀번호가 일치하지 않습니다.');
    }
    return [$pw, false];
}

function find_admin(array $doc, string $loginId): int
{
    foreach ($doc['users'] as $i => $u) {
        if (strtolower($u['loginId']) === $loginId) {
            if ($u['role'] !== 'admin') {
                fail("{$loginId}은(는) 관리자 계정이 아닙니다. 부서원 계정은 웹 관리자 탭에서 관리하세요.");
            }
            return $i;
        }
    }
    fail("관리자 계정 {$loginId}을(를) 찾을 수 없습니다.");
}

$args = array_slice($argv, 1);
$generate = in_array('--generate', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--generate'));
$cmd = $args[0] ?? '';

try {
    ensure_data_dirs();
    switch ($cmd) {
        case 'create-admin':
            $loginId = clean_login_id($args[1] ?? '');
            [$pw, $must] = obtain_password($generate);
            $user = with_lock(function () use ($loginId, $pw, $must) {
                $doc = load_users();
                foreach ($doc['users'] as $u) {
                    if (strtolower($u['loginId']) === $loginId) {
                        fail('이미 사용 중인 로그인 ID입니다.');
                    }
                }
                $user = [
                    'id' => new_id('u'),
                    'loginId' => $loginId,
                    'passwordHash' => hash_password($pw),
                    'role' => 'admin',
                    'teamId' => null,
                    'personId' => null,
                    'active' => true,
                    'mustChangePassword' => $must,
                    'consentedAt' => null,
                    'createdAt' => now_iso(),
                ];
                $doc['users'][] = $user;
                save_users($doc);
                return $user;
            });
            audit(null, 'admin_create_cli', null, $loginId);
            out("관리자 계정을 만들었습니다: {$loginId} ({$user['id']})");
            if ($must) {
                out("임시 비밀번호: {$pw}  (최초 로그인 시 변경 필요)");
            }
            break;

        case 'reset-password':
            $loginId = clean_login_id($args[1] ?? '');
            [$pw, $must] = obtain_password($generate);
            with_lock(function () use ($loginId, $pw, $must) {
                $doc = load_users();
                $i = find_admin($doc, $loginId);
                $doc['users'][$i]['passwordHash'] = hash_password($pw);
                $doc['users'][$i]['mustChangePassword'] = $must;
                $doc['users'][$i]['active'] = true;
                save_users($doc);
                $state = load_user_state();
                foreach (array_keys($state['loginFailures']) as $k) {
                    if (str_starts_with($k, $loginId . '|')) {
                        unset($state['loginFailures'][$k]);
                    }
                }
                save_user_state($state);
            });
            audit(null, 'admin_reset_password_cli', null, $loginId);
            out("비밀번호를 초기화했습니다: {$loginId}");
            if ($must) {
                out("임시 비밀번호: {$pw}  (최초 로그인 시 변경 필요)");
            }
            break;

        case 'unlock':
            $loginId = clean_login_id($args[1] ?? '');
            $n = with_lock(function () use ($loginId) {
                find_admin(load_users(), $loginId);
                $state = load_user_state();
                $n = 0;
                foreach (array_keys($state['loginFailures']) as $k) {
                    if (str_starts_with($k, $loginId . '|')) {
                        unset($state['loginFailures'][$k]);
                        $n++;
                    }
                }
                save_user_state($state);
                return $n;
            });
            audit(null, 'admin_unlock_cli', null, $loginId);
            out("로그인 잠금/실패 기록 {$n}건을 해제했습니다: {$loginId}");
            break;

        case 'list-admins':
            $state = load_user_state();
            foreach (load_users()['users'] as $u) {
                if ($u['role'] !== 'admin') {
                    continue;
                }
                $last = $state['states'][$u['id']]['lastLoginAt'] ?? '-';
                out(sprintf('%-20s %-12s %-8s 마지막 로그인: %s', $u['loginId'], $u['id'], $u['active'] ? '활성' : '비활성', $last));
            }
            break;

        default:
            out("사용법:\n  php tools/admin_cli.php create-admin <loginId> [--generate]\n  php tools/admin_cli.php reset-password <loginId> [--generate]\n  php tools/admin_cli.php unlock <loginId>\n  php tools/admin_cli.php list-admins");
            exit($cmd === '' ? 0 : 1);
    }
} catch (ApiError $e) {
    fail($e->getMessage());
}
