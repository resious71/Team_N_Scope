/* Team Scope — 클라이언트 로직 (모든 데이터는 PHP API를 통해서만 읽고 씀, 브라우저 저장소 미사용) */
'use strict';

// ---------------------------------------------------------------------------
// 상수
// ---------------------------------------------------------------------------
const CHART_SIZE = 800;
const CHART_RADIUS = 290;
const CURVATURE = 0.25;          // 곡선 제어점 이동량 = 현 길이 × 곡률 계수
const OPPOSITE_TOLERANCE = 0.5;  // 정반대 판정 허용 오차(도)
const POLL_INTERVAL = 30000;

const STATUS = {
  private: { label: '사적', meaning: '긍정', score: 1, text: '사적 (긍정 +1)' },
  public: { label: '공적', meaning: '중립', score: 0, text: '공적 (중립 0)' },
  social: { label: '사회적', meaning: '부정', score: -1, text: '사회적 (부정 -1)' },
};
const STATUS_KEYS = Object.keys(STATUS);
const POSITIONS = { leader: '팀장', head: '부서장', member: '부서원' };
const VIEW_SCOPES = { team: '팀', dept: '부서', person: '부서원' };
/** 수정자 구분 (부서원 화면): 이름 대신 역할만 표시 */
const UPDATED_BY_LABELS = { self: '본인', leader: '팀장', head: '부서장', admin: '관리자', member: '부서원', unknown: '알 수 없음' };
/** 점수 추이 계열 색 (고정 순서, 검증된 범주형 팔레트 앞 5칸) */
const SERIES_COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4'];

