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

const PALETTE = [
    '#52C0A1', '#FF9A56', '#FF6B9D', '#C44569', '#6C63FF',
    '#5DADE2', '#F39C12', '#E74C3C', '#AF7AC5', '#48C9B0',
];
const STATUS_SCORES = ['private' => 1, 'public' => 0, 'social' => -1];
const ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';
const LOGIN_ID_PATTERN = '/^[a-z0-9_.-]{4,20}$/';

/**
 * 개인정보 안내문. 문구를 개정하면 PRIVACY_NOTICE_VERSION을 개정 시각으로 올린다.
 * consentedAt이 이 값보다 이전이면 재동의를 요구한다.
 */
const PRIVACY_NOTICE_VERSION = '2026-10-01T00:00:00+09:00';
const PRIVACY_NOTICE = [
    '이 서비스는 팀 내 관계를 시각화하기 위해 귀하가 입력한 관계 평가(사적·공적·사회적)를 수집합니다.',
    '입력한 관계는 두 사람 모두의 점수에 반영되며, 점수와 순위 산출에 사용됩니다.',
    '관리자는 모든 관계와 함께 입력자 및 수정 이력(누가, 언제 입력·수정했는지)을 열람할 수 있습니다.',
    '부서원은 본인 점수만 볼 수 있으며, 다른 사람의 점수와 순위는 관리자만 조회합니다.',
    '부서원 또는 팀이 삭제되면 관련 관계와 계정은 즉시 삭제됩니다. 백업은 14일, 활동 로그는 12개월간 보관 후 삭제됩니다.',
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
    foreach (['', '/teams', '/trash', '/audit'] as $sub) {
        $dir = data_dir() . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            umask($old);
            throw new ApiError(500, '데이터 폴더를 만들 수 없습니다.');
        }
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

function valid_color(mixed $c): string
{
    if (!is_string($c) || !in_array(strtoupper($c), PALETTE, true)) {
        throw new ApiError(400, '부서 색상은 팔레트 색상 중 하나여야 합니다.');
    }
    return strtoupper($c);
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
        $p = ['id' => new_id('p'), 'name' => $name, 'departmentId' => $deptIds[$i % count($deptIds)], 'createdAt' => $now];
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
            $roll = random_int(1, 100);
            $status = $roll <= 45 ? 'private' : ($roll <= 80 ? 'public' : 'social');
            [$x, $y] = strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
            $team['relationships'][] = [
                'id' => $id, 'a' => $x, 'b' => $y, 'status' => $status,
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
