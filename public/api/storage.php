<?php
/**
 * JSON 파일 저장소: 경로·잠금·원자적 쓰기·검증·점수 계산·활동 로그.
 * 웹 API(index.php)와 CLI 도구(tools/*.php)가 함께 사용한다.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
mb_internal_encoding('UTF-8');

// ---------------------------------------------------------------------------
// 설정 상수
// ---------------------------------------------------------------------------
/** 릴리스 버전 (CHANGELOG.md·Git 태그와 맞춤) */
const APP_VERSION = '1.2.2';
const SCHEMA_VERSION = 1;
const DEFAULT_MAX_MEMBERS = 30;
const SYSTEM_MAX_MEMBERS = 100;          // 팀 인원 한도의 시스템 상한
const SAMPLE_PEOPLE_COUNT = 20;
const SESSION_IDLE_SECONDS = 120 * 60;   // 세션 유휴 만료
const LOGIN_FAIL_WINDOW = 600;           // 10분 내
const LOGIN_FAIL_LIMIT = 5;              // 5회 연속 실패 시
const LOGIN_LOCK_SECONDS = 600;          // 10분 잠금
const REQUEST_MAX_BYTES = 64 * 1024;     // 일반 요청 본문 한도
const IMPORT_MAX_BYTES = 2 * 1024 * 1024; // 팀 가져오기 한도
const AUDIT_VIEW_LIMIT = 100;
const AUDIT_RETENTION_MONTHS = 12;       // 운영 정책에 맞게 조정
const BACKUP_KEEP_DAYS = 14;
const DEFAULT_ADMIN_LOGIN = 'admin';     // 관리자 계정이 하나도 없을 때 자동 생성
const DEFAULT_ADMIN_PASSWORD = 'admin';  // 운영 전에 헤더의 '비밀번호 변경'으로 바꿀 것

/**
 * 부서 색상 (파스텔 16색). 새 부서는 이 순서대로 아직 쓰지 않은 색을 받으므로,
 * 이웃한 색끼리 색상(hue)이 최대한 다르도록 섞어 두었다.
 */
const PALETTE = [
    '#8FD6C2', '#FFC49B', '#B5BEF5', '#FFDB8E', '#F8B4C8', '#93D8E0', '#F4A6A0', '#D8B4EC',
    '#A8DDB0', '#EDB0DB', '#A6CFF2', '#E2CBA8', '#CDE8A0', '#E8B79E', '#BFC8D6', '#EEE89A',
];
/** 1.0.0까지 쓰던 10색 — 이 색을 쓰는 부서는 새 색상표에서 겹치지 않는 색을 다시 배정받는다 */
const LEGACY_COLORS = ['#52C0A1', '#FF9A56', '#FF6B9D', '#C44569', '#6C63FF', '#5DADE2', '#F39C12', '#E74C3C', '#AF7AC5', '#48C9B0'];
const STATUS_SCORES = ['private' => 1, 'public' => 0, 'social' => -1];
/**
 * 관계 평가 슬롯: 한 쌍(A↔B)에 A의 평가, B의 평가, 관리자 평가(팀장·부서장·관리자가
 * 당사자가 아닐 때 입력)를 따로 저장한다. 쌍의 유효 상태 = 평가 중 가장 낮은 값.
 */
const MGR_SLOT = 'mgr';
/** 부서원 직급: 팀장 / 부서장 / 부서원 */
const POSITIONS = ['leader' => '팀장', 'head' => '부서장', 'member' => '부서원'];
/** 일괄 등록에서 받는 직급 표기 */
const POSITION_ALIASES = ['' => 'member', '팀장' => 'leader', '부서장' => 'head', '부서원' => 'member',
    'leader' => 'leader', 'head' => 'head', 'member' => 'member'];
/** 활동 로그 분류 (action 이름 또는 접두사) */
const AUDIT_CATEGORIES = [
    'login' => ['login', 'login_failed', 'login_locked', 'login_inactive', 'logout', 'password_change', 'consent'],
    'account' => ['account_'],
    'team' => ['team_'],
    'department' => ['department_'],
    'person' => ['person_'],
    'relationship' => ['relationship_'],
    'admin' => ['admin_'],
];

const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
const LOGIN_ID_PATTERN = '/^[a-z0-9_.-]{4,20}$/';

/**
 * 개인정보 안내문. 문구를 개정하면 PRIVACY_NOTICE_VERSION을 개정 시각으로 올린다.
 * consentedAt이 이 값보다 이전이면 재동의를 요구한다.
 */