// ---- 아이콘 (stroke 24px, JS로 SVG 생성: <base href>에서도 깨지지 않도록 <use> 대신 인라인) ----
const ICONS = {
  network: [['circle', { cx: 18, cy: 5, r: 3 }], ['circle', { cx: 6, cy: 12, r: 3 }], ['circle', { cx: 18, cy: 19, r: 3 }], ['path', { d: 'm8.6 13.5 6.8 4M15.4 6.5l-6.8 4' }]],
  shield: [['path', { d: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z' }]],
  shieldCheck: [['path', { d: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z' }], ['path', { d: 'm9 12 2 2 4-4' }]],
  key: [['circle', { cx: 7.5, cy: 15.5, r: 5.5 }], ['path', { d: 'm21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3' }]],
  logout: [['path', { d: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9' }]],
  users: [['path', { d: 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2' }], ['circle', { cx: 9, cy: 7, r: 4 }], ['path', { d: 'M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75' }]],
  user: [['path', { d: 'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2' }], ['circle', { cx: 12, cy: 7, r: 4 }]],
  layers: [['path', { d: 'm12 2 10 5-10 5L2 7zM2 17l10 5 10-5M2 12l10 5 10-5' }]],
  link: [['path', { d: 'M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71' }]],
  trending: [['path', { d: 'm22 7-8.5 8.5-5-5L2 17M16 7h6v6' }]],
  list: [['rect', { x: 8, y: 2, width: 8, height: 4, rx: 1 }], ['path', { d: 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M12 11h4M12 16h4M8 11h.01M8 16h.01' }]],
  trophy: [['path', { d: 'M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22M18 2H6v7a6 6 0 0 0 12 0V2Z' }]],
  info: [['circle', { cx: 12, cy: 12, r: 10 }], ['path', { d: 'M12 16v-4M12 8h.01' }]],
  bar: [['path', { d: 'M3 3v18h18M18 17V9M13 17V5M8 17v-3' }]],
  upload: [['path', { d: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12' }]],
  download: [['path', { d: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3' }]],
  image: [['rect', { x: 3, y: 3, width: 18, height: 18, rx: 2 }], ['circle', { cx: 9, cy: 9, r: 2 }], ['path', { d: 'm21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21' }]],
  file: [['path', { d: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M16 13H8M16 17H8' }]],
  history: [['circle', { cx: 12, cy: 12, r: 10 }], ['path', { d: 'M12 6v6l4 2' }]],
  x: [['path', { d: 'M18 6 6 18M6 6l12 12' }]],
};

function iconSvg(name) {
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  for (const [k, v] of Object.entries({ viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'aria-hidden': 'true' })) svg.setAttribute(k, v);
  for (const [tag, attrs] of ICONS[name] || []) {
    const el = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
    svg.append(el);
  }
  return svg;
}

/** data-icon 자리 표시자에 아이콘을 채운다 */
function renderIcons(root = document) {
  for (const el of root.querySelectorAll('[data-icon]')) {
    if (!el.firstChild) el.append(iconSvg(el.dataset.icon));
  }
}

function icon(name) {
  const span = h('span', { class: 'icon', 'aria-hidden': 'true' });
  span.append(iconSvg(name));
  return span;
}

const AUDIT_LABELS = {
  login: '로그인', login_failed: '로그인 실패', login_locked: '잠금 상태 로그인 시도', login_inactive: '비활성 계정 로그인 시도',
  logout: '로그아웃', password_change: '비밀번호 변경', consent: '개인정보 동의',
  team_create: '팀 생성', team_update: '팀 수정', team_delete: '팀 삭제', team_seed: '샘플 데이터', team_export: '팀 내보내기', team_import: '팀 가져오기',
  department_create: '부서 추가', department_update: '부서 수정', department_delete: '부서 삭제',
  person_create: '부서원 추가', person_update: '부서원 수정', person_delete: '부서원 삭제',
  account_create: '계정 발급', account_reset_password: '비밀번호 초기화', account_activate: '계정 활성화', account_deactivate: '계정 비활성화', account_unlock: '잠금 해제',
  relationship_create: '관계 추가', relationship_update: '관계 수정', relationship_delete: '관계 삭제',
  person_bulk_create: '부서원 일괄 등록',
  admin_create: '관리자 추가', admin_reset_password: '관리자 비밀번호 초기화', admin_activate: '관리자 활성화',
  admin_deactivate: '관리자 비활성화', admin_unlock: '관리자 잠금 해제',
  admin_create_default: '기본 관리자 생성', admin_create_cli: '관리자 생성(CLI)', admin_reset_password_cli: '관리자 비밀번호 초기화(CLI)', admin_unlock_cli: '관리자 잠금 해제(CLI)',
};

// ---------------------------------------------------------------------------
// 상태
// ---------------------------------------------------------------------------
const S = {
  me: null,
  csrf: '',
  palette: [],
  privacy: null,
  teams: [],
  teamId: null,
  data: null,
  pollTimer: null,
  busy: 0,
  tab: 'diagram',
  adminSub: 'teams',
  zoom: 100,
  statusFilter: { private: true, public: true, social: true },
  searchText: '',
  myRelFilter: '',
  adminRelFilter: '',
  infoDeptId: null,
  mySelected: new Set(),
  deptSelected: new Set(),
  personSelected: new Set(),
  relSelected: new Set(),
  deptEditColor: null,
  view: null,        // 관계도 표시 범위 { scope: team|dept|person, deptId, personId }
};

const $ = (id) => document.getElementById(id);
const isAdmin = () => S.me && S.me.role === 'admin';

// ---------------------------------------------------------------------------
// DOM 헬퍼 (사용자 입력은 항상 textContent로 출력)
// ---------------------------------------------------------------------------
function h(tag, props = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'text') node.textContent = v;
    else if (k === 'class') node.className = v;
    else if (k === 'dataset') Object.assign(node.dataset, v);
    else if (k === 'style') Object.assign(node.style, v);
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else if (k in node && typeof v !== 'string') node[k] = v;
    else node.setAttribute(k, v === true ? '' : v);
  }
  for (const c of [].concat(children)) {
    if (c === null || c === undefined || c === false) continue;
    node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return node;
}

function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
  return node;
}

function fmtDate(iso) {
  if (!iso) return '-';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** 색을 흰색과 섞어 파스텔 톤으로 (amount: 흰색 비율 0~1) */
function pastel(hex, amount = 0.5) {
  const n = parseInt(hex.slice(1), 16);
  const mix = (c) => Math.round(c + (255 - c) * amount);
  const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(mix);
  return `rgb(${r}, ${g}, ${b})`;
}

function fmtScore(n) {
  return n > 0 ? `+${n}` : String(n);
}

const collator = new Intl.Collator('ko');

let toastTimer = null;
function toast(msg, isError = false) {
  const t = $('toast');
  t.textContent = msg;
  t.classList.toggle('error', isError);
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.hidden = true; }, isError ? 5000 : 2500);
}

function setSaveStatus(text, isError = false) {
  const el = $('save-status');
  el.textContent = text;
  el.classList.toggle('error', isError);
}

// ---------------------------------------------------------------------------
// API
// ---------------------------------------------------------------------------
class ApiError extends Error {
  constructor(status, message, code) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

async function api(action, { method = 'GET', params = {}, body = null } = {}) {
  const url = new URL('api/index.php', document.baseURI);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null) url.searchParams.set(k, v);
  }
  const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (method === 'POST') {
    opts.headers['Content-Type'] = 'application/json';
    opts.headers['X-CSRF-Token'] = S.csrf || '';
    opts.body = JSON.stringify(body || {});
  }
  let res;
  try {
    res = await fetch(url, opts);
  } catch (e) {
    throw new ApiError(0, '서버에 연결할 수 없습니다. 네트워크를 확인하세요.');
  }
  let json = null;
  try {
    json = await res.json();
  } catch (e) {
    throw new ApiError(res.status, '서버 응답을 해석할 수 없습니다.');
  }
  if (!json.ok) {
    const err = new ApiError(res.status, json.error || '요청을 처리하지 못했습니다.', json.code);
    if (res.status === 401 && action !== 'login' && action !== 'me') onSessionExpired(err.message);
    if (res.status === 403 && (err.code === 'PASSWORD_CHANGE_REQUIRED' || err.code === 'CONSENT_REQUIRED')) refreshMe();
    throw err;
  }
  return json.data;
}

/** 팀 범위 요청에 teamId를 붙인다 (부서원은 서버가 무시하고 세션의 소속 팀을 사용). */
function teamBody(extra = {}) {
  return { teamId: S.teamId, ...extra };
}

/** 변경 요청 실행: 저장 중 표시 → 서버 응답 후 데이터 재조회. 실패 시 알림, 409/404는 재조회. */
async function mutate(action, body, { success, reload = true } = {}) {
  S.busy++;
  setSaveStatus('저장 중…');
  try {
    const data = await api(action, { method: 'POST', body });
    setSaveStatus('저장됨');
    if (success) toast(success);
    if (reload) await loadTeamData();
    return data;
  } catch (e) {
    setSaveStatus('저장 실패', true);
    if (e.status !== 401) toast(e.message, true);
    if (e.status === 409 || e.status === 404) await loadTeamData().catch(() => {});
    throw e;
  } finally {
    S.busy--;
    setTimeout(() => { if (!S.busy) setSaveStatus(''); }, 2500);
  }
}

/** 이벤트 핸들러용: 오류는 이미 알림으로 표시되었으므로 삼킨다. */
const safe = (fn) => async (...args) => {
  try {
    await fn(...args);
  } catch (e) {
    if (!(e instanceof ApiError)) {
      console.error(e);
      toast('처리 중 오류가 발생했습니다.', true);
    }
  }
};

// ---------------------------------------------------------------------------
// 화면 전환
// ---------------------------------------------------------------------------
const VIEWS = ['view-loading', 'view-login', 'view-password', 'view-consent', 'view-app'];
function showView(id) {
  for (const v of VIEWS) $(v).hidden = v !== id;
}

function applyMe(data) {
  S.me = data.user;
  S.csrf = data.csrfToken;
  S.palette = data.palette || S.palette;
  S.privacy = data.privacyNotice || S.privacy;
  S.limits = data.limits || S.limits;
  if (data.team) S.memberTeam = data.team;
  if ('lastTeamId' in data) S.lastTeamId = data.lastTeamId;
}

async function refreshMe() {
  try {
    applyMe(await api('me'));
    route();
  } catch (e) { /* 401은 onSessionExpired에서 처리 */ }
}

function route() {
  if (!S.me) return showLogin();
  if (S.me.mustChangePassword) return showPasswordView(true);
  if (S.me.needsConsent) return showConsent();
  return showApp();
}

function onSessionExpired(message) {
  stopPolling();
  S.me = null;
  S.data = null;
  showLogin(message || '세션이 만료되었습니다. 다시 로그인하세요.');
}

function showLogin(message = '') {
  stopPolling();
  showView('view-login');
  $('login-error').textContent = message;
  $('login-password').value = '';
  $('login-id').focus();
}

function showPasswordView(forced) {
  showView('view-password');
  $('password-notice').textContent = forced
    ? '임시 비밀번호로 로그인했습니다. 비밀번호를 변경해야 서비스를 이용할 수 있습니다.'
    : '새 비밀번호를 입력하세요.';
  $('password-cancel').hidden = forced;
  $('password-logout').hidden = !forced;
  $('password-username').value = S.me ? S.me.loginId : '';
  for (const id of ['pw-current', 'pw-new', 'pw-confirm']) $(id).value = '';
  $('password-error').textContent = '';
  $('pw-current').focus();
}

function showConsent() {
  showView('view-consent');
  const box = clear($('consent-text'));
  const paragraphs = (S.privacy && S.privacy.paragraphs) || [];
  box.append(h('ul', {}, paragraphs.map((p) => h('li', { text: p }))));
  if (S.privacy) box.append(h('p', { class: 'hint', text: `안내문 개정일: ${fmtDate(S.privacy.version)}` }));
  $('consent-check').checked = false;
  $('consent-submit').disabled = true;
  $('consent-error').textContent = '';
}

async function showApp() {
  showView('view-app');
  $('user-label').textContent = `${S.me.name} (${isAdmin() ? '관리자' : '부서원'})`;
  $('tab-btn-admin').hidden = !isAdmin();
  $('team-select').hidden = !isAdmin();
  $('team-name-fixed').hidden = isAdmin();
  $('panel-ranking').hidden = true;
  $('panel-my-relations').hidden = false;
  $('my-score').hidden = isAdmin();
  const side = document.querySelector('.side-column');
  side.classList.toggle('admin', false);
  side.classList.toggle('member', true);
  if (!isAdmin()) switchTab('diagram');

  if (isAdmin()) {
    await loadTeams(S.lastTeamId);
  } else {
    S.teamId = S.me.teamId;
    $('team-name-fixed').textContent = S.memberTeam ? S.memberTeam.name : '';
  }
  await loadTeamData();
  startPolling();
}

// ---------------------------------------------------------------------------
// 데이터 로드·갱신
// ---------------------------------------------------------------------------
async function loadTeams(preferId) {
  const res = await api('teams');
  S.teams = res.teams;
  const want = preferId || S.teamId || res.lastTeamId;
  S.teamId = S.teams.some((t) => t.id === want) ? want : (S.teams[0] ? S.teams[0].id : null);
  renderTeamSelect();
}

async function loadTeamData() {
  if (!S.teamId) {
    S.data = null;
    renderAll();
    return;
  }
  try {
    S.data = await api('team_data', { params: { teamId: S.teamId } });
  } catch (e) {
    if (e.status === 404 && isAdmin()) {
      await loadTeams();
      return loadTeamData();
    }
    if (e.status !== 401) toast(e.message, true);
    throw e;
  }
  if (isAdmin()) {
    // 팀 목록의 인원 수·이름도 최신화
    const t = S.teams.find((x) => x.id === S.teamId);
    if (t) {
      Object.assign(t, S.data.team, { memberCount: S.data.people.length });
      renderTeamSelect();
    }
  }
  renderAll();
}

function startPolling() {
  stopPolling();
  S.pollTimer = setInterval(checkRevision, POLL_INTERVAL);
}

function stopPolling() {
  if (S.pollTimer) clearInterval(S.pollTimer);
  S.pollTimer = null;
}

async function checkRevision() {
  if (!S.me || !S.teamId || document.hidden || S.busy) return;
  try {
    const { revision } = await api('revision', { params: { teamId: S.teamId } });
    if (!S.data || revision !== S.data.revision) await loadTeamData();
  } catch (e) {
    if (e.status === 404 && isAdmin()) {
      await loadTeams().catch(() => {});
      await loadTeamData().catch(() => {});
    }
  }
}

document.addEventListener('visibilitychange', () => {
  if (!document.hidden && $('view-app').hidden === false) checkRevision();
});

// ---------------------------------------------------------------------------
// 파생 데이터
// ---------------------------------------------------------------------------
function deptById(id) {
  return S.data ? S.data.departments.find((d) => d.id === id) : null;
}

function personById(id) {
  return S.data ? S.data.people.find((p) => p.id === id) : null;
}

function personName(id) {
  const p = personById(id);
  return p ? p.name : '(알 수 없음)';
}

function deptColor(id) {
  const d = deptById(id);
  return d ? d.color : '#9CA3AF';
}

/** 같은 부서끼리 이웃하도록 부서 순서 → 이름순으로 정렬 */
function orderedPeople() {
  if (!S.data) return [];
  const order = new Map(S.data.departments.map((d, i) => [d.id, i]));
  return [...S.data.people].sort((a, b) =>
    (order.get(a.departmentId) ?? 999) - (order.get(b.departmentId) ?? 999) || collator.compare(a.name, b.name));
}

function personLabel(p) {
  const d = deptById(p.departmentId);
  return `${p.name} (${d ? d.name : '부서 없음'})`;
}

function myPersonId() {
  return S.me && S.me.personId;
}

function otherOf(rel, me) {
  return rel.a === me ? rel.b : rel.a;
}

function myRelationships() {
  const me = myPersonId();
  if (!S.data || !me) return [];
  return S.data.relationships.filter((r) => r.a === me || r.b === me);
}

function matchIds(query) {
  const q = query.trim().toLowerCase();
  if (!q || !S.data) return [];
  return S.data.people.filter((p) => p.name.toLowerCase().includes(q)).map((p) => p.id);
}

function computeRanking(people) {
  const byDesc = [...people].sort((a, b) => b.score - a.score || collator.compare(a.name, b.name));
  const top = byDesc.slice(0, 3);
  const topIds = new Set(top.map((p) => p.id));
  let pool = [...people].sort((a, b) => a.score - b.score || collator.compare(a.name, b.name));
  if (people.length < 6) pool = pool.filter((p) => !topIds.has(p.id));
  return { top, bottom: pool.slice(0, 3) };
}

// ---------------------------------------------------------------------------
// 렌더링
// ---------------------------------------------------------------------------
// ---- 직급·권한 (서버도 같은 규칙으로 검사) -------------------------------------
function myPosition() {
  if (isAdmin()) return 'admin';
  return (S.data && S.data.me && S.data.me.position) || 'member';
}

function myDeptId() {
  return S.data && S.data.me ? S.data.me.departmentId : null;
}

/** 관리자·팀장은 팀 전체를, 부서장·부서원은 자기 부서만 볼 수 있다. */
function canViewWholeTeam() {
  return ['admin', 'leader'].includes(myPosition());
}

function allowedDepts() {
  if (!S.data) return [];
  return canViewWholeTeam() ? S.data.departments : S.data.departments.filter((d) => d.id === myDeptId());
}

function allowedViewPeople() {
  return orderedPeople().filter((p) => canViewWholeTeam() || p.departmentId === myDeptId());
}

/**
 * 팀장: 팀 전체 / 부서장: 두 사람 모두 자기 부서원 / 부서원: 본인과 같은 부서원 사이 관계.
 * (부서장·부서원에게는 서버가 자기 부서 사람만 보내므로 "보이는 사람"이 곧 자기 부서원)
 */
function canManagePair(a, b) {
  const pos = myPosition();
  if (pos === 'admin' || pos === 'leader') return true;
  if (!personById(a) || !personById(b)) return false;
  return pos === 'head' || a === myPersonId() || b === myPersonId();
}

/** 팀장·부서장·관리자는 (보이는 범위의) 점수와 순위를 본다 */
function canSeeScores() {
  return ['admin', 'leader', 'head'].includes(myPosition());
}

/** 관계 관리 카드에서 "당사자"로 고를 수 있는 사람 */
function partyACandidates() {
  const pos = myPosition();
  const people = orderedPeople();
  if (pos === 'admin' || pos === 'leader' || pos === 'head') return people;
  return people.filter((p) => p.id === myPersonId());
}

// ---- 관계도 표시 범위 필터 (부서원 정보 카드의 부서 선택과 동기화) ----------------
function allowedScopes() {
  return canViewWholeTeam() ? ['team', 'dept', 'person'] : ['dept', 'person'];
}

function normalizeView() {
  if (!S.data) return;
  const v = S.view || {};
  const scopes = allowedScopes();
  if (!scopes.includes(v.scope)) v.scope = scopes[0];
  const depts = allowedDepts();
  if (!depts.some((d) => d.id === v.deptId)) {
    v.deptId = depts.some((d) => d.id === S.infoDeptId) ? S.infoDeptId : (depts[0] ? depts[0].id : null);
  }
  const people = allowedViewPeople();
  if (!people.some((p) => p.id === v.personId)) {
    const me = people.find((p) => p.id === myPersonId());
    v.personId = me ? me.id : (people[0] ? people[0].id : null);
  }
  S.view = v;
  // 부서원 정보 카드와 동기화
  if (v.scope === 'dept') S.infoDeptId = v.deptId;
  if (v.scope === 'person' && personById(v.personId)) S.infoDeptId = personById(v.personId).departmentId;
  if (!depts.some((d) => d.id === S.infoDeptId)) S.infoDeptId = v.deptId;
}

/** 선택한 범위에 보일 노드·엣지 */
function visibleGraph() {
  const v = S.view;
  const all = orderedPeople();
  const rels = S.data.relationships;
  if (!v || v.scope === 'team') return { people: all, rels };
  if (v.scope === 'dept') {
    const ids = new Set(all.filter((p) => p.departmentId === v.deptId).map((p) => p.id));
    return { people: all.filter((p) => ids.has(p.id)), rels: rels.filter((r) => ids.has(r.a) && ids.has(r.b)) };
  }
  const mine = rels.filter((r) => r.a === v.personId || r.b === v.personId);
  const ids = new Set([v.personId, ...mine.map((r) => otherOf(r, v.personId))]);
  return { people: all.filter((p) => ids.has(p.id)), rels: mine };
}

function renderViewFilter() {
  const v = S.view;
  const scopeSel = clear($('view-scope'));
  for (const k of allowedScopes()) scopeSel.append(h('option', { value: k, text: VIEW_SCOPES[k] }));
  scopeSel.value = v ? v.scope : '';
  const deptSel = clear($('view-dept'));
  for (const d of allowedDepts()) deptSel.append(h('option', { value: d.id, text: d.name }));
  deptSel.value = v && v.deptId ? v.deptId : '';
  deptSel.hidden = !v || v.scope !== 'dept';
  const personSel = clear($('view-person'));
  for (const p of allowedViewPeople()) personSel.append(h('option', { value: p.id, text: personLabel(p) }));
  personSel.value = v && v.personId ? v.personId : '';
  personSel.hidden = !v || v.scope !== 'person';
}

function onViewChange() {
  normalizeView();
  renderViewFilter();
  renderChart();
  renderDeptInfo();
}

/** 좌우로 나란히 있을 때 오른쪽 열의 아래를 왼쪽 마지막 카드(관계 상태)의 아래와 맞춘다. */
function alignSideColumn() {
  const left = document.querySelector('.chart-column');
  const side = document.querySelector('.side-column');
  const sideBySide = left.offsetHeight > 0 && Math.abs(left.offsetTop - side.offsetTop) < 2;
  side.classList.toggle('aligned', sideBySide);
  side.style.height = sideBySide ? `${left.offsetHeight}px` : '';
  // 부서 통계 카드 = 관계 상태 카드 높이 (위·아래 줄 맞춤)
  const legend = document.querySelector('.legend-card');
  document.querySelector('.panel-stats').style.height = sideBySide ? `${legend.offsetHeight}px` : '';
}

function renderAll() {
  const noTeam = !S.teamId;
  $('no-team-guide').hidden = !(noTeam && isAdmin());
  $('diagram-layout').hidden = noTeam;
  pruneSelections();
  if (S.data && !isAdmin()) {
    const pos = myPosition();
    $('user-label').textContent = `${S.me.name} (${POSITIONS[pos] || '부서원'})`;
  }
  normalizeView();
  renderViewFilter();
  renderChart();
  renderLegend();
  renderSidePanels();
  if (isAdmin()) renderAdmin();
  alignSideColumn();
}

function pruneSelections() {
  const keep = (set, ids) => { for (const id of [...set]) if (!ids.has(id)) set.delete(id); };
  const d = S.data;
  keep(S.mySelected, new Set(d ? d.relationships.map((r) => r.id) : []));
  keep(S.relSelected, new Set(d ? d.relationships.map((r) => r.id) : []));
  keep(S.deptSelected, new Set(d ? d.departments.map((x) => x.id) : []));
  keep(S.personSelected, new Set(d ? d.people.map((x) => x.id) : []));
}

function renderTeamSelect() {
  const sel = clear($('team-select'));
  if (!S.teams.length) sel.append(h('option', { value: '', text: '팀 없음' }));
  for (const t of S.teams) sel.append(h('option', { value: t.id, text: t.name }));
  sel.value = S.teamId || '';
}

// ---- 관계도 (D3) ----------------------------------------------------------
/** 두 노드 각도(도)가 정반대(180° ± 허용 오차)인지 */
function isOpposite(angleA, angleB) {
  let diff = Math.abs(angleA - angleB) % 360;
  if (diff > 180) diff = 360 - diff;
  return Math.abs(diff - 180) <= OPPOSITE_TOLERANCE;
}

function polar(angleDeg, r) {
  const rad = ((angleDeg - 90) * Math.PI) / 180;
  return [r * Math.cos(rad), r * Math.sin(rad)];
}

/** 정반대면 직선, 그 외는 현의 중점에서 원 중심 방향으로 (현 길이 × 곡률 계수)만큼 이동한 제어점의 2차 베지어 곡선 */
function edgePath(pa, pb) {
  const [x1, y1] = pa.xy;
  const [x2, y2] = pb.xy;
  if (isOpposite(pa.angle, pb.angle)) return `M${x1},${y1}L${x2},${y2}`;
  const mx = (x1 + x2) / 2;
  const my = (y1 + y2) / 2;
  const chord = Math.hypot(x2 - x1, y2 - y1);
  const dist = Math.hypot(mx, my) || 1;
  const k = (chord * CURVATURE) / dist;
  const cx = mx - mx * k;
  const cy = my - my * k;
  return `M${x1},${y1}Q${cx},${cy} ${x2},${y2}`;
}

function layoutNodes(people) {
  const root = d3.hierarchy({ children: people });
  d3.cluster().size([360, CHART_RADIUS]).separation(() => 1)(root);
  const pos = new Map();
  for (const leaf of root.leaves()) {
    pos.set(leaf.data.id, { person: leaf.data, angle: leaf.x, xy: polar(leaf.x, CHART_RADIUS) });
  }
  return pos;
}

function renderChart() {
  const svg = d3.select('#chart');
  svg.selectAll('*').remove();
  applyZoom();
  const empty = $('chart-empty');
  const graph = S.data ? visibleGraph() : { people: [], rels: [] };
  const people = graph.people;
  if (!S.data || !people.length) {
    const noOne = !S.data || !S.data.people.length;
    empty.textContent = !noOne
      ? '선택한 범위에 표시할 부서원이 없습니다.'
      : isAdmin()
        ? '등록된 부서원이 없습니다. 관리자 탭의 부서원 관리에서 부서원을 추가하세요.'
        : '등록된 부서원이 없습니다.';
    empty.hidden = !S.data;
    return;
  }
  empty.hidden = true;
  const half = CHART_SIZE / 2;
  svg.attr('viewBox', `${-half} ${-half} ${CHART_SIZE} ${CHART_SIZE}`);

  // 노드가 놓이는 큰 원 (엣지·노드보다 뒤에 그림)
  svg.append('circle').attr('class', 'ring').attr('r', CHART_RADIUS);

  const pos = layoutNodes(people);
  const me = myPersonId();
  const rels = graph.rels.filter((r) => S.statusFilter[r.status] && pos.has(r.a) && pos.has(r.b));

  const edges = svg.append('g').attr('class', 'edges')
    .selectAll('g').data(rels, (r) => r.id).join('g')
    .attr('class', (r) => `edge ${r.status}`)
    .attr('data-a', (r) => r.a)
    .attr('data-b', (r) => r.b);
  edges.append('path').attr('class', 'line').attr('d', (r) => edgePath(pos.get(r.a), pos.get(r.b)));
  edges.append('path').attr('class', 'hit').attr('d', (r) => edgePath(pos.get(r.a), pos.get(r.b)))
    .on('mouseenter', function (event, r) {
      d3.select(this.parentNode).classed('hover', true);
      svg.selectAll('.node').filter((n) => n.id === r.a || n.id === r.b).classed('hover', true);
      showTooltip(event, `${personName(r.a)} ↔ ${personName(r.b)} · ${STATUS[r.status].text}`);
    })
    .on('mousemove', moveTooltip)
    .on('mouseleave', function () {
      d3.select(this.parentNode).classed('hover', false);
      svg.selectAll('.node').classed('hover', false);
      hideTooltip();
    });

  const nodes = svg.append('g').attr('class', 'nodes')
    .selectAll('g').data(people, (p) => p.id).join('g')
    .attr('class', (p) => `node${p.id === me ? ' me' : ''}`)
    .attr('transform', (p) => `rotate(${pos.get(p.id).angle - 90}) translate(${CHART_RADIUS},0)`)
    .on('mouseenter', (event, p) => {
      svg.selectAll('.node').filter((n) => n.id === p.id).classed('hover', true);
      edges.filter((r) => r.a === p.id || r.b === p.id).classed('hover', true);
      const score = typeof p.score === 'number' ? ` · 점수 ${fmtScore(p.score)}` : '';
      showTooltip(event, `${personLabel(p)}${score}`);
    })
    .on('mousemove', moveTooltip)
    .on('mouseleave', () => {
      svg.selectAll('.node').classed('hover', false);
      edges.classed('hover', false);
      hideTooltip();
    });
  nodes.append('circle').attr('r', 6).attr('fill', (p) => deptColor(p.departmentId));
  nodes.append('text')
    .attr('dy', '0.32em')
    .attr('x', (p) => (pos.get(p.id).angle < 180 ? 10 : -10))
    .attr('text-anchor', (p) => (pos.get(p.id).angle < 180 ? 'start' : 'end'))
    .attr('transform', (p) => (pos.get(p.id).angle < 180 ? null : 'rotate(180)'))
    .text((p) => p.name);

  applyHighlight();
}

function applyHighlight() {
  const ids = new Set([
    ...matchIds(S.searchText),
    ...(isAdmin() ? matchIds(S.adminRelFilter) : []),
    ...matchIds(S.myRelFilter).filter((id) => id !== myPersonId()),
  ]);
  const svg = d3.select('#chart');
  svg.classed('dimmed', ids.size > 0);
  const neighbors = new Set(ids);
  svg.selectAll('.edge').each(function (r) {
    const on = ids.has(r.a) || ids.has(r.b);
    d3.select(this).classed('hl', on);
    if (on) { neighbors.add(r.a); neighbors.add(r.b); }
  });
  svg.selectAll('.node').classed('hl', (p) => neighbors.has(p.id));
}

/** 100% = 차트 영역(최대 800px)에 꼭 맞는 크기. 100% 초과일 때만 스크롤이 생긴다. */
function applyZoom() {
  const box = $('chart-scroll');
  const base = Math.min(CHART_SIZE, box.clientWidth || CHART_SIZE, box.clientHeight || CHART_SIZE);
  const size = Math.floor((base * S.zoom) / 100);
  d3.select('#chart').attr('width', size).attr('height', size);
  box.classList.toggle('scrollable', S.zoom >= 100);
  if (S.zoom > 100) {
    box.scrollLeft = (box.scrollWidth - box.clientWidth) / 2;
    box.scrollTop = (box.scrollHeight - box.clientHeight) / 2;
  }
  $('zoom-value').textContent = `${S.zoom}%`;
  // 슬라이더 채워진 구간 (파스텔 톤, WebKit/Chromium용)
  const pct = ((S.zoom - 50) / 100) * 100;
  $('zoom-slider').style.background = `linear-gradient(to right, #C4DFF7 ${pct}%, #E0EEFB ${pct}%)`;
}

window.addEventListener('resize', () => {
  if (S.data) applyZoom();
  alignSideColumn();
});

function showTooltip(event, text) {
  const t = $('tooltip');
  t.textContent = text;
  t.hidden = false;
  moveTooltip(event);
}

function moveTooltip(event) {
  const t = $('tooltip');
  t.style.left = `${event.clientX + 12}px`;
  t.style.top = `${event.clientY + 12}px`;
}

function hideTooltip() {
  $('tooltip').hidden = true;
}

// ---- 범례 ----------------------------------------------------------------
function renderLegend() {
  const box = clear($('legend-status'));
  for (const key of STATUS_KEYS) {
    const cb = h('input', { type: 'checkbox', class: key, checked: S.statusFilter[key] });
    cb.addEventListener('change', () => {
      S.statusFilter[key] = cb.checked;
      renderChart();
    });
    box.append(h('label', {}, [cb, h('span', { class: `line-sample ${key}` }), h('span', { text: STATUS[key].text })]));
  }
  const depts = clear($('legend-depts'));
  if (!S.data || !S.data.departments.length) depts.append(h('span', { class: 'muted', text: '부서 없음' }));
  for (const d of S.data ? S.data.departments : []) {
    depts.append(h('span', { class: 'legend-item' }, [h('span', { class: 'dot', style: { background: d.color } }), d.name]));
  }
}

// ---- 우측 패널 -----------------------------------------------------------
function renderSidePanels() {
  if (!S.data) return;
  $('panel-ranking').hidden = !canSeeScores();
  if (canSeeScores()) renderRanking();
  renderMyPanel();
  renderDeptInfo();
  renderDeptStats();
}

function renderRanking() {
  const pos = myPosition();
  $('rank-scope').textContent = pos === 'head' ? '· 우리 부서' : pos === 'leader' ? '· 팀 전체' : '';
  const { top, bottom } = computeRanking(S.data.people.filter((p) => typeof p.score === 'number'));
  const fill = (list, items) => {
    clear(list);
    if (!items.length) list.append(h('li', { class: 'muted', text: '해당 없음' }));
    items.forEach((p, i) => list.append(h('li', {}, [
      h('span', { class: 'rank-badge', text: String(i + 1) }),
      h('span', { class: 'dot', style: { background: deptColor(p.departmentId) } }),
      h('span', { text: p.name }),
      h('span', { class: 'score', text: fmtScore(p.score) }),
    ])));
  };
  fill($('rank-top'), top);
  fill($('rank-bottom'), bottom);
}

function renderStatusRadios(fieldset, name, onChange) {
  if (fieldset.querySelector('input')) return;
  for (const key of STATUS_KEYS) {
    const input = h('input', { type: 'radio', name, value: key });
    if (onChange) input.addEventListener('change', onChange);
    fieldset.append(h('label', {}, [input, h('span', { text: STATUS[key].text })]));
  }
}

function setRadio(fieldset, value) {
  for (const r of fieldset.querySelectorAll('input[type=radio]')) r.checked = r.value === value;
}

function getRadio(fieldset) {
  const r = fieldset.querySelector('input[type=radio]:checked');
  return r ? r.value : null;
}

function renderMyPanel() {
  const me = myPersonId();
  const pos = myPosition();
  $('my-score').textContent = `내 점수 ${fmtScore(S.data.me ? S.data.me.score : 0)}`;
  $('my-rel-scope').textContent = pos === 'admin' || pos === 'leader' ? '· 팀 전체' : pos === 'head' ? '· 우리 부서원' : '· 내 관계';
  const alone = S.data.people.length < 2;
  $('my-alone').hidden = !alone;
  $('my-rel-form').hidden = alone;

  // 당사자(A): 부서원은 본인 고정, 부서장은 자기 부서원, 팀장은 전체
  const selA = $('my-rel-a');
  const prevA = selA.value;
  const cands = partyACandidates();
  clear(selA);
  for (const p of cands) selA.append(h('option', { value: p.id, text: p.id === me ? `나 (${p.name})` : personLabel(p) }));
  selA.value = cands.some((p) => p.id === prevA) ? prevA : (cands.some((p) => p.id === me) ? me : (cands[0] ? cands[0].id : ''));
  selA.disabled = cands.length <= 1;
  renderPartyB();
  renderStatusRadios($('my-rel-status'), 'my-status');
  syncMyForm(false);

  const manageable = S.data.relationships.filter((r) => canManagePair(r.a, r.b));
  const mine = myRelationships();
  $('my-rel-count').textContent = pos === 'member'
    ? `입력한 관계 ${mine.length} / 최대 ${Math.max(S.data.people.length - 1, 0)}`
    : `관리 가능한 관계 ${manageable.length}개`;
  const q = S.myRelFilter.trim().toLowerCase();
  const rows = manageable
    .map((r) => {
      const involvesMe = r.a === me || r.b === me;
      const first = involvesMe ? me : r.a;
      const second = otherOf(r, first);
      return { r, first, second, label: involvesMe ? `나 ↔ ${personName(second)}` : `${personName(first)} ↔ ${personName(second)}` };
    })
    .filter((x) => !q || personName(x.first).toLowerCase().includes(q) || personName(x.second).toLowerCase().includes(q))
    .sort((x, y) => collator.compare(x.label, y.label));
  const list = clear($('my-rel-list'));
  if (!rows.length) list.append(h('li', { class: 'empty', text: manageable.length ? '검색 결과가 없습니다.' : '아직 입력한 관계가 없습니다.' }));
  for (const { r, first, second, label } of rows) {
    const cb = h('input', { type: 'checkbox', checked: S.mySelected.has(r.id), 'aria-label': `${label} 선택` });
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', () => {
      if (cb.checked) S.mySelected.add(r.id); else S.mySelected.delete(r.id);
      li.classList.toggle('selected', cb.checked);
      $('my-rel-delete').disabled = S.mySelected.size === 0;
    });
    const pick = () => {
      const aOk = [...selA.options].some((o) => o.value === first);
      selA.value = aOk ? first : second;
      renderPartyB();
      $('my-rel-other').value = aOk ? second : first;
      syncMyForm(true);
      $('my-rel-other').focus();
    };
    const other = personById(second);
    const li = h('li', { class: S.mySelected.has(r.id) ? 'selected' : '', tabindex: '0', title: '클릭하면 상태를 수정할 수 있습니다' }, [
      cb,
      h('span', { class: 'dot', style: { background: deptColor(other ? other.departmentId : '') } }),
      h('span', { class: 'grow', text: label }),
      r.updatedByLabel ? h('span', { class: 'by', text: `수정: ${r.updatedByLabel}` }) : null,
      r.updatedByRole ? h('span', { class: 'by', text: `수정: ${r.updatedByRole === 'party' ? (r.a === me || r.b === me ? '상대방' : '당사자') : (UPDATED_BY_LABELS[r.updatedByRole] || '-')}` }) : null,
      h('span', { class: `status-chip ${r.status}`, text: STATUS[r.status].label }),
    ]);
    li.addEventListener('click', pick);
    li.addEventListener('keydown', (e) => { if (e.key === 'Enter') pick(); });
    list.append(li);
  }
  $('my-rel-delete').disabled = S.mySelected.size === 0;
}

/** 상대(B) 목록: 당사자(A)를 뺀 팀 전원 */
function renderPartyB() {
  const a = $('my-rel-a').value;
  const sel = $('my-rel-other');
  const prev = sel.value;
  const others = orderedPeople().filter((p) => p.id !== a);
  clear(sel).append(h('option', { value: '', text: '상대 선택' }));
  for (const p of others) sel.append(h('option', { value: p.id, text: personLabel(p) }));
  sel.value = others.some((p) => p.id === prev) ? prev : '';
}

/** 이미 관계가 있는 쌍을 고르면 현재 상태를 표시하고 버튼을 "수정"으로 */
function syncMyForm(fromUser) {
  const a = $('my-rel-a').value;
  const b = $('my-rel-other').value;
  const rel = a && b && S.data.relationships.find((r) => r.id === relId(a, b));
  if (rel) setRadio($('my-rel-status'), rel.status);
  else if (fromUser) setRadio($('my-rel-status'), null);
  $('my-rel-save').textContent = rel ? '수정' : '저장';
}

function relId(a, b) {
  return a < b ? `${a}_${b}` : `${b}_${a}`;
}

function renderDeptInfo() {
  const sel = $('info-dept');
  const depts = allowedDepts();
  if (!depts.some((d) => d.id === S.infoDeptId)) S.infoDeptId = depts[0] ? depts[0].id : null;
  clear(sel);
  if (!depts.length) sel.append(h('option', { value: '', text: '부서 없음' }));
  for (const d of depts) sel.append(h('option', { value: d.id, text: d.name }));
  sel.value = S.infoDeptId || '';
  const names = S.data.people
    .filter((p) => p.departmentId === S.infoDeptId)
    .map((p) => p.name)
    .sort(collator.compare);
  $('info-members').value = names.length ? names.join('\n') : '(소속 인원 없음)';
}

function renderDeptStats() {
  const box = clear($('dept-stats'));
  const counts = S.data.departments.map((d) => ({ d, n: S.data.people.filter((p) => p.departmentId === d.id).length }));
  const max = Math.max(1, ...counts.map((c) => c.n));
  if (!counts.length) box.append(h('p', { class: 'muted', text: '부서가 없습니다.' }));
  for (const { d, n } of counts) {
    box.append(h('div', { class: 'stat-row', title: `${d.name}: ${n}명` }, [
      h('span', { class: 'name' }, [h('span', { class: 'dot', style: { background: d.color } }), d.name]),
      h('div', { class: 'stat-bar' }, [h('span', { style: { width: `${(n / max) * 100}%`, background: pastel(d.color) } })]),
      h('span', { class: 'num', text: `${n}명` }),
    ]));
  }
}

// ---------------------------------------------------------------------------
// 관리자 탭
// ---------------------------------------------------------------------------
function renderAdmin() {
  const hasTeam = !!(S.teamId && S.data);
  for (const el of document.querySelectorAll('#tab-admin .needs-team')) el.hidden = !hasTeam;
  for (const el of document.querySelectorAll('#tab-admin .no-team-msg')) el.hidden = hasTeam;
  renderTeamAdmin();
  if (!hasTeam) return;
  renderDeptAdmin();
  renderPeopleAdmin();
  renderRelAdmin();
}

function renderTeamAdmin() {
  const body = clear($('team-table'));
  if (!S.teams.length) body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '4', text: '팀이 없습니다.' })]));
  for (const t of S.teams) {
    const tr = h('tr', { class: t.id === S.teamId ? 'selected' : '' }, [
      h('td', { text: t.name }),
      h('td', { text: `${t.memberCount ?? '-'} / ${t.maxMembers}` }),
      h('td', { text: t.memberViewScope === 'team' ? '우리 부서 관계' : '본인 관계만' }),
      h('td', { text: fmtDate(t.updatedAt) }),
    ]);
    body.append(tr);
  }
  if (!S.data) return;
  const t = S.data.team;
  const active = document.activeElement;
  const editing = active && active.closest && active.closest('#team-edit-form');
  if (!editing) {
    $('team-edit-name').value = t.name;
    $('team-edit-scope').value = t.memberViewScope;
    $('team-edit-max').value = t.maxMembers;
  }
  const lim = S.limits || { systemMaxMembers: 100 };
  $('team-edit-max').min = Math.max(1, S.data.people.length);
  $('team-edit-max').max = lim.systemMaxMembers;
  $('team-edit-max-hint').textContent = `현재 ${S.data.people.length}명 이상 ~ 최대 ${lim.systemMaxMembers}명까지 설정할 수 있습니다.`;
  $('team-delete-target').textContent = t.name;
  $('team-seed').disabled = S.data.people.length >= t.maxMembers;
}

function renderDeptAdmin() {
  const list = clear($('dept-list'));
  if (!S.data.departments.length) list.append(h('li', { class: 'empty', text: '부서가 없습니다. 부서를 추가하세요.' }));
  for (const d of S.data.departments) {
    const n = S.data.people.filter((p) => p.departmentId === d.id).length;
    const cb = h('input', { type: 'checkbox', checked: S.deptSelected.has(d.id), 'aria-label': `${d.name} 선택` });
    const li = h('li', { class: S.deptSelected.has(d.id) ? 'selected' : '', tabindex: '0' }, [
      cb,
      h('span', { class: 'dot', style: { background: d.color } }),
      h('span', { class: 'grow', text: d.name }),
      h('span', { class: 'muted', text: `${n}명` }),
    ]);
    const toggle = () => {
      if (S.deptSelected.has(d.id)) S.deptSelected.delete(d.id); else S.deptSelected.add(d.id);
      renderDeptAdmin();
    };
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', toggle);
    li.addEventListener('click', toggle);
    li.addEventListener('keydown', (e) => { if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); } });
    list.append(li);
  }
  $('dept-delete').disabled = S.deptSelected.size === 0;

  const one = S.deptSelected.size === 1 ? deptById([...S.deptSelected][0]) : null;
  $('dept-edit-empty').hidden = !!one;
  $('dept-edit-body').hidden = !one;
  if (one) {
    if ($('dept-edit-form').dataset.id !== one.id) {
      $('dept-edit-form').dataset.id = one.id;
      $('dept-edit-name').value = one.name;
      S.deptEditColor = one.color;
    }
    renderPalette();
  } else {
    delete $('dept-edit-form').dataset.id;
  }

  for (const sel of document.querySelectorAll('.position-select')) {
    if (!sel.options.length) {
      for (const [k, v] of Object.entries(POSITIONS)) sel.append(h('option', { value: k, text: v }));
      sel.value = 'member';
    }
  }
  for (const sel of [$('person-create-dept'), $('person-edit-dept')]) {
    const prev = sel.value;
    clear(sel);
    for (const d of S.data.departments) sel.append(h('option', { value: d.id, text: d.name }));
    if (S.data.departments.some((d) => d.id === prev)) sel.value = prev;
  }
}

function renderPalette() {
  const box = clear($('dept-edit-palette'));
  for (const c of S.palette) {
    const b = h('button', {
      type: 'button',
      class: `swatch${c === S.deptEditColor ? ' selected' : ''}`,
      style: { background: c },
      role: 'radio',
      'aria-checked': String(c === S.deptEditColor),
      'aria-label': c,
      title: c,
    });
    b.addEventListener('click', () => { S.deptEditColor = c; renderPalette(); });
    box.append(b);
  }
}

function accountBadge(acc) {
  if (!acc) return h('span', { class: 'acc-badge', text: '없음' });
  const parts = [h('span', { class: `acc-badge ${acc.active ? 'active' : 'inactive'}`, text: acc.active ? '활성' : '비활성' })];
  if (acc.locked) parts.push(' ', h('span', { class: 'acc-badge locked', text: '잠김' }));
  return h('span', {}, parts);
}

/** 팀장·부서장 공석 (부서원이 있는 부서만) */
function missingPositions() {
  if (!S.data || !S.data.people.length) return [];
  const out = [];
  if (!S.data.people.some((p) => p.position === 'leader')) out.push('팀장 미지정');
  const noHead = S.data.departments.filter((d) =>
    S.data.people.some((p) => p.departmentId === d.id) && !S.data.people.some((p) => p.departmentId === d.id && p.position === 'head'));
  if (noHead.length) out.push(`부서장 미지정: ${noHead.map((d) => d.name).join(', ')}`);
  return out;
}

/** 팀장·부서장이 빠지게 되는 변경이면 경고 문구 */
function vacancyWarning(people) {
  const lines = [];
  for (const p of people) {
    if (p.position === 'leader') lines.push(`⚠ 팀장(${p.name})이 없어지면 팀 전체 관계를 관리할 사람이 없습니다.`);
    if (p.position === 'head') {
      const d = deptById(p.departmentId);
      lines.push(`⚠ ${d ? d.name : ''} 부서장(${p.name})이 없어지면 그 부서의 관계를 관리할 사람이 없습니다.`);
    }
  }
  return lines.length ? `\n\n${lines.join('\n')}` : '';
}

function renderPeopleAdmin() {
  const t = S.data.team;
  const n = S.data.people.length;
  $('person-count').textContent = `${n} / ${t.maxMembers}`;
  const full = n >= t.maxMembers;
  $('person-limit-msg').hidden = !full;
  $('person-create-btn').disabled = full || !S.data.departments.length;
  const missing = missingPositions();
  $('position-warning').hidden = !missing.length;
  $('position-warning').textContent = missing.length ? `⚠ ${missing.join(' · ')} — 해당 범위의 관계를 관리할 사람이 없습니다.` : '';

  const body = clear($('person-table'));
  const people = orderedPeople();
  if (!people.length) body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '6', text: '부서원이 없습니다.' })]));
  for (const p of people) {
    const d = deptById(p.departmentId);
    const cb = h('input', { type: 'checkbox', checked: S.personSelected.has(p.id), 'aria-label': `${p.name} 선택` });
    const toggle = () => {
      if (S.personSelected.has(p.id)) S.personSelected.delete(p.id); else S.personSelected.add(p.id);
      renderPeopleAdmin();
    };
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', toggle);
    const tr = h('tr', { class: S.personSelected.has(p.id) ? 'selected' : '' }, [
      h('td', {}, [cb]),
      h('td', {}, [h('span', { class: 'dot', style: { background: deptColor(p.departmentId), marginRight: '6px' } }), p.name]),
      h('td', { text: d ? d.name : '-' }),
      h('td', { text: POSITIONS[p.position] || '부서원' }),
      h('td', { text: p.account ? p.account.loginId : '-' }),
      h('td', {}, [accountBadge(p.account)]),
    ]);
    tr.addEventListener('click', toggle);
    body.append(tr);
  }
  $('person-check-all').checked = people.length > 0 && S.personSelected.size === people.length;
  $('person-delete').disabled = S.personSelected.size === 0;

  // 선택된 1명만 수정 가능
  const one = S.personSelected.size === 1 ? personById([...S.personSelected][0]) : null;
  $('person-edit-empty').hidden = !!one;
  $('person-edit-body').hidden = !one;
  if (!one) {
    delete $('person-edit-form').dataset.id;
    return;
  }
  if ($('person-edit-form').dataset.id !== one.id) {
    $('person-edit-form').dataset.id = one.id;
    $('person-edit-name').value = one.name;
    $('person-edit-dept').value = one.departmentId;
    $('person-edit-position').value = one.position || 'member';
    $('person-account-login').value = '';
  }
  const acc = one.account;
  $('person-account-info').textContent = acc
    ? `로그인 ID: ${acc.loginId} · ${acc.active ? '활성' : '비활성'}${acc.locked ? ' · 로그인 잠김' : ''}`
    : '계정 없음 (관리자가 관계를 대신 입력할 수 있습니다)';
  $('person-account-create').hidden = !!acc;
  $('person-account-actions').hidden = !acc;
  if (acc) {
    $('btn-account-toggle').textContent = acc.active ? '비활성화' : '활성화';
    $('btn-account-unlock').hidden = !acc.locked;
  }
}

function renderRelAdmin() {
  const people = orderedPeople();
  for (const id of ['admin-rel-a', 'admin-rel-b']) {
    const sel = $(id);
    const prev = sel.value;
    clear(sel).append(h('option', { value: '', text: id.endsWith('a') ? '부서원 A' : '부서원 B' }));
    for (const p of people) sel.append(h('option', { value: p.id, text: personLabel(p) }));
    if (people.some((p) => p.id === prev)) sel.value = prev;
  }
  renderStatusRadios($('admin-rel-status'), 'admin-status');

  const q = S.adminRelFilter.trim().toLowerCase();
  const rows = S.data.relationships
    .map((r) => ({ r, an: personName(r.a), bn: personName(r.b) }))
    .filter((x) => !q || x.an.toLowerCase().includes(q) || x.bn.toLowerCase().includes(q))
    .sort((x, y) => collator.compare(x.an, y.an) || collator.compare(x.bn, y.bn));
  $('admin-rel-count').textContent = `${rows.length} / 전체 ${S.data.relationships.length}개`;
  const body = clear($('admin-rel-table'));
  if (!rows.length) body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '7', text: '관계가 없습니다.' })]));
  for (const { r, an, bn } of rows) {
    const cb = h('input', { type: 'checkbox', checked: S.relSelected.has(r.id), 'aria-label': `${an} ↔ ${bn} 선택` });
    const toggle = () => {
      if (S.relSelected.has(r.id)) S.relSelected.delete(r.id); else S.relSelected.add(r.id);
      tr.classList.toggle('selected', S.relSelected.has(r.id));
      cb.checked = S.relSelected.has(r.id);
      updateRelAdminButtons();
    };
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', toggle);
    const tr = h('tr', { class: S.relSelected.has(r.id) ? 'selected' : '' }, [
      h('td', {}, [cb]),
      h('td', { text: `${an} ↔ ${bn}` }),
      h('td', {}, [h('span', { class: `status-chip ${r.status}`, text: STATUS[r.status].text })]),
      h('td', { text: r.createdByLabel || '-' }),
      h('td', { text: r.updatedByLabel || '-' }),
      h('td', { text: fmtDate(r.updatedAt) }),
      h('td', {}, [h('button', {
        type: 'button', class: 'btn btn-mini btn-ghost', title: '변경 이력 보기', 'aria-label': `${an} ↔ ${bn} 변경 이력`,
        onclick: (ev) => { ev.stopPropagation(); safe(openHistory)(r); },
      }, [icon('history'), '이력'])]),
    ]);
    tr.addEventListener('click', toggle);
    body.append(tr);
  }
  updateRelAdminButtons();
}

function updateRelAdminButtons() {
  $('admin-rel-delete').disabled = S.relSelected.size === 0;
  $('admin-rel-bulk-status').disabled = S.relSelected.size === 0;
  const total = $('admin-rel-table').querySelectorAll('input[type=checkbox]').length;
  $('rel-check-all').checked = total > 0 && S.relSelected.size >= total;
}

// ---- 활동 로그 (검색·페이지) ------------------------------------------------
async function loadAudit(page = S.auditPage || 1) {
  S.auditPage = page;
  const body = clear($('audit-table'));
  body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '6', text: '불러오는 중…' })]));
  const params = {
    from: $('audit-from').value || null,
    to: $('audit-to').value || null,
    category: $('audit-category').value || null,
    user: $('audit-user').value.trim() || null,
    q: $('audit-q').value.trim() || null,
    page,
    pageSize: 50,
  };
  try {
    const res = await api('audit_log', { params });
    clear(body);
    if (!res.entries.length) body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '6', text: '조건에 맞는 기록이 없습니다.' })]));
    for (const e of res.entries) {
      body.append(h('tr', {}, [
        h('td', { class: 'nowrap', text: fmtDate(e.at) }),
        h('td', { text: e.userLabel }),
        h('td', { text: AUDIT_LABELS[e.action] || e.action }),
        h('td', { text: e.teamName }),
        h('td', { class: 'ellipsis', text: e.target || '-', title: e.target || '' }),
        h('td', { text: e.summary || '' }),
      ]));
    }
    $('audit-info').textContent = `총 ${res.total.toLocaleString()}건`;
    $('audit-page').textContent = `${res.page} / ${res.pages}`;
    $('audit-prev').disabled = res.page <= 1;
    $('audit-next').disabled = res.page >= res.pages;
  } catch (e) {
    clear(body).append(h('tr', {}, [h('td', { class: 'empty', colspan: '6', text: e.message })]));
  }
}

