<?php
/**
 * 이전 버전(v1.0.x, v1.1.x) 데이터를 현재 버전 형식으로 마이그레이션한다 (CLI 전용).
 *
 *   php tools/migrate.php [--from <예전 data 폴더 | 백업 .tar.gz>] [--dry-run] [--force] [--backup-dir <폴더>]
 *
 *   --from        다른 위치(예전 서버)의 data 폴더나 tools/backup.php로 만든 백업 파일을 가져와 옮긴다.
 *                 현재 data 폴더에 이미 데이터가 있으면 --force가 있어야 덮어쓴다 (덮어쓰기 전에 백업).
 *   --dry-run     바뀔 내용만 보고하고 아무것도 쓰지 않는다.
 *   --force       --from으로 가져올 때 현재 데이터를 덮어쓴다.
 *   --backup-dir  마이그레이션 전 백업을 둘 폴더 (기본: GCBA_BACKUP_DIR 또는 저장소의 backups/)
 *
 * 하는 일 (모두 멱등 — 여러 번 실행해도 결과가 같음):
 *   - 계정: 빠진 필드 채움(활성, 비밀번호 변경 요구, 동의 시각, 생성일), 로그인 ID 소문자화, 깨진 항목 보고
 *   - 팀: 인원 한도·날짜 기본값
 *   - 팀 데이터: 부서 색상(예전 10색 → 파스텔 16색), 직급 기본값(부서원)·중복 팀장/부서장 정리,
 *     관계를 평가자별 평가로 변환(마지막 수정자가 당사자면 그 사람의 평가, 아니면 관리자 평가),
 *     없는 부서원을 가리키는 관계 제거
 *   - 사용자 상태 파일 정규화, 마이그레이션 기록(data/migrations.json)
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/public/api/storage.php';

function say(string $msg): void
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function fail(string $msg): never
{
    fwrite(STDERR, '오류: ' . $msg . PHP_EOL);
    exit(1);
}

// ---------------------------------------------------------------------------
// 인자
// ---------------------------------------------------------------------------
$opts = ['from' => null, 'dry-run' => false, 'force' => false, 'backup-dir' => null];
for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '--dry-run' || $arg === '--force') {
        $opts[substr($arg, 2)] = true;
    } elseif ($arg === '--from' || $arg === '--backup-dir') {
        $opts[substr($arg, 2)] = $argv[++$i] ?? fail("{$arg} 다음에 경로가 필요합니다.");
    } elseif ($arg === '-h' || $arg === '--help') {
        say("사용법: php tools/migrate.php [--from <예전 data 폴더|백업.tar.gz>] [--dry-run] [--force] [--backup-dir <폴더>]");
        exit(0);
    } else {
        fail("알 수 없는 인자: {$arg} (--help 참고)");
    }
}
$dry = $opts['dry-run'];
$target = data_dir();
say("Team Scope 데이터 마이그레이션 → v" . APP_VERSION . ($dry ? '  [미리 보기: 아무것도 쓰지 않음]' : ''));
say("대상 data 폴더: {$target}");

// ---------------------------------------------------------------------------
// 도우미
// ---------------------------------------------------------------------------
function has_data(string $dir): bool
{
    return is_file("{$dir}/users.json") || is_file("{$dir}/teams.json");
}

function rcopy(string $src, string $dst): void
{
    if (!is_dir($dst) && !mkdir($dst, 0700, true)) {
        fail("폴더를 만들 수 없습니다: {$dst}");
    }
    foreach (scandir($src) ?: [] as $f) {
        if ($f === '.' || $f === '..' || str_contains($f, '.tmp.')) {
            continue;
        }
        $s = "{$src}/{$f}";
        $d = "{$dst}/{$f}";
        if (is_dir($s)) {
            rcopy($s, $d);
        } elseif (!copy($s, $d)) {
            fail("파일을 복사할 수 없습니다: {$s}");
        } else {
            chmod($d, 0600);
        }
    }
}

function rremove(string $dir): void
{
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = "{$dir}/{$f}";
        is_dir($p) && !is_link($p) ? rremove($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** 백업 압축 파일을 임시 폴더에 풀고 그 안의 data 폴더 경로를 돌려준다. */