const PRIVACY_NOTICE_VERSION = '2026-10-16T13:30:00+09:00';
const PRIVACY_NOTICE = [
    '이 서비스는 팀 내 관계를 시각화하기 위해 입력된 관계 평가(사적·공적·사회적)를 수집합니다.',
    '관계 평가는 입력한 사람별로 따로 저장됩니다. 부서원은 본인이 등록한 평가만 볼 수 있으며, 다른 사람이 입력한 평가는 본인이 포함되어도 보이지 않습니다.',
    '두 사람 사이의 평가가 서로 다르면 그중 낮은 값이 관계도와 두 사람의 점수에 반영됩니다. 관리자는 당사자가 아닌 관계에 별도의 관리자 평가를 입력할 수 있으며, 이 평가도 낮은 값 계산에 포함됩니다.',
    '팀장은 팀 전체, 부서장은 자기 부서원 사이의 모든 관계를 낮은 값으로 보고, 점수·순위를 볼 수 있습니다. 쌍마다 각자의 평가와 입력자, 변경 이력, 점수 추이는 관리자만 열람할 수 있습니다.',
    '부서원 또는 팀이 삭제되면 관련 관계·변경 이력과 계정은 즉시 삭제됩니다. 백업은 14일, 활동 로그는 12개월간 보관 후 삭제됩니다.',
    '평가 정보의 사용 목적 제한 등 세부 사항은 조직의 개인정보·인사 관련 정책을 따릅니다.',
];

class ApiError extends Exception
{
    public function __construct(public int $status, string $message, public ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}

// ---------------------------------------------------------------------------
// 경로
// ---------------------------------------------------------------------------
function data_dir(): string
{
    $env = $_SERVER['GCBA_DATA_DIR'] ?? getenv('GCBA_DATA_DIR');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }
    return dirname(__DIR__, 2) . '/data';
}

function data_path(string $rel): string
{
    return data_dir() . '/' . $rel;
}

function team_path(string $teamId): string
{
    assert_id($teamId, '팀 ID');
    return data_path('teams/' . $teamId . '.json');
}

function ensure_data_dirs(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $old = umask(0077);
    foreach (['', '/teams', '/trash', '/audit', '/history'] as $sub) {
        $dir = data_dir() . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            umask($old);
            throw new ApiError(500, '데이터 폴더를 만들 수 없습니다.');
        }
    }
    // data/가 웹에 노출된 배치에서도 Apache가 직접 접근을 막도록
    $ht = data_path('.htaccess');
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }
    $lock = data_path('.lock');
    if (!file_exists($lock)) {
        @touch($lock);
    }
    umask($old);
    $done = true;
}

// ---------------------------------------------------------------------------
// 잠금·읽기·쓰기
// ---------------------------------------------------------------------------
/**
 * data/.lock에 배타 잠금을 걸고 $fn을 실행한다. 읽기-수정-쓰기 전 구간을 보호한다.
 * 재진입 가능(이미 잠금을 가진 상태에서 다시 호출하면 그대로 실행).
 */