// ---- 관계 변경 이력 -----------------------------------------------------------
async function openHistory(rel) {
  $('history-title').textContent = `${personName(rel.a)} ↔ ${personName(rel.b)}`;
  const body = clear($('history-table'));
  body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '3', text: '불러오는 중…' })]));
  openDialog($('history-dialog'));
  const { entries } = await api('relationship_history', { params: { teamId: S.teamId, relId: rel.id } });
  clear(body);
  if (!entries.length) body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '3', text: '이력이 없습니다.' })]));
  const chip = (st) => (st ? h('span', { class: `status-chip ${st}`, text: STATUS[st].label }) : h('span', { class: 'muted', text: '없음' }));
  for (const e of entries) {
    const change = e.baseline
      ? h('span', {}, [chip(e.to), h('span', { class: 'muted', text: ' (이력 기록 시작 시점 상태)' })])
      : h('span', { class: 'change' }, [chip(e.from), ' → ', e.to ? chip(e.to) : h('span', { class: 'muted', text: '삭제' })]);
    body.append(h('tr', {}, [h('td', { class: 'nowrap', text: fmtDate(e.at) }), h('td', {}, [change]), h('td', { text: e.byLabel })]));
  }
}

function openDialog(dlg) {
  if (typeof dlg.showModal === 'function') { if (!dlg.open) dlg.showModal(); } else dlg.setAttribute('open', '');
}