function extract_backup(string $archive): string
{
    $tmp = sys_get_temp_dir() . '/team-scope-migrate-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700, true);
    register_shutdown_function(fn() => rremove($tmp));
    try {
        (new PharData($archive))->extractTo($tmp, null, true);
    } catch (Throwable $e) {
        fail("백업 파일을 풀 수 없습니다: " . $e->getMessage());
    }
    foreach (["{$tmp}/data", $tmp] as $cand) {
        if (has_data($cand)) {
            return $cand;
        }
    }
    fail('백업 파일 안에서 data 폴더(users.json·teams.json)를 찾을 수 없습니다.');
}

function backup_current(string $dir, ?string $backupDir): ?string
{
    if (!has_data($dir)) {
        return null;
    }
    $dest = rtrim($backupDir ?? (getenv('GCBA_BACKUP_DIR') ?: dirname(__DIR__) . '/backups'), '/');
    if (!is_dir($dest) && !mkdir($dest, 0700, true)) {
        fail("백업 폴더를 만들 수 없습니다: {$dest}");
    }
    // 같은 초에 여러 번 실행해도 겹치지 않도록 고유 접미사
    $tarPath = "{$dest}/gcba-data-premigrate-" . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.tar';
    $tar = new PharData($tarPath);
    $base = realpath($dir);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && !str_contains($file->getPathname(), '.tmp.')) {
            $tar->addFile($file->getPathname(), 'data/' . substr($file->getPathname(), strlen($base) + 1));
        }
    }
    $tar->compress(Phar::GZ);
    unset($tar);
    @unlink($tarPath);
    chmod($tarPath . '.gz', 0600);
    return $tarPath . '.gz';
}

// ---------------------------------------------------------------------------
// 1) --from: 예전 데이터 가져오기
// ---------------------------------------------------------------------------
if ($opts['from'] !== null) {
    $from = $opts['from'];
    if (!file_exists($from)) {
        fail("--from 경로가 없습니다: {$from}");
    }
    $src = is_dir($from) ? rtrim($from, '/') : extract_backup($from);
    if (!has_data($src)) {
        fail("가져올 data 폴더에 users.json·teams.json이 없습니다: {$src}");
    }
    if (realpath($src) === realpath($target)) {
        fail('--from과 대상 data 폴더가 같습니다. 제자리 마이그레이션은 --from 없이 실행하세요.');
    }
    if (has_data($target) && !$opts['force']) {
        fail("대상 data 폴더에 이미 데이터가 있습니다. 덮어쓰려면 --force를 붙이세요 (덮어쓰기 전에 자동 백업). 대상: {$target}");
    }
    say("가져올 데이터: {$src}");
    if (!$dry) {
        $bk = backup_current($target, $opts['backup-dir']);
        if ($bk) {
            say("현재 데이터 백업: {$bk}");
            rremove($target);
        }
        rcopy($src, $target);
        say('예전 데이터를 대상 폴더로 복사했습니다.');
    } else {
        // 미리 보기: 임시 복사본에서 분석
        $preview = sys_get_temp_dir() . '/team-scope-preview-' . bin2hex(random_bytes(4));
        rcopy($src, $preview);
        register_shutdown_function(fn() => rremove($preview));
        putenv("GCBA_DATA_DIR={$preview}");
        $_SERVER['GCBA_DATA_DIR'] = $preview;
        $target = $preview;
        say('(미리 보기는 임시 복사본에서 분석합니다)');
    }
} elseif (!has_data($target)) {
    fail("마이그레이션할 데이터가 없습니다: {$target} (다른 위치의 데이터는 --from으로 지정)");
} elseif (!$dry) {
    $bk = backup_current($target, $opts['backup-dir']);
    say("마이그레이션 전 백업: {$bk}");
}