function with_lock(callable $fn): mixed
{
    static $depth = 0;
    ensure_data_dirs();
    if ($depth > 0) {
        return $fn();
    }
    $fp = fopen(data_path('.lock'), 'c');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        throw new ApiError(500, '저장소 잠금을 얻을 수 없습니다.');
    }
    $depth++;
    try {
        return $fn();
    } finally {
        $depth--;
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function read_json(string $path, ?array $default = null): ?array
{
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new ApiError(500, '데이터 파일을 읽을 수 없습니다.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        error_log('[gcba] JSON decode failed: ' . $path . ' (' . json_last_error_msg() . ')');
        throw new ApiError(500, '데이터 파일이 손상되었습니다. 관리자에게 문의하세요.');
    }
    return $data;
}

/** 임시 파일에 쓴 뒤 rename()으로 교체한다. 호출자는 with_lock 안에 있어야 한다. */
function write_json_atomic(string $path, array $data): void
{
    ensure_data_dirs();
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new ApiError(500, '데이터를 저장 형식으로 변환할 수 없습니다.');
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $old = umask(0077);
    $ok = @file_put_contents($tmp, $json . "\n") !== false;
    umask($old);
    if (!$ok || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new ApiError(500, '데이터를 저장할 수 없습니다.');
    }
}

// ---------------------------------------------------------------------------
// 파일별 접근
// ---------------------------------------------------------------------------
function load_users(): array
{
    return read_json(data_path('users.json'), ['schemaVersion' => SCHEMA_VERSION, 'users' => []]);
}

function save_users(array $doc): void
{
    write_json_atomic(data_path('users.json'), $doc);
}

function load_user_state(): array
{
    $doc = read_json(data_path('user_state.json'), null)
        ?? ['schemaVersion' => SCHEMA_VERSION, 'states' => [], 'loginFailures' => []];
    // 빈 배열이 JSON 배열([])로 저장·로드될 수 있으므로 정규화
    $doc['states'] = (array)($doc['states'] ?? []);
    $doc['loginFailures'] = (array)($doc['loginFailures'] ?? []);
    return $doc;
}

function save_user_state(array $doc): void
{
    $doc['states'] = (object)$doc['states'];
    $doc['loginFailures'] = (object)$doc['loginFailures'];
    write_json_atomic(data_path('user_state.json'), $doc);
}

/** user_state.json의 한 계정 항목을 잠금 안에서 수정한다. */
function update_user_state(string $userId, callable $fn): void
{
    with_lock(function () use ($userId, $fn) {
        $doc = load_user_state();
        $state = (array)($doc['states'][$userId] ?? []);
        $doc['states'][$userId] = $fn($state);
        save_user_state($doc);
    });
}

function load_teams(): array
{
    return read_json(data_path('teams.json'), ['schemaVersion' => SCHEMA_VERSION, 'teams' => []]);
}

function save_teams(array $doc): void
{
    write_json_atomic(data_path('teams.json'), $doc);
}

function find_team_meta(string $teamId): ?array
{
    foreach (load_teams()['teams'] as $t) {
        if ($t['id'] === $teamId) {
            return $t;
        }
    }
    return null;
}

function load_team(string $teamId): ?array
{
    $team = read_json(team_path($teamId), null);
    if ($team === null) {
        return null;
    }
    $team += ['departments' => [], 'people' => [], 'relationships' => [], 'revision' => 0];
    $team['departments'] = migrate_department_colors($team['departments']);
    $team['relationships'] = migrate_relationship_evals($team['relationships'], $teamId);
    return $team;
}

function save_team(array $team): void
{
    write_json_atomic(team_path($team['teamId']), $team);
}

/** 팀 파일을 잠금 안에서 읽어 $fn으로 수정하고 revision을 올려 저장한다. $fn의 반환값을 돌려준다. */
function mutate_team(string $teamId, callable $fn): mixed
{
    return with_lock(function () use ($teamId, $fn) {
        $team = load_team($teamId);
        if ($team === null || find_team_meta($teamId) === null) {
            throw new ApiError(404, '팀을 찾을 수 없습니다.');
        }
        $result = $fn($team);
        $team['revision'] = (int)$team['revision'] + 1;
        save_team($team);
        return $result;
    });
}

function new_team_document(string $teamId): array
{
    return [
        'schemaVersion' => SCHEMA_VERSION,
        'teamId' => $teamId,
        'revision' => 1,
        'departments' => [],
        'people' => [],
        'relationships' => [],
    ];
}

/**
 * 관리자 계정이 하나도 없으면 기본 관리자(admin / admin)를 만든다.
 * 첫 로그인 시 비밀번호 변경은 강제하지 않는다 (개인정보 안내 동의는 필요).
 */
function ensure_default_admin(): void
{
    $hasAdmin = fn(array $doc) => (bool)array_filter($doc['users'], fn($u) => $u['role'] === 'admin');
    if ($hasAdmin(load_users())) {
        return;
    }
    with_lock(function () use ($hasAdmin) {
        $doc = load_users();
        if ($hasAdmin($doc)) {
            return;
        }
        foreach ($doc['users'] as $u) {
            if (strtolower($u['loginId']) === DEFAULT_ADMIN_LOGIN) {
                error_log('[gcba] default admin not created: login ID "admin" is used by a member account');
                return;
            }
        }
        $doc['users'][] = [
            'id' => new_id('u'),
            'loginId' => DEFAULT_ADMIN_LOGIN,
            'passwordHash' => hash_password(DEFAULT_ADMIN_PASSWORD),
            'role' => 'admin',
            'teamId' => null,
            'personId' => null,
            'active' => true,
            'mustChangePassword' => false,
            'consentedAt' => null,
            'createdAt' => now_iso(),
        ];
        save_users($doc);
        audit(null, 'admin_create_default', null, DEFAULT_ADMIN_LOGIN, '기본 관리자 자동 생성');
    });
}

// ---------------------------------------------------------------------------
// ID·시각·문자열 검증
// ---------------------------------------------------------------------------
function now_iso(): string
{
    return date('c');
}

function new_id(string $prefix): string
{
    return $prefix . '-' . bin2hex(random_bytes(4));
}

function assert_id(mixed $id, string $label = 'ID'): string
{
    if (!is_string($id) || !preg_match(ID_PATTERN, $id)) {
        throw new ApiError(400, $label . ' 형식이 올바르지 않습니다.');
    }
    return $id;
}

/** 이름류 문자열: 앞뒤 공백 제거, 제어문자 금지, 길이 검사. */
function clean_name(mixed $value, string $label, int $max): string
{
    if (!is_string($value)) {
        throw new ApiError(400, $label . '을(를) 입력하세요.');
    }
    $v = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    $len = mb_strlen($v);
    if ($len < 1 || $len > $max) {
        throw new ApiError(400, "{$label}은(는) 1~{$max}자여야 합니다.");
    }
    if (preg_match('/[\x00-\x1F\x7F<>]/u', $v)) {
        throw new ApiError(400, $label . '에 사용할 수 없는 문자가 있습니다.');
    }
    return $v;
}

function same_name(string $a, string $b): bool
{
    return mb_strtolower($a) === mb_strtolower($b);
}

function clean_login_id(mixed $value): string
{
    if (!is_string($value)) {
        throw new ApiError(400, '로그인 ID를 입력하세요.');
    }
    $v = strtolower(trim($value));
    if (!preg_match(LOGIN_ID_PATTERN, $v)) {
        throw new ApiError(400, '로그인 ID는 4~20자의 영문 소문자·숫자·_ . - 만 사용할 수 있습니다.');
    }
    return $v;
}

function validate_password_policy(mixed $pw): string
{
    if (!is_string($pw) || strlen($pw) < 8 || strlen($pw) > 72
        || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
        throw new ApiError(400, '비밀번호는 8자 이상(최대 72자)이며 영문과 숫자를 모두 포함해야 합니다.');
    }
    return $pw;
}

function generate_temp_password(): string
{
    $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits = '23456789';
    $all = $letters . $digits;
    do {
        $pw = '';
        for ($i = 0; $i < 10; $i++) {
            $pw .= $all[random_int(0, strlen($all) - 1)];
        }
    } while (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw));
    return $pw;
}