function closeDialog(dlg) {
  if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open');
}

// ---- 부서원 일괄 등록 (CSV) ----------------------------------------------------
/** 따옴표·쉼표·줄바꿈을 처리하는 간단한 CSV 파서 */
function parseCsv(text) {
  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { cell += '"'; i++; } else if (c === '"') quoted = false; else cell += c;
    } else if (c === '"') quoted = true;
    else if (c === ',' || c === '\t') { row.push(cell); cell = ''; } else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(cell); rows.push(row); row = []; cell = '';
    } else cell += c;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows.map((r) => r.map((x) => x.trim())).filter((r) => r.some((x) => x !== ''));
}

/** 엑셀이 저장한 CSV(CP949)도 읽을 수 있도록 UTF-8 → EUC-KR 순서로 해석 */
async function readTextFile(file) {
  const buf = await file.arrayBuffer();
  try {
    return new TextDecoder('utf-8', { fatal: true }).decode(buf).replace(/^﻿/, '');
  } catch (e) {
    return new TextDecoder('euc-kr').decode(buf);
  }
}

function csvEscape(v) {
  const s = String(v ?? '');
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function downloadCsv(rows, filename) {
  const text = '﻿' + rows.map((r) => r.map(csvEscape).join(',')).join('\r\n');
  downloadBlob(new Blob([text], { type: 'text/csv;charset=utf-8' }), filename);
}

function downloadBlob(blob, filename) {
  const a = h('a', { href: URL.createObjectURL(blob), download: filename });
  document.body.append(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

async function submitBulk() {
  const file = $('bulk-file').files[0];
  const text = file ? await readTextFile(file) : $('bulk-text').value;
  let rows = parseCsv(text);
  if (rows.length && /^(이름|name)$/i.test(rows[0][0])) rows = rows.slice(1);
  if (!rows.length) { toast('등록할 행이 없습니다.', true); return; }
  const data = rows.map((r) => ({ name: r[0] || '', department: r[1] || '', position: r[2] || '', loginId: r[3] || '' }));
  if (!confirm(`${data.length}명을 등록할까요?\n(한 행이라도 오류가 있으면 전체가 등록되지 않습니다)`)) return;
  const res = await mutate('person_bulk_create', teamBody({ rows: data }));
  $('bulk-file').value = '';
  $('bulk-text').value = '';
  $('bulk-summary').textContent = `부서원 ${res.created}명을 등록했습니다.`
    + (res.departmentsCreated.length ? ` 새 부서: ${res.departmentsCreated.join(', ')}.` : '');
  S.bulkAccounts = res.accounts;
  $('bulk-accounts').hidden = !res.accounts.length;
  $('bulk-download').hidden = !res.accounts.length;
  const body = clear($('bulk-table'));
  for (const a of res.accounts) body.append(h('tr', {}, [h('td', { text: a.name }), h('td', { text: a.loginId }), h('td', {}, [h('code', { text: a.tempPassword })])]));
  openDialog($('bulk-dialog'));
}

// ---- 관리자 계정 ---------------------------------------------------------------
async function loadAdmins() {
  const body = clear($('admin-table'));
  const { admins } = await api('admin_list');
  for (const a of admins) {
    const status = [h('span', { class: `acc-badge ${a.active ? 'active' : 'inactive'}`, text: a.active ? '활성' : '비활성' })];
    if (a.locked) status.push(' ', h('span', { class: 'acc-badge locked', text: '잠김' }));
    if (a.mustChangePassword) status.push(' ', h('span', { class: 'acc-badge', text: '임시 비밀번호' }));
    const actions = h('div', { class: 'row-end' });
    if (!a.isSelf) {
      actions.append(h('button', {
        type: 'button', class: 'btn btn-mini', text: '비밀번호 초기화',
        onclick: safe(async () => {
          if (!confirm(`${a.loginId}의 비밀번호를 초기화할까요?`)) return;
          const res = await mutate('admin_reset_password', { userId: a.id }, { reload: false });
          showTempPassword(res.loginId, res.tempPassword);
          await loadAdmins();
        }),
      }));
      if (a.locked) {
        actions.append(h('button', {
          type: 'button', class: 'btn btn-mini', text: '잠금 해제',
          onclick: safe(async () => { await mutate('admin_unlock', { userId: a.id }, { reload: false, success: '잠금을 해제했습니다.' }); await loadAdmins(); }),
        }));
      }
      actions.append(h('button', {
        type: 'button', class: `btn btn-mini ${a.active ? 'btn-danger' : ''}`, text: a.active ? '비활성화' : '활성화',
        onclick: safe(async () => {
          if (a.active && !confirm(`${a.loginId} 관리자 계정을 비활성화할까요? 사용 중인 세션도 즉시 차단됩니다.`)) return;
          await mutate('admin_set_active', { userId: a.id, active: !a.active }, { reload: false, success: a.active ? '비활성화했습니다.' : '활성화했습니다.' });
          await loadAdmins();
        }),
      }));
    } else {
      actions.append(h('span', { class: 'muted', text: '본인 (상단의 비밀번호 변경 이용)' }));
    }
    body.append(h('tr', {}, [
      h('td', { text: a.loginId }),
      h('td', {}, status),
      h('td', { text: fmtDate(a.lastLoginAt) }),
      h('td', { text: fmtDate(a.createdAt) }),
      h('td', {}, [actions]),
    ]));
  }
}

// ---- 점수 추이 (관리자) ----------------------------------------------------------
async function loadTrend() {
  if (!S.teamId) return;
  S.trendData = await api('score_trend', { params: { teamId: S.teamId, months: $('trend-months').value } });
  const ids = new Set(S.trendData.series.map((x) => x.personId));
  S.trendSel = (S.trendSel || []).filter((x) => ids.has(x.id));
  if (!S.trendSel.length && S.trendData.series.length) {
    // 기본: 현재 점수 최고·최저 1명씩
    const sorted = [...S.trendData.series].sort((a, b) => b.scores.at(-1) - a.scores.at(-1));
    for (const x of [sorted[0], sorted.at(-1)]) if (x && !S.trendSel.some((y) => y.id === x.personId)) addTrendPerson(x.personId, false);
  }
  renderTrend();
}

/** 색은 사람을 따라간다: 선택 시 비어 있는 가장 앞 칸을 배정하고 이후 유지 */
function addTrendPerson(id, render = true) {
  S.trendSel = S.trendSel || [];
  if (S.trendSel.some((x) => x.id === id) || S.trendSel.length >= SERIES_COLORS.length) return;
  const used = new Set(S.trendSel.map((x) => x.slot));
  const slot = SERIES_COLORS.findIndex((_, i) => !used.has(i));
  S.trendSel.push({ id, slot });
  if (render) renderTrend();
}

function renderTrend() {
  const data = S.trendData;
  if (!data) return;
  const sel = S.trendSel || [];
  const add = clear($('trend-add'));
  add.append(h('option', { value: '', text: sel.length >= SERIES_COLORS.length ? '최대 5명' : '+ 비교할 부서원 추가' }));
  for (const p of orderedPeople()) if (!sel.some((x) => x.id === p.id)) add.append(h('option', { value: p.id, text: personLabel(p) }));
  add.disabled = sel.length >= SERIES_COLORS.length;

  const chips = clear($('trend-chips'));
  const legend = clear($('trend-legend'));
  const series = sel.map((x) => ({ ...x, color: SERIES_COLORS[x.slot], data: data.series.find((y) => y.personId === x.id) })).filter((x) => x.data);
  for (const x of series) {
    chips.append(h('span', { class: 'chip' }, [
      h('span', { class: 'swatch-line', style: { background: x.color } }), x.data.name,
      h('button', { type: 'button', class: 'chip-x', 'aria-label': `${x.data.name} 제거`, onclick: () => { S.trendSel = S.trendSel.filter((y) => y.id !== x.id); renderTrend(); } }, [icon('x')]),
    ]));
    legend.append(h('span', { class: 'legend-item' }, [h('span', { class: 'swatch-line', style: { background: x.color } }), x.data.name]));
  }
  drawTrendChart(data.months, series);

  // 표 보기
  const table = clear($('trend-table'));
  table.append(h('thead', {}, [h('tr', {}, [h('th', { text: '부서원' }), ...data.months.map((m) => h('th', { text: m }))])]));
  const tb = h('tbody');
  for (const x of series) tb.append(h('tr', {}, [h('td', { text: x.data.name }), ...x.data.scores.map((v) => h('td', { class: 'num', text: fmtScore(v) }))]));
  table.append(tb);
}

function drawTrendChart(months, series) {
  const box = $('trend-chart');
  clear(box);
  if (!series.length) { box.append(h('p', { class: 'chart-empty-inline', text: '비교할 부서원을 추가하세요.' })); return; }
  const W = Math.max(box.clientWidth, 480);
  const H = 300;
  const m = { top: 16, right: series.length <= 4 ? 96 : 24, bottom: 32, left: 40 };
  const all = series.flatMap((x) => x.data.scores);
  const lo = Math.min(0, ...all);
  const hi = Math.max(0, ...all);
  const pad = Math.max(1, Math.ceil((hi - lo) * 0.1));
  const x = d3.scalePoint().domain(months).range([m.left, W - m.right]);
  const y = d3.scaleLinear().domain([lo - pad, hi + pad]).nice().range([H - m.bottom, m.top]);
  const svg = d3.select(box).append('svg').attr('viewBox', `0 0 ${W} ${H}`).attr('width', '100%').attr('height', H)
    .attr('role', 'img').attr('aria-label', '월별 점수 추이');
  const yTicks = y.ticks(6).filter(Number.isInteger);
  svg.append('g').attr('class', 'grid').selectAll('line').data(yTicks).join('line')
    .attr('x1', m.left).attr('x2', W - m.right).attr('y1', (d) => y(d)).attr('y2', (d) => y(d))
    .classed('zero', (d) => d === 0);
  svg.append('g').attr('class', 'axis').selectAll('text').data(yTicks).join('text')
    .attr('x', m.left - 8).attr('y', (d) => y(d)).attr('dy', '0.32em').attr('text-anchor', 'end').text((d) => fmtScore(d));
  const step = Math.ceil(months.length / 8);
  svg.append('g').attr('class', 'axis').selectAll('text').data(months.filter((_, i) => i % step === 0 || i === months.length - 1)).join('text')
    .attr('x', (d) => x(d)).attr('y', H - m.bottom + 18).attr('text-anchor', 'middle').text((d) => d.slice(2).replace('-', '.'));
  const line = d3.line().x((_, i) => x(months[i])).y((d) => y(d));
  for (const s of series) {
    svg.append('path').attr('class', 'trend-line').attr('d', line(s.data.scores)).attr('stroke', s.color);
    svg.append('circle').attr('class', 'trend-end').attr('cx', x(months.at(-1))).attr('cy', y(s.data.scores.at(-1))).attr('r', 4).attr('fill', s.color);
  }
  if (series.length <= 4) {
    // 선 끝 직접 라벨 (겹치지 않게 세로로 밀어냄)
    const labels = series.map((s) => ({ s, y: y(s.data.scores.at(-1)) })).sort((a, b) => a.y - b.y);
    for (let i = 1; i < labels.length; i++) labels[i].y = Math.max(labels[i].y, labels[i - 1].y + 14);
    for (const l of labels) {
      svg.append('text').attr('class', 'direct-label').attr('x', x(months.at(-1)) + 10).attr('y', l.y).attr('dy', '0.32em')
        .text(`${l.s.data.name} ${fmtScore(l.s.data.scores.at(-1))}`);
    }
  }
  // 크로스헤어 + 툴팁
  const cross = svg.append('line').attr('class', 'crosshair').attr('y1', m.top).attr('y2', H - m.bottom).style('display', 'none');
  const dots = svg.append('g').style('display', 'none');
  svg.append('rect').attr('class', 'hover-area').attr('x', m.left).attr('y', m.top).attr('width', W - m.left - m.right).attr('height', H - m.top - m.bottom)
    .on('mousemove', (event) => {
      const [mx] = d3.pointer(event);
      let i = 0;
      months.forEach((mo, k) => { if (Math.abs(x(mo) - mx) < Math.abs(x(months[i]) - mx)) i = k; });
      cross.style('display', null).attr('x1', x(months[i])).attr('x2', x(months[i]));
      dots.style('display', null).selectAll('circle').data(series).join('circle').attr('r', 5)
        .attr('cx', x(months[i])).attr('cy', (s) => y(s.data.scores[i])).attr('fill', (s) => s.color).attr('stroke', '#fff').attr('stroke-width', 2);
      const rows = [...series].sort((a, b) => b.data.scores[i] - a.data.scores[i]).map((s) => `${s.data.name} ${fmtScore(s.data.scores[i])}`);
      showTooltip(event, `${months[i]}  ·  ${rows.join('  ·  ')}`);
    })
    .on('mouseleave', () => { cross.style('display', 'none'); dots.style('display', 'none'); hideTooltip(); });
}

// ---- 관계도 내보내기 (PNG / PDF) ----------------------------------------------
async function diagramCanvas() {
  const svg = $('chart');
  if (!svg.querySelector('.node')) throw new Error('표시할 관계도가 없습니다.');
  const clone = svg.cloneNode(true);
  const props = ['fill', 'stroke', 'stroke-width', 'stroke-dasharray', 'stroke-opacity', 'stroke-linecap', 'opacity', 'font-size', 'font-weight', 'font-family'];
  const src = svg.querySelectorAll('*');
  const dst = clone.querySelectorAll('*');
  src.forEach((el, i) => {
    const cs = getComputedStyle(el);
    for (const p of props) dst[i].setAttribute(p, cs.getPropertyValue(p));
  });
  clone.querySelectorAll('.hit').forEach((el) => el.remove());
  clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
  const size = 1600;
  clone.setAttribute('width', size);
  clone.setAttribute('height', size);
  const url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(clone));
  const img = new Image();
  await new Promise((ok, fail) => { img.onload = ok; img.onerror = () => fail(new Error('이미지를 만들 수 없습니다.')); img.src = url; });

  const head = 120;
  const foot = 110;
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size + head + foot;
  const g = canvas.getContext('2d');
  g.fillStyle = '#ffffff';
  g.fillRect(0, 0, canvas.width, canvas.height);
  const font = getComputedStyle(document.body).fontFamily;
  const v = S.view || {};
  const scope = v.scope === 'dept' ? `부서: ${(deptById(v.deptId) || {}).name || ''}`
    : v.scope === 'person' ? `부서원: ${personName(v.personId)}` : '팀 전체';
  g.fillStyle = '#111827';
  g.font = `700 44px ${font}`;
  g.fillText(`${S.data.team.name} 관계도`, 60, 70);
  g.fillStyle = '#6b7280';
  g.font = `400 28px ${font}`;
  g.fillText(`${scope}  ·  ${fmtDate(new Date().toISOString())}`, 60, 108);
  g.drawImage(img, 0, head, size, size);
  // 범례
  let lx = 60;
  const ly = head + size + 50;
  g.font = `400 26px ${font}`;
  for (const key of STATUS_KEYS) {
    if (!S.statusFilter[key]) continue;
    g.strokeStyle = { private: '#3B82F6', public: '#9CA3AF', social: '#EF4444' }[key];
    g.lineWidth = 4;
    g.setLineDash(key === 'public' ? [3, 6] : key === 'social' ? [12, 8] : []);
    g.beginPath(); g.moveTo(lx, ly); g.lineTo(lx + 56, ly); g.stroke();
    g.setLineDash([]);
    g.fillStyle = '#374151';
    g.fillText(STATUS[key].text, lx + 70, ly + 9);
    lx += 70 + g.measureText(STATUS[key].text).width + 50;
  }
  lx = 60;
  for (const d of allowedDepts()) {
    g.fillStyle = d.color;
    g.beginPath(); g.arc(lx + 10, ly + 48, 10, 0, Math.PI * 2); g.fill();
    g.fillStyle = '#374151';
    g.fillText(d.name, lx + 28, ly + 57);
    lx += 28 + g.measureText(d.name).width + 36;
  }
  return canvas;
}

function exportFileName(ext) {
  return `${S.data.team.name}-관계도-${new Date().toISOString().slice(0, 10)}.${ext}`;
}

async function exportPng() {
  const canvas = await diagramCanvas();
  canvas.toBlob((blob) => downloadBlob(blob, exportFileName('png')), 'image/png');
}

/** JPEG 한 장을 담은 최소 PDF (A4 세로, 여백 30pt) */
async function exportPdf() {
  const canvas = await diagramCanvas();
  const jpeg = Uint8Array.from(atob(canvas.toDataURL('image/jpeg', 0.92).split(',')[1]), (c) => c.charCodeAt(0));
  const pw = 595.28;
  const ph = 841.89;
  const scale = Math.min((pw - 60) / canvas.width, (ph - 60) / canvas.height);
  const w = (canvas.width * scale).toFixed(2);
  const hgt = (canvas.height * scale).toFixed(2);
  const x = ((pw - w) / 2).toFixed(2);
  const y = (ph - 30 - hgt).toFixed(2);
  const enc = new TextEncoder();
  const parts = [];
  const offsets = [];
  let len = 0;
  const push = (data) => { const b = typeof data === 'string' ? enc.encode(data) : data; parts.push(b); len += b.length; };
  const content = `q ${w} 0 0 ${hgt} ${x} ${y} cm /Im0 Do Q`;
  push('%PDF-1.4\n');
  const obj = (n, body) => { offsets[n] = len; push(`${n} 0 obj\n${body}\nendobj\n`); };
  obj(1, '<< /Type /Catalog /Pages 2 0 R >>');
  obj(2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>');
  obj(3, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pw} ${ph}] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>`);
  offsets[4] = len;
  push(`4 0 obj\n<< /Type /XObject /Subtype /Image /Width ${canvas.width} /Height ${canvas.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${jpeg.length} >>\nstream\n`);
  push(jpeg);
  push('\nendstream\nendobj\n');
  obj(5, `<< /Length ${content.length} >>\nstream\n${content}\nendstream`);
  const xref = len;
  push(`xref\n0 6\n0000000000 65535 f \n${[1, 2, 3, 4, 5].map((n) => `${String(offsets[n]).padStart(10, '0')} 00000 n \n`).join('')}`);
  push(`trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
  downloadBlob(new Blob(parts, { type: 'application/pdf' }), exportFileName('pdf'));
}

function showTempPassword(loginId, pw) {
  $('temp-pw-login').textContent = loginId;
  $('temp-pw-value').textContent = pw;
  const dlg = $('temp-pw-dialog');
  if (typeof dlg.showModal === 'function') dlg.showModal();
  else dlg.setAttribute('open', '');
}

function switchTab(tab) {
  S.tab = tab;
  for (const b of document.querySelectorAll('.tab')) {
    const on = b.dataset.tab === tab;
    b.classList.toggle('active', on);
    b.setAttribute('aria-selected', String(on));
  }
  $('tab-diagram').hidden = tab !== 'diagram';
  $('tab-admin').hidden = tab !== 'admin';
}

function switchSub(sub) {
  S.adminSub = sub;
  for (const b of document.querySelectorAll('.subtab')) b.classList.toggle('active', b.dataset.sub === sub);
  for (const p of document.querySelectorAll('.sub-panel')) p.hidden = p.dataset.sub !== sub;
  if (sub === 'audit') loadAudit(1);
  if (sub === 'admins') safe(loadAdmins)();
  if (sub === 'trend') safe(loadTrend)();
}

// ---------------------------------------------------------------------------
// 이벤트 바인딩
// ---------------------------------------------------------------------------
function bind() {
  // 로그인
  $('login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    $('login-error').textContent = '';
    const btn = e.submitter || e.target.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      const data = await api('login', { method: 'POST', body: { loginId: $('login-id').value, password: $('login-password').value } });
      S.csrf = data.csrfToken;
      applyMe(await api('me'));
      S.teamId = null;
      route();
    } catch (err) {
      $('login-error').textContent = err.message;
    } finally {
      btn.disabled = false;
      $('login-password').value = '';
    }
  });

  // 비밀번호 변경
  $('password-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = $('password-error');
    err.textContent = '';
    const cur = $('pw-current').value;
    const nw = $('pw-new').value;
    if (nw !== $('pw-confirm').value) { err.textContent = '새 비밀번호가 일치하지 않습니다.'; return; }
    if (nw.length < 8 || !/[A-Za-z]/.test(nw) || !/[0-9]/.test(nw)) { err.textContent = '8자 이상, 영문과 숫자를 모두 포함해야 합니다.'; return; }
    try {
      await api('password_change', { method: 'POST', body: { currentPassword: cur, newPassword: nw } });
      toast('비밀번호를 변경했습니다.');
      applyMe(await api('me'));
      route();
    } catch (ex) {
      err.textContent = ex.message;
    }
  });
  $('password-cancel').addEventListener('click', () => route());
  $('password-logout').addEventListener('click', safe(logout));
  $('btn-change-password').addEventListener('click', () => { stopPolling(); showPasswordView(false); });

  // 동의
  $('consent-check').addEventListener('change', (e) => { $('consent-submit').disabled = !e.target.checked; });
  $('consent-submit').addEventListener('click', async () => {
    try {
      await api('consent', { method: 'POST' });
      applyMe(await api('me'));
      route();
    } catch (ex) {
      $('consent-error').textContent = ex.message;
    }
  });
  $('consent-logout').addEventListener('click', safe(logout));

  // 헤더·탭
  $('btn-logout').addEventListener('click', safe(logout));
  for (const b of document.querySelectorAll('.tab')) b.addEventListener('click', () => switchTab(b.dataset.tab));
  for (const b of document.querySelectorAll('.subtab')) b.addEventListener('click', () => switchSub(b.dataset.sub));
  $('team-select').addEventListener('change', safe(async (e) => {
    S.teamId = e.target.value || null;
    S.mySelected.clear(); S.deptSelected.clear(); S.personSelected.clear(); S.relSelected.clear();
    S.view = null;
    S.trendSel = [];
    S.trendData = null;
    if (S.teamId) await api('team_select', { method: 'POST', body: { teamId: S.teamId } }).catch(() => {});
    await loadTeamData();
    if (S.tab === 'admin' && S.adminSub === 'trend') await loadTrend();
  }));
  $('btn-go-team-admin').addEventListener('click', () => {
    switchTab('admin');
    switchSub('teams');
    $('team-create-name').focus();
  });

  // 관계도 조작
  $('person-search').addEventListener('input', (e) => { S.searchText = e.target.value; applyHighlight(); });
  $('zoom-slider').addEventListener('input', (e) => { S.zoom = Number(e.target.value); applyZoom(); });
  // 부서원 정보의 부서 선택 → 관계도 필터를 해당 부서로 동기화
  $('info-dept').addEventListener('change', (e) => {
    S.infoDeptId = e.target.value;
    S.view = { ...S.view, scope: 'dept', deptId: e.target.value };
    onViewChange();
  });
  $('view-scope').addEventListener('change', (e) => { S.view = { ...S.view, scope: e.target.value }; onViewChange(); });
  $('view-dept').addEventListener('change', (e) => { S.view = { ...S.view, deptId: e.target.value }; onViewChange(); });
  $('view-person').addEventListener('change', (e) => { S.view = { ...S.view, personId: e.target.value }; onViewChange(); });

  // 내 관계 (부서원)
  $('my-rel-a').addEventListener('change', () => { renderPartyB(); syncMyForm(true); });
  $('my-rel-other').addEventListener('change', () => syncMyForm(true));
  $('my-rel-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const a = $('my-rel-a').value;
    const otherId = $('my-rel-other').value;
    const status = getRadio($('my-rel-status'));
    if (!otherId) { toast('상대를 선택하세요.', true); return; }
    if (!status) { toast('관계 상태를 선택하세요.', true); return; }
    if (!canManagePair(a, otherId)) { toast('이 관계를 관리할 권한이 없습니다.', true); return; }
    const body = a === myPersonId() ? { otherId, status } : teamBody({ a, b: otherId, status });
    const res = await mutate('relationship_upsert', body);
    toast(res.created ? '관계를 추가했습니다.' : '관계를 수정했습니다.');
  }));
  $('my-rel-filter').addEventListener('input', (e) => { S.myRelFilter = e.target.value; renderMyPanel(); applyHighlight(); });
  $('my-rel-delete').addEventListener('click', safe(async () => {
    const n = S.mySelected.size;
    if (!n || !confirm(`선택한 관계 ${n}개를 삭제할까요? 두 사람의 점수에 모두 반영됩니다.`)) return;
    await mutate('relationship_delete', teamBody({ ids: [...S.mySelected] }), { success: `관계 ${n}개를 삭제했습니다.` });
    S.mySelected.clear();
    renderMyPanel();
  }));

  // 팀 관리
  $('team-create-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const res = await mutate('team_create', { name: $('team-create-name').value, seed: $('team-create-seed').checked },
      { success: '팀을 만들었습니다.', reload: false });
    $('team-create-name').value = '';
    $('team-create-seed').checked = false;
    await api('team_select', { method: 'POST', body: { teamId: res.team.id } }).catch(() => {});
    await loadTeams(res.team.id);
    await loadTeamData();
  }));
  $('team-edit-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const max = Number($('team-edit-max').value);
    if (!Number.isInteger(max)) { toast('인원 한도는 정수여야 합니다.', true); return; }
    if (S.data && max < S.data.people.length) {
      toast(`인원 한도는 현재 부서원 수(${S.data.people.length}명)보다 낮게 설정할 수 없습니다.`, true);
      return;
    }
    document.activeElement.blur();
    await mutate('team_update', teamBody({
      name: $('team-edit-name').value,
      memberViewScope: $('team-edit-scope').value,
      maxMembers: max,
    }), { success: '팀 설정을 저장했습니다.' });
  }));
  $('team-seed').addEventListener('click', safe(async () => {
    if (!confirm('현재 팀에 샘플 부서·부서원·관계를 추가할까요? (인원 한도 이내)')) return;
    const res = await mutate('team_seed', teamBody(), { reload: true });
    toast(`샘플 부서원 ${res.added}명을 추가했습니다.`);
  }));
  $('team-delete-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const name = S.data && S.data.team.name;
    const typed = $('team-delete-confirm').value.trim();
    if (typed !== name) { toast('팀 이름이 일치하지 않습니다.', true); return; }
    if (!confirm(`'${name}' 팀과 소속 부서원 계정을 모두 삭제합니다. 계속할까요?`)) return;
    await mutate('team_delete', teamBody({ confirmName: typed }), { success: '팀을 삭제했습니다.', reload: false });
    $('team-delete-confirm').value = '';
    S.teamId = null;
    await loadTeams();
    if (S.teamId) await api('team_select', { method: 'POST', body: { teamId: S.teamId } }).catch(() => {});
    await loadTeamData();
  }));
  $('team-export').addEventListener('click', safe(async () => {
    const data = await api('team_export', { params: { teamId: S.teamId } });
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const a = h('a', { href: URL.createObjectURL(blob), download: `${data.team.name}-${new Date().toISOString().slice(0, 10)}.json` });
    document.body.append(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }));
  $('team-import-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const file = $('import-file').files[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { toast('파일이 2MB를 넘습니다.', true); return; }
    let data;
    try {
      data = JSON.parse(await file.text());
    } catch (ex) {
      toast('JSON 파일을 읽을 수 없습니다.', true);
      return;
    }
    const body = { data };
    const name = $('import-name').value.trim();
    if (name) body.name = name;
    const max = $('import-max').value;
    if (max !== '') body.maxMembers = Number(max);
    const res = await mutate('team_import', body, { success: '새 팀으로 가져왔습니다.', reload: false });
    e.target.reset();
    await api('team_select', { method: 'POST', body: { teamId: res.team.id } }).catch(() => {});
    await loadTeams(res.team.id);
    await loadTeamData();
  }));

  // 부서 관리
  $('dept-create-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    await mutate('department_create', teamBody({ name: $('dept-create-name').value }), { success: '부서를 추가했습니다.' });
    $('dept-create-name').value = '';
  }));
  $('dept-edit-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const id = e.target.dataset.id;
    if (!id) return;
    await mutate('department_update', teamBody({ departmentId: id, name: $('dept-edit-name').value, color: S.deptEditColor }),
      { success: '부서를 수정했습니다.' });
    delete e.target.dataset.id;
    renderDeptAdmin();
  }));
  $('dept-delete').addEventListener('click', safe(async () => {
    const ids = [...S.deptSelected];
    const blocked = ids.map(deptById).filter((d) => d && S.data.people.some((p) => p.departmentId === d.id));
    if (blocked.length) {
      toast(`소속 부서원이 있는 부서는 삭제할 수 없습니다: ${blocked.map((d) => d.name).join(', ')}. 먼저 부서원을 다른 부서로 이동하세요.`, true);
      return;
    }
    if (!confirm(`선택한 부서 ${ids.length}개를 삭제할까요?`)) return;
    await mutate('department_delete', teamBody({ departmentIds: ids }), { success: '부서를 삭제했습니다.' });
    S.deptSelected.clear();
    renderDeptAdmin();
  }));

  // 부서원 관리
  $('person-create-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const loginId = $('person-create-login').value.trim().toLowerCase();
    const res = await mutate('person_create', teamBody({
      name: $('person-create-name').value,
      departmentId: $('person-create-dept').value,
      position: $('person-create-position').value,
      loginId: loginId || undefined,
    }), { success: '부서원을 추가했습니다.' });
    $('person-create-name').value = '';
    $('person-create-login').value = '';
    $('person-create-position').value = 'member';
    if (res.tempPassword) showTempPassword(res.loginId, res.tempPassword);
  }));
  $('person-edit-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const id = e.target.dataset.id;
    if (!id) return;
    const before = personById(id);
    const losing = before && ((before.position === 'leader' && $('person-edit-position').value !== 'leader')
      || (before.position === 'head' && ($('person-edit-position').value !== 'head' || $('person-edit-dept').value !== before.departmentId)));
    if (losing && !confirm(`${before.name}의 직급·부서를 변경합니다.${vacancyWarning([before])}\n\n계속할까요?`)) return;
    await mutate('person_update', teamBody({
      personId: id,
      name: $('person-edit-name').value,
      departmentId: $('person-edit-dept').value,
      position: $('person-edit-position').value,
    }),
      { success: '부서원 정보를 수정했습니다.' });
    delete e.target.dataset.id;
    renderPeopleAdmin();
  }));
  $('person-check-all').addEventListener('change', (e) => {
    S.personSelected = new Set(e.target.checked ? S.data.people.map((p) => p.id) : []);
    renderPeopleAdmin();
  });
  $('person-delete').addEventListener('click', safe(async () => {
    const ids = [...S.personSelected];
    const names = ids.map(personName).join(', ');
    const warn = vacancyWarning(ids.map(personById).filter(Boolean));
    if (!confirm(`${names}\n\n선택한 부서원 ${ids.length}명과 그 사람의 모든 관계, 계정을 함께 삭제합니다.${warn}\n\n계속할까요?`)) return;
    await mutate('person_delete', teamBody({ personIds: ids }), { success: '부서원을 삭제했습니다.' });
    S.personSelected.clear();
    renderPeopleAdmin();
  }));
  const selectedPersonId = () => $('person-edit-form').dataset.id;
  $('btn-account-create').addEventListener('click', safe(async () => {
    const loginId = $('person-account-login').value.trim().toLowerCase();
    if (!loginId) { toast('로그인 ID를 입력하세요.', true); return; }
    const res = await mutate('account_create', teamBody({ personId: selectedPersonId(), loginId }), { success: '계정을 발급했습니다.' });
    $('person-account-login').value = '';
    showTempPassword(res.loginId, res.tempPassword);
  }));
  $('btn-account-reset').addEventListener('click', safe(async () => {
    if (!confirm('비밀번호를 초기화하고 새 임시 비밀번호를 발급할까요?')) return;
    const res = await mutate('account_reset_password', teamBody({ personId: selectedPersonId() }));
    showTempPassword(res.loginId, res.tempPassword);
  }));
  $('btn-account-unlock').addEventListener('click', safe(async () => {
    await mutate('account_unlock', teamBody({ personId: selectedPersonId() }), { success: '로그인 잠금을 해제했습니다.' });
  }));
  $('btn-account-toggle').addEventListener('click', safe(async () => {
    const p = personById(selectedPersonId());
    if (!p || !p.account) return;
    const active = !p.account.active;
    if (!active && !confirm(`${p.name}의 계정을 비활성화할까요? 사용 중인 세션도 즉시 차단됩니다.`)) return;
    await mutate('account_set_active', teamBody({ personId: p.id, active }), { success: active ? '계정을 활성화했습니다.' : '계정을 비활성화했습니다.' });
  }));

  // 관계 관리 (관리자)
  $('admin-rel-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const a = $('admin-rel-a').value;
    const b = $('admin-rel-b').value;
    const status = getRadio($('admin-rel-status'));
    if (!a || !b) { toast('두 부서원을 선택하세요.', true); return; }
    if (a === b) { toast('서로 다른 두 사람을 선택하세요.', true); return; }
    if (!status) { toast('관계 상태를 선택하세요.', true); return; }
    const res = await mutate('relationship_upsert', teamBody({ a, b, status }));
    toast(res.created ? '관계를 추가했습니다.' : '관계를 수정했습니다.');
  }));
  const syncAdminRelStatus = () => {
    const a = $('admin-rel-a').value;
    const b = $('admin-rel-b').value;
    const rel = a && b && S.data.relationships.find((r) => r.id === relId(a, b));
    if (rel) setRadio($('admin-rel-status'), rel.status);
  };
  $('admin-rel-a').addEventListener('change', syncAdminRelStatus);
  $('admin-rel-b').addEventListener('change', syncAdminRelStatus);
  $('admin-rel-filter').addEventListener('input', (e) => { S.adminRelFilter = e.target.value; renderRelAdmin(); applyHighlight(); });
  $('rel-check-all').addEventListener('change', (e) => {
    S.relSelected.clear();
    if (e.target.checked) {
      // 현재 필터로 보이는 행만 선택
      const q = S.adminRelFilter.trim().toLowerCase();
      for (const r of S.data.relationships) {
        if (!q || personName(r.a).toLowerCase().includes(q) || personName(r.b).toLowerCase().includes(q)) S.relSelected.add(r.id);
      }
    }
    renderRelAdmin();
  });
  $('admin-rel-bulk-status').addEventListener('change', safe(async (e) => {
    const status = e.target.value;
    e.target.value = '';
    if (!status || !S.relSelected.size) return;
    const rels = S.data.relationships.filter((r) => S.relSelected.has(r.id));
    if (!confirm(`선택한 관계 ${rels.length}개를 '${STATUS[status].text}'(으)로 변경할까요?`)) return;
    for (const r of rels) {
      await mutate('relationship_upsert', teamBody({ a: r.a, b: r.b, status }), { reload: false });
    }
    toast(`관계 ${rels.length}개를 수정했습니다.`);
    await loadTeamData();
  }));
  $('admin-rel-delete').addEventListener('click', safe(async () => {
    const n = S.relSelected.size;
    if (!n || !confirm(`선택한 관계 ${n}개를 삭제할까요?`)) return;
    await mutate('relationship_delete', teamBody({ ids: [...S.relSelected] }), { success: `관계 ${n}개를 삭제했습니다.` });
    S.relSelected.clear();
    renderRelAdmin();
  }));

  // 활동 로그 검색
  $('audit-filter').addEventListener('submit', (e) => { e.preventDefault(); loadAudit(1); });
  $('audit-reset').addEventListener('click', () => { $('audit-filter').reset(); loadAudit(1); });
  $('audit-prev').addEventListener('click', () => loadAudit(S.auditPage - 1));
  $('audit-next').addEventListener('click', () => loadAudit(S.auditPage + 1));

  // 일괄 등록
  $('bulk-form').addEventListener('submit', safe(async (e) => { e.preventDefault(); await submitBulk(); }));
  $('bulk-template').addEventListener('click', () => downloadCsv([
    ['이름', '부서', '직급', '로그인ID'],
    ['홍길동', '개발', '팀장', 'gildong.hong'],
    ['김영희', '개발', '부서장', ''],
    ['이철수', '기획', '부서원', 'chulsoo.lee'],
  ], '부서원-일괄등록-양식.csv'));
  $('bulk-close').addEventListener('click', () => { clear($('bulk-table')); S.bulkAccounts = null; closeDialog($('bulk-dialog')); });
  $('bulk-download').addEventListener('click', () => downloadCsv(
    [['이름', '로그인ID', '임시 비밀번호'], ...(S.bulkAccounts || []).map((a) => [a.name, a.loginId, a.tempPassword])],
    `임시비밀번호-${new Date().toISOString().slice(0, 10)}.csv`,
  ));
  $('bulk-dialog').addEventListener('cancel', (e) => e.preventDefault());
  $('history-close').addEventListener('click', () => closeDialog($('history-dialog')));

  // 관리자 계정
  $('admin-create-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const loginId = $('admin-create-login').value.trim().toLowerCase();
    if (!confirm(`관리자 '${loginId}'를 추가할까요? 관리자는 모든 팀의 관계와 입력자를 볼 수 있습니다.`)) return;
    const res = await mutate('admin_create', { loginId }, { reload: false, success: '관리자를 추가했습니다.' });
    $('admin-create-login').value = '';
    showTempPassword(res.loginId, res.tempPassword);
    await loadAdmins();
  }));

  // 점수 추이
  $('trend-months').addEventListener('change', safe(loadTrend));
  $('trend-add').addEventListener('change', (e) => { if (e.target.value) addTrendPerson(e.target.value); });
  window.addEventListener('resize', () => { if (S.adminSub === 'trend' && S.tab === 'admin') renderTrend(); });

  // 관계도 내보내기
  const exporting = (fn) => async () => {
    try { await fn(); } catch (e) { toast(e.message || '저장하지 못했습니다.', true); }
  };
  $('export-png').addEventListener('click', exporting(exportPng));
  $('export-pdf').addEventListener('click', exporting(exportPdf));

  // 임시 비밀번호 대화상자
  $('temp-pw-close').addEventListener('click', () => {
    $('temp-pw-value').textContent = '';
    const dlg = $('temp-pw-dialog');
    if (typeof dlg.close === 'function') dlg.close(); else dlg.removeAttribute('open');
  });
  $('temp-pw-copy').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText($('temp-pw-value').textContent);
      toast('복사했습니다.');
    } catch (e) {
      toast('복사하지 못했습니다. 직접 선택해 복사하세요.', true);
    }
  });
  $('temp-pw-dialog').addEventListener('cancel', (e) => e.preventDefault());
}

async function logout() {
  stopPolling();
  try {
    await api('logout', { method: 'POST' });
  } catch (e) { /* 이미 만료된 세션 */ }
  S.me = null;
  S.data = null;
  S.teamId = null;
  S.csrf = '';
  S.view = null;
  showLogin('로그아웃했습니다.');
}

// ---------------------------------------------------------------------------
// 시작
// ---------------------------------------------------------------------------
async function boot() {
  renderIcons();
  bind();
  if ('ResizeObserver' in window) new ResizeObserver(alignSideColumn).observe(document.querySelector('.chart-column'));
  try {
    applyMe(await api('me'));
    route();
  } catch (e) {
    showLogin(e.status === 401 ? '' : e.message);
  }
}

document.addEventListener('DOMContentLoaded', boot);