// ---------------------------------------------------------------------------
// 2) 변환
// ---------------------------------------------------------------------------
$report = [
    'users_fixed' => 0, 'users_invalid' => [], 'orphan_accounts' => [],
    'teams' => 0, 'teams_fixed' => 0, 'colors_reassigned' => 0, 'positions_defaulted' => 0,
    'positions_demoted' => [], 'relationships' => 0, 'rel_to_party' => 0, 'rel_to_mgr' => 0,
    'rel_orphans_removed' => 0, 'missing_team_files' => [],
];

$run = function () use (&$report, $dry) {
    if (!$dry) {
        ensure_data_dirs();
    }
    // --- 계정
    $usersDoc = load_users();
    $usersDoc['schemaVersion'] = SCHEMA_VERSION;
    $seen = [];
    $users = [];
    foreach ($usersDoc['users'] as $u) {
        if (!is_array($u) || !isset($u['id'], $u['loginId'], $u['passwordHash'], $u['role'])
            || !in_array($u['role'], ['admin', 'member'], true)) {
            $report['users_invalid'][] = is_array($u) ? ($u['loginId'] ?? $u['id'] ?? '?') : '?';
            $users[] = $u;   // 깨진 항목은 지우지 않고 보고만 한다
            continue;
        }
        $before = $u;
        $u['loginId'] = strtolower((string)$u['loginId']);
        $u += ['teamId' => null, 'personId' => null, 'active' => true, 'mustChangePassword' => false,
            'consentedAt' => null, 'createdAt' => now_iso()];
        if ($u['role'] === 'admin') {
            $u['teamId'] = null;
            $u['personId'] = null;
        }
        if (isset($seen[$u['loginId']])) {
            $report['users_invalid'][] = "{$u['loginId']} (중복 로그인 ID)";
        }
        $seen[$u['loginId']] = true;
        if ($u !== $before) {
            $report['users_fixed']++;
        }
        $users[] = $u;
    }
    $usersDoc['users'] = $users;
    if (!$dry) {
        save_users($usersDoc);
    }

    // --- 사용자 상태
    if (!$dry && is_file(data_path('user_state.json'))) {
        $st = load_user_state();
        $st['schemaVersion'] = SCHEMA_VERSION;
        save_user_state($st);
    }

    // --- 팀 목록
    $teamsDoc = load_teams();
    $teamsDoc['schemaVersion'] = SCHEMA_VERSION;
    foreach ($teamsDoc['teams'] as &$t) {
        $before = $t;
        $t += ['memberViewScope' => 'own', 'maxMembers' => DEFAULT_MAX_MEMBERS, 'createdAt' => now_iso(), 'updatedAt' => now_iso()];
        $t['maxMembers'] = max(1, min(SYSTEM_MAX_MEMBERS, (int)$t['maxMembers']));
        if ($t !== $before) {
            $report['teams_fixed']++;
        }
    }
    unset($t);
    if (!$dry) {
        save_teams($teamsDoc);
    }

    // --- 팀 데이터
    $validPeople = [];
    foreach ($teamsDoc['teams'] as $meta) {
        $report['teams']++;
        $raw = read_json(team_path($meta['id']), null);
        if ($raw === null) {
            $report['missing_team_files'][] = $meta['name'];
            continue;
        }
        $raw += ['schemaVersion' => SCHEMA_VERSION, 'teamId' => $meta['id'], 'revision' => 0,
            'departments' => [], 'people' => [], 'relationships' => []];

        // 부서 색상
        $legacyColors = count(array_filter($raw['departments'],
            fn($d) => !in_array(strtoupper((string)($d['color'] ?? '')), PALETTE, true)));
        $raw['departments'] = migrate_department_colors($raw['departments']);
        $report['colors_reassigned'] += $legacyColors;

        // 직급: 기본값 부서원, 팀장 1명·부서당 부서장 1명 (초과분은 부서원으로)
        $hasLeader = false;
        $heads = [];
        foreach ($raw['people'] as &$p) {
            if (!isset($p['position']) || !isset(POSITIONS[$p['position']])) {
                $p['position'] = 'member';
                $report['positions_defaulted']++;
            }
            if ($p['position'] === 'leader') {
                if ($hasLeader) {
                    $p['position'] = 'member';
                    $report['positions_demoted'][] = "{$meta['name']}: {$p['name']} (팀장 중복)";
                }
                $hasLeader = true;
            } elseif ($p['position'] === 'head') {
                if (isset($heads[$p['departmentId']])) {
                    $p['position'] = 'member';
                    $report['positions_demoted'][] = "{$meta['name']}: {$p['name']} (부서장 중복)";
                }
                $heads[$p['departmentId']] = true;
            }
            $validPeople[$meta['id']][$p['id']] = true;
        }
        unset($p);

        // 관계: 없는 부서원을 가리키는 관계 제거 → 평가자별 평가로 변환
        $ids = $validPeople[$meta['id']] ?? [];
        $before = count($raw['relationships']);
        $raw['relationships'] = array_values(array_filter($raw['relationships'],
            fn($r) => is_array($r) && isset($ids[$r['a'] ?? ''], $ids[$r['b'] ?? ''])));
        $report['rel_orphans_removed'] += $before - count($raw['relationships']);
        foreach ($raw['relationships'] as $r) {
            if (empty($r['evals'])) {
                $report['relationships']++;
            }
        }
        $converted = migrate_relationship_evals($raw['relationships'], $meta['id']);
        foreach ($converted as $k => $r) {
            if (empty($raw['relationships'][$k]['evals'])) {
                isset($r['evals'][MGR_SLOT]) ? $report['rel_to_mgr']++ : $report['rel_to_party']++;
            }
        }
        $raw['relationships'] = $converted;
        $raw['schemaVersion'] = SCHEMA_VERSION;
        $raw['revision'] = (int)$raw['revision'] + 1;
        if (!$dry) {
            save_team($raw);
        }
    }

    // --- 고아 계정 (팀·부서원이 없는 부서원 계정): 보고만
    foreach ($usersDoc['users'] as $u) {
        if (is_array($u) && ($u['role'] ?? '') === 'member'
            && !isset($validPeople[$u['teamId'] ?? ''][$u['personId'] ?? ''])) {
            $report['orphan_accounts'][] = $u['loginId'];
        }
    }

    if (!$dry) {
        $log = read_json(data_path('migrations.json'), ['schemaVersion' => SCHEMA_VERSION, 'runs' => []]);
        $log['runs'][] = ['at' => now_iso(), 'appVersion' => APP_VERSION, 'report' => $report];
        write_json_atomic(data_path('migrations.json'), $log);
    }
};