function hash_password(string $pw): string
{
    return password_hash($pw, PASSWORD_DEFAULT);
}

function valid_status(mixed $s): string
{
    if (!is_string($s) || !array_key_exists($s, STATUS_SCORES)) {
        throw new ApiError(400, '관계 상태는 private / public / social 중 하나여야 합니다.');
    }
    return $s;
}

function valid_position(mixed $p): string
{
    if (!is_string($p) || !array_key_exists($p, POSITIONS)) {
        throw new ApiError(400, '직급은 leader(팀장) / head(부서장) / member(부서원) 중 하나여야 합니다.');
    }
    return $p;
}

function valid_color(mixed $c): string
{
    $c = is_string($c) ? strtoupper($c) : null;
    if ($c === null || !in_array($c, PALETTE, true)) {
        throw new ApiError(400, '부서 색상은 팔레트 색상 중 하나여야 합니다.');
    }
    return $c;
}

// ---------------------------------------------------------------------------
// 팀 데이터 헬퍼
// ---------------------------------------------------------------------------
function relationship_id(string $a, string $b): string
{
    return strcmp($a, $b) < 0 ? "{$a}_{$b}" : "{$b}_{$a}";
}

function find_index(array $list, string $id): int
{
    foreach ($list as $i => $item) {
        if (($item['id'] ?? null) === $id) {
            return $i;
        }
    }
    return -1;
}

/**
 * 색상표에 없는 색(1.0.0까지의 10색 등)을 쓰는 부서에 새 색상표의 색을 부서 순서대로 다시 배정한다.
 * 이미 새 색을 쓰는 부서는 그대로 두고, 겹치지 않는 색부터 고른다. (다음 저장 때 파일에도 반영됨)
 */
function migrate_department_colors(array $departments): array
{
    $kept = array_values(array_filter($departments, fn($d) => in_array(strtoupper((string)($d['color'] ?? '')), PALETTE, true)));
    foreach ($departments as &$d) {
        if (!in_array(strtoupper((string)($d['color'] ?? '')), PALETTE, true)) {
            $d['color'] = next_department_color($kept);
            $kept[] = $d;
        }
    }
    unset($d);
    return $departments;
}