$dry ? $run() : with_lock($run);

// ---------------------------------------------------------------------------
// 3) 보고
// ---------------------------------------------------------------------------
$r = $report;
say('');
say('== 결과 ' . ($dry ? '(미리 보기)' : ''));
say("계정: 필드 보완 {$r['users_fixed']}개" . ($r['users_invalid'] ? ', 확인 필요 ' . count($r['users_invalid']) . '개' : ''));
say("팀: {$r['teams']}개 (설정 보완 {$r['teams_fixed']}개)");
say("부서 색상 재배정: {$r['colors_reassigned']}개");
say("직급: 기본값(부서원) 지정 {$r['positions_defaulted']}명" . ($r['positions_demoted'] ? ', 중복 정리 ' . count($r['positions_demoted']) . '명' : ''));
say("관계 변환: {$r['relationships']}개 → 당사자 평가 {$r['rel_to_party']}개, 관리자 평가 {$r['rel_to_mgr']}개"
    . ($r['rel_orphans_removed'] ? ", 없는 부서원을 가리키는 관계 {$r['rel_orphans_removed']}개 제거" : ''));
foreach (['users_invalid' => '확인이 필요한 계정', 'orphan_accounts' => '팀·부서원이 없는 부서원 계정 (로그인 불가)',
    'positions_demoted' => '부서원으로 바뀐 중복 팀장/부서장', 'missing_team_files' => '팀 파일이 없는 팀'] as $k => $label) {
    if ($r[$k]) {
        say("- {$label}: " . implode(', ', $r[$k]));
    }
}
say($dry ? '미리 보기를 마쳤습니다. --dry-run 없이 실행하면 적용됩니다.' : '마이그레이션을 마쳤습니다. 기록: ' . data_path('migrations.json'));