function next_department_color(array $departments): string
{
    $used = array_map(fn($d) => strtoupper($d['color'] ?? ''), $departments);
    foreach (PALETTE as $c) {
        if (!in_array($c, $used, true)) {
            return $c;
        }
    }
    return PALETTE[count($departments) % count(PALETTE)];
}

function person_of(array $team, string $personId): ?array
{
    $i = find_index($team['people'], $personId);
    return $i >= 0 ? $team['people'][$i] : null;
}

function position_of(?array $person): string
{
    return $person['position'] ?? 'member';
}

// ---------------------------------------------------------------------------
// 관계 평가 (평가자별 저장, 유효 상태 = 가장 낮은 값)
// ---------------------------------------------------------------------------
/** 평가들 중 점수가 가장 낮은 상태 (없으면 null) */
function effective_status(array $evals): ?string
{
    $best = null;
    foreach ($evals as $e) {
        $st = $e['status'] ?? null;
        if (isset(STATUS_SCORES[$st]) && ($best === null || STATUS_SCORES[$st] < STATUS_SCORES[$best])) {
            $best = $st;
        }
    }
    return $best;
}

/** 관계의 슬롯 평가를 바꾸거나($status=null이면 삭제) 유효 상태를 다시 계산한다. */
function set_relationship_eval(array &$rel, string $slot, ?string $status, string $userId): void
{
    $rel['evals'] = (array)($rel['evals'] ?? []);
    if ($status === null) {
        unset($rel['evals'][$slot]);
    } else {
        $rel['evals'][$slot] = ['status' => $status, 'by' => $userId, 'at' => now_iso()];
    }
    $rel['status'] = effective_status($rel['evals']);
    $rel['updatedBy'] = $userId;
    $rel['updatedAt'] = now_iso();
}

/** 평가를 남길 슬롯: 본인이 당사자면 본인, 아니면 관리자 평가 */
function eval_slot(?string $actorPersonId, string $a, string $b): string
{
    return ($actorPersonId !== null && ($actorPersonId === $a || $actorPersonId === $b)) ? $actorPersonId : MGR_SLOT;
}

/**
 * 1.1.0 이하의 관계(평가 1개)를 평가 슬롯 구조로 변환한다.
 * 마지막 수정자가 당사자면 그 사람의 평가, 아니면 관리자 평가로 본다. (다음 저장 때 파일에도 반영)
 */
function migrate_relationship_evals(array $relationships, string $teamId): array
{
    $needs = false;
    foreach ($relationships as $r) {
        if (!isset($r['evals']) || !is_array($r['evals']) || !$r['evals']) {
            $needs = true;
            break;
        }
    }
    if (!$needs) {
        return $relationships;
    }
    $personOf = [];
    foreach (load_users()['users'] as $u) {
        if (($u['teamId'] ?? null) === $teamId) {
            $personOf[$u['id']] = $u['personId'];
        }
    }
    foreach ($relationships as &$r) {
        if (!isset($r['evals']) || !is_array($r['evals']) || !$r['evals']) {
            $slot = eval_slot($personOf[$r['updatedBy'] ?? ''] ?? null, $r['a'], $r['b']);
            $r['evals'] = [$slot => ['status' => $r['status'], 'by' => $r['updatedBy'] ?? null, 'at' => $r['updatedAt'] ?? now_iso()]];
        }
        $r['status'] = effective_status($r['evals']) ?? $r['status'];
    }
    unset($r);
    return $relationships;
}

/** 팀장은 팀 전체, 부서장·부서원은 자기 부서만 본다. */
function member_sees_whole_team(array $me): bool
{
    return position_of($me) === 'leader';
}

/** 부서원 계정에게 보이는 사람 ID 집합 (팀장: 전체 / 그 외: 자기 부서) */
function member_visible_people(array $team, array $me): array
{
    $ids = [];
    foreach ($team['people'] as $p) {
        if (member_sees_whole_team($me) || $p['departmentId'] === $me['departmentId']) {
            $ids[$p['id']] = true;
        }
    }
    return $ids;
}

/**
 * 관리자가 아닌 계정이 두 사람 사이 관계를 추가·수정·삭제할 수 있는지.
 * 직급과 관계없이 본인이 당사자인 관계만 (상대는 팀장: 팀 전체 / 부서장·부서원: 자기 부서).
 * 팀장·부서장은 범위 안의 다른 사람들 관계를 보기만 한다.
 */
function member_can_manage(array $team, array $me, string $a, string $b): bool
{
    // 관리자가 아니면(팀장·부서장 포함) 본인이 당사자인 관계만, 상대는 보이는 범위 안
    if ($a !== $me['id'] && $b !== $me['id']) {
        return false;
    }
    $visible = member_visible_people($team, $me);
    return isset($visible[$a], $visible[$b]);
}

/**
 * 관리자가 아닌 계정에게 보내는 관계 (두 당사자가 모두 보이는 범위에 있어야 함).
 * 팀장: 팀 전체 / 부서장: 자기 부서원 사이 — 모든 관계 (쌍의 가장 낮은 값으로 표시)
 * 부서원: 본인이 등록한 평가가 있는 관계만 (다른 사람이 입력한 평가는 본인이 포함돼도 보이지 않음)
 */
function member_visible_relationships(array $team, array $me): array
{
    $visible = member_visible_people($team, $me);
    $ownOnly = position_of($me) === 'member';
    return array_values(array_filter($team['relationships'], fn($r) =>
        isset($visible[$r['a']], $visible[$r['b']]) && (!$ownOnly || isset($r['evals'][$me['id']]))));
}

/**
 * 직급 인원 제한: 팀당 팀장 1명, 부서당 부서장 1명.
 * $people 안에서 $personId를 제외하고 같은 자리가 이미 있으면 409.
 */
function assert_position_available(array $people, string $position, string $departmentId, ?string $personId = null): void
{
    foreach ($people as $p) {
        if ($p['id'] === $personId) {
            continue;
        }
        $pos = position_of($p);
        if ($position === 'leader' && $pos === 'leader') {
            throw new ApiError(409, "이미 팀장({$p['name']})이 있습니다. 팀장은 팀당 1명이므로 먼저 기존 팀장의 직급을 바꾸세요.");
        }
        if ($position === 'head' && $pos === 'head' && $p['departmentId'] === $departmentId) {
            throw new ApiError(409, "이 부서에는 이미 부서장({$p['name']})이 있습니다. 부서장은 부서당 1명이므로 먼저 기존 부서장의 직급을 바꾸세요.");
        }
    }
}

// ---------------------------------------------------------------------------
// 관계 변경 이력 (data/history/{teamId}.jsonl) — 이력 조회·점수 추이에 사용
// ---------------------------------------------------------------------------
function history_path(string $teamId): string
{
    assert_id($teamId, '팀 ID');
    return data_path('history/' . $teamId . '.jsonl');
}

/** 이력 파일이 없으면 현재 관계를 기준선으로 기록한다 (기능 도입 전 데이터·가져온 팀). 잠금 안에서 호출. */
function history_ensure_baseline(array $team): void
{
    $path = history_path($team['teamId']);
    if (is_file($path)) {
        return;
    }
    $lines = '';
    foreach ($team['relationships'] as $r) {
        $lines .= json_encode([
            'at' => $r['updatedAt'] ?? $r['createdAt'] ?? now_iso(), 'rel' => $r['id'], 'a' => $r['a'], 'b' => $r['b'],
            'from' => null, 'to' => $r['status'], 'by' => $r['updatedBy'] ?? null, 'baseline' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
    $old = umask(0077);
    file_put_contents($path, $lines);
    umask($old);
}

/**
 * 이력 한 건 추가. from/to는 쌍의 유효 상태(가장 낮은 값, null이면 관계 없음),
 * slot/slotFrom/slotTo는 실제로 바뀐 평가(누구의 평가가 어떻게 바뀌었는지).
 */
function history_append(string $teamId, array $rel, ?string $from, ?string $to, string $userId,
    ?string $slot = null, ?string $slotFrom = null, ?string $slotTo = null): void
{
    $line = json_encode([
        'at' => now_iso(), 'rel' => $rel['id'], 'a' => $rel['a'], 'b' => $rel['b'],
        'from' => $from, 'to' => $to, 'by' => $userId,
        'slot' => $slot, 'slotFrom' => $slotFrom, 'slotTo' => $slotTo,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $old = umask(0077);
    file_put_contents(history_path($teamId), $line . "\n", FILE_APPEND | LOCK_EX);
    umask($old);
}

function history_read(string $teamId): array
{
    $path = history_path($teamId);
    if (!is_file($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

/** 삭제된 부서원이 포함된 이력 제거 (부서원 삭제 시 관련 데이터 즉시 삭제). 잠금 안에서 호출. */
function history_purge_people(string $teamId, array $personIds): void
{
    $path = history_path($teamId);
    if (!is_file($path)) {
        return;
    }
    $drop = array_flip($personIds);
    $keep = array_filter(history_read($teamId), fn($e) => !isset($drop[$e['a']]) && !isset($drop[$e['b']]));
    $lines = implode('', array_map(fn($e) => json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", $keep));
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $old = umask(0077);
    file_put_contents($tmp, $lines);
    umask($old);
    rename($tmp, $path);
}

/**
 * 월말 기준 점수 추이 (최근 $months개월). 현재 부서원만 대상, 이력을 순서대로 재생해 계산한다.
 * 반환: ['months' => ['2026-01', ...], 'scores' => [personId => [점수, ...]]]
 */
function score_trend(array $team, int $months = 12): array
{
    $events = history_read($team['teamId']);
    if (!$events) {
        $events = array_map(fn($r) => ['at' => $r['updatedAt'] ?? now_iso(), 'rel' => $r['id'], 'a' => $r['a'], 'b' => $r['b'], 'to' => $r['status']], $team['relationships']);
    }
    usort($events, fn($x, $y) => strcmp((string)$x['at'], (string)$y['at']));
    $labels = [];
    $ends = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $first = strtotime(date('Y-m-01') . " -{$i} months");
        $labels[] = date('Y-m', $first);
        $ends[] = strtotime(date('Y-m-t 23:59:59', $first));
    }
    $people = array_column($team['people'], 'id');
    $scores = array_fill_keys($people, []);
    $state = [];
    $k = 0;
    foreach ($ends as $end) {
        while ($k < count($events) && strtotime((string)$events[$k]['at']) <= $end) {
            $e = $events[$k++];
            if ($e['to'] === null) {
                unset($state[$e['rel']]);
            } else {
                $state[$e['rel']] = $e;
            }
        }
        $month = array_fill_keys($people, 0);
        foreach ($state as $e) {
            $v = STATUS_SCORES[$e['to']] ?? 0;
            if (isset($month[$e['a']])) {
                $month[$e['a']] += $v;
            }
            if (isset($month[$e['b']])) {
                $month[$e['b']] += $v;
            }
        }
        foreach ($month as $pid => $v) {
            $scores[$pid][] = $v;
        }
    }
    return ['months' => $labels, 'scores' => $scores];
}

/** 2.6 점수 계산: 본인이 포함된 관계 점수 값의 합. 저장하지 않고 항상 계산한다. */
function compute_scores(array $team): array
{
    $scores = [];
    foreach ($team['people'] as $p) {
        $scores[$p['id']] = 0;
    }
    foreach ($team['relationships'] as $r) {
        $v = STATUS_SCORES[$r['status']] ?? 0;
        if (isset($scores[$r['a']])) {
            $scores[$r['a']] += $v;
        }
        if (isset($scores[$r['b']])) {
            $scores[$r['b']] += $v;
        }
    }
    return $scores;
}

/** 존재하지 않는 부서원을 가리키는 관계 등 고아 데이터는 무시하고 오류 로그에 남긴다. */
function sanitize_team(array $team): array
{
    $peopleIds = array_flip(array_column($team['people'], 'id'));
    $deptIds = array_flip(array_column($team['departments'], 'id'));
    foreach ($team['people'] as $p) {
        if (!isset($deptIds[$p['departmentId'] ?? ''])) {
            error_log("[gcba] orphan person department: team={$team['teamId']} person={$p['id']}");
        }
    }
    $before = count($team['relationships']);
    $team['relationships'] = array_values(array_filter(
        $team['relationships'],
        fn($r) => isset($peopleIds[$r['a']], $peopleIds[$r['b']])
    ));
    if ($before !== count($team['relationships'])) {
        error_log("[gcba] orphan relationships ignored: team={$team['teamId']}");
    }
    return $team;
}

// ---------------------------------------------------------------------------
// 샘플 데이터
// ---------------------------------------------------------------------------
const SAMPLE_DEPARTMENTS = ['개발', '기획', '디자인', '영업'];
const SAMPLE_NAMES = [
    '김민수', '이지은', '박서준', '최유진', '정하늘', '강도윤', '조수아', '윤지호', '장예린', '임태현',
    '한소희', '오준영', '서다인', '신우진', '권나연', '황시우', '안채원', '송민재', '류하은', '전건우',
    '고은별', '문지훈', '양서연', '배현우', '백지민', '허윤서', '남궁민', '노가은', '하준서', '유다현',
];

/** 팀에 샘플 부서·부서원·관계를 한도 이내에서 추가한다. 추가된 인원 수를 반환한다. */
function seed_team(array &$team, int $maxMembers, string $userId): int
{
    $room = min(SAMPLE_PEOPLE_COUNT, $maxMembers - count($team['people']));
    if ($room <= 0) {
        return 0;
    }
    $deptIds = [];
    foreach (SAMPLE_DEPARTMENTS as $name) {
        $found = null;
        foreach ($team['departments'] as $d) {
            if (same_name($d['name'], $name)) {
                $found = $d['id'];
            }
        }
        if ($found === null) {
            $found = new_id('d');
            $team['departments'][] = ['id' => $found, 'name' => $name, 'color' => next_department_color($team['departments'])];
        }
        $deptIds[] = $found;
    }
    $existing = array_map(fn($p) => mb_strtolower($p['name']), $team['people']);
    $now = now_iso();
    $added = [];
    foreach (SAMPLE_NAMES as $i => $name) {
        if (count($added) >= $room) {
            break;
        }
        if (in_array(mb_strtolower($name), $existing, true)) {
            continue;
        }
        $p = ['id' => new_id('p'), 'name' => $name, 'departmentId' => $deptIds[$i % count($deptIds)], 'position' => 'member', 'createdAt' => $now];
        // 샘플: 첫 번째는 팀장, 각 부서의 첫 사람은 부서장
        if (!$team['people'] && !$added) {
            $p['position'] = 'leader';
        } elseif (!array_filter($team['people'], fn($x) => $x['departmentId'] === $p['departmentId'])) {
            $p['position'] = 'head';
        }
        $team['people'][] = $p;
        $added[] = $p['id'];
    }
    $existingRel = array_flip(array_column($team['relationships'], 'id'));
    $all = array_column($team['people'], 'id');
    foreach ($added as $a) {
        foreach ($all as $b) {
            if ($a === $b || random_int(1, 100) > 45) {
                continue;
            }
            $id = relationship_id($a, $b);
            if (isset($existingRel[$id])) {
                continue;
            }
            // 샘플: 두 사람이 각자 평가 (한쪽만 평가한 쌍도 섞음)
            $pick = function () {
                $roll = random_int(1, 100);
                return $roll <= 50 ? 'private' : ($roll <= 82 ? 'public' : 'social');
            };
            [$x, $y] = strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
            $who = random_int(1, 100);
            $evals = [];
            if ($who <= 75) {
                $evals[$x] = ['status' => $pick(), 'by' => $userId, 'at' => $now];
            }
            if ($who > 40) {
                $evals[$y] = ['status' => $pick(), 'by' => $userId, 'at' => $now];
            }
            $team['relationships'][] = [
                'id' => $id, 'a' => $x, 'b' => $y, 'status' => effective_status($evals), 'evals' => $evals,
                'createdBy' => $userId, 'createdAt' => $now, 'updatedBy' => $userId, 'updatedAt' => $now,
            ];
            $existingRel[$id] = true;
        }
    }
    return count($added);
}

// ---------------------------------------------------------------------------
// 활동 로그 (data/audit/YYYY-MM.log, JSON Lines)
// ---------------------------------------------------------------------------
function audit(?string $userId, string $action, ?string $teamId = null, ?string $target = null, string $summary = ''): void
{
    try {
        ensure_data_dirs();
        $line = json_encode([
            'at' => now_iso(),
            'userId' => $userId,
            'action' => $action,
            'teamId' => $teamId,
            'target' => $target,
            'summary' => $summary,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = data_path('audit/' . date('Y-m') . '.log');
        $old = umask(0077);
        file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        umask($old);
    } catch (Throwable $e) {
        error_log('[gcba] audit write failed: ' . $e->getMessage());
    }
}

/** 최근 활동 로그 $limit건 (최신순). */
function read_audit(int $limit = AUDIT_VIEW_LIMIT): array
{
    $files = glob(data_path('audit/*.log')) ?: [];
    rsort($files);
    $out = [];
    foreach ($files as $f) {
        $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $row = json_decode($lines[$i], true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/** 보관 기간이 지난 월별 로그 삭제. */
function prune_audit(int $months = AUDIT_RETENTION_MONTHS): int
{
    $cutoff = date('Y-m', strtotime("-{$months} months"));
    $n = 0;
    foreach (glob(data_path('audit/*.log')) ?: [] as $f) {
        if (basename($f, '.log') < $cutoff && @unlink($f)) {
            $n++;
        }
    }
    return $n;
}
