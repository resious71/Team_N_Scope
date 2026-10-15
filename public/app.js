/* Good Cop vs Bad Apple — 클라이언트 로직 (모든 데이터는 PHP API를 통해서만 읽고 씀, 브라우저 저장소 미사용) */
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

const AUDIT_LABELS = {
  login: '로그인', login_failed: '로그인 실패', login_locked: '잠금 상태 로그인 시도', login_inactive: '비활성 계정 로그인 시도',
  logout: '로그아웃', password_change: '비밀번호 변경', consent: '개인정보 동의',
  team_create: '팀 생성', team_update: '팀 수정', team_delete: '팀 삭제', team_seed: '샘플 데이터', team_export: '팀 내보내기', team_import: '팀 가져오기',
  department_create: '부서 추가', department_update: '부서 수정', department_delete: '부서 삭제',
  person_create: '부서원 추가', person_update: '부서원 수정', person_delete: '부서원 삭제',
  account_create: '계정 발급', account_reset_password: '비밀번호 초기화', account_activate: '계정 활성화', account_deactivate: '계정 비활성화', account_unlock: '잠금 해제',
  relationship_create: '관계 추가', relationship_update: '관계 수정', relationship_delete: '관계 삭제',
  admin_create_cli: '관리자 생성(CLI)', admin_reset_password_cli: '관리자 비밀번호 초기화(CLI)', admin_unlock_cli: '관리자 잠금 해제(CLI)',
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
  const url = new URL('api/index.php', location.href);
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
  $('panel-ranking').hidden = !isAdmin();
  $('panel-my-relations').hidden = isAdmin();
  $('my-score').hidden = isAdmin();
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
function renderAll() {
  const noTeam = !S.teamId;
  $('no-team-guide').hidden = !(noTeam && isAdmin());
  $('diagram-layout').hidden = noTeam;
  pruneSelections();
  renderChart();
  renderLegend();
  renderSidePanels();
  if (isAdmin()) renderAdmin();
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
  const people = orderedPeople();
  if (!S.data || !people.length) {
    empty.textContent = isAdmin()
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
  const rels = S.data.relationships.filter((r) => S.statusFilter[r.status] && pos.has(r.a) && pos.has(r.b));

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
      const score = isAdmin() ? ` · 점수 ${fmtScore(p.score)}` : '';
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
    ...(isAdmin() ? matchIds(S.adminRelFilter) : matchIds(S.myRelFilter).filter((id) => id !== myPersonId())),
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

window.addEventListener('resize', () => { if (S.data) applyZoom(); });

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
  if (isAdmin()) renderRanking();
  else renderMyPanel();
  renderDeptInfo();
  renderDeptStats();
}

function renderRanking() {
  const { top, bottom } = computeRanking(S.data.people);
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
  $('my-score').textContent = `내 점수 ${fmtScore(S.data.me ? S.data.me.score : 0)}`;
  const others = orderedPeople().filter((p) => p.id !== me);
  const alone = others.length === 0;
  $('my-alone').hidden = !alone;
  $('my-rel-form').hidden = alone;

  const sel = $('my-rel-other');
  const prev = sel.value;
  clear(sel).append(h('option', { value: '', text: '상대 선택' }));
  for (const p of others) sel.append(h('option', { value: p.id, text: personLabel(p) }));
  sel.value = others.some((p) => p.id === prev) ? prev : '';
  renderStatusRadios($('my-rel-status'), 'my-status');
  syncMyForm(false);

  const mine = myRelationships();
  $('my-rel-count').textContent = `입력한 관계 ${mine.length} / 최대 ${Math.max(S.data.people.length - 1, 0)}`;
  const q = S.myRelFilter.trim().toLowerCase();
  const rows = mine
    .map((r) => ({ r, other: personById(otherOf(r, me)) }))
    .filter((x) => x.other && (!q || x.other.name.toLowerCase().includes(q)))
    .sort((x, y) => collator.compare(x.other.name, y.other.name));
  const list = clear($('my-rel-list'));
  if (!rows.length) list.append(h('li', { class: 'empty', text: mine.length ? '검색 결과가 없습니다.' : '아직 입력한 관계가 없습니다.' }));
  for (const { r, other } of rows) {
    const cb = h('input', { type: 'checkbox', checked: S.mySelected.has(r.id), 'aria-label': `${other.name} 선택` });
    cb.addEventListener('click', (e) => e.stopPropagation());
    cb.addEventListener('change', () => {
      if (cb.checked) S.mySelected.add(r.id); else S.mySelected.delete(r.id);
      li.classList.toggle('selected', cb.checked);
      $('my-rel-delete').disabled = S.mySelected.size === 0;
    });
    const pick = () => {
      sel.value = other.id;
      syncMyForm(true);
      sel.focus();
    };
    const li = h('li', { class: S.mySelected.has(r.id) ? 'selected' : '', tabindex: '0', title: '클릭하면 상태를 수정할 수 있습니다' }, [
      cb,
      h('span', { class: 'dot', style: { background: deptColor(other.departmentId) } }),
      h('span', { class: 'grow', text: `나 ↔ ${other.name}` }),
      h('span', { class: `status-chip ${r.status}`, text: STATUS[r.status].label }),
    ]);
    li.addEventListener('click', pick);
    li.addEventListener('keydown', (e) => { if (e.key === 'Enter') pick(); });
    list.append(li);
  }
  $('my-rel-delete').disabled = S.mySelected.size === 0;
}

/** 이미 관계가 있는 상대를 고르면 현재 상태를 표시하고 버튼을 "수정"으로 */
function syncMyForm(fromUser) {
  const other = $('my-rel-other').value;
  const me = myPersonId();
  const rel = other && S.data.relationships.find((r) => r.id === relId(me, other));
  if (rel) setRadio($('my-rel-status'), rel.status);
  else if (fromUser) setRadio($('my-rel-status'), null);
  $('my-rel-save').textContent = rel ? '수정' : '저장';
}

function relId(a, b) {
  return a < b ? `${a}_${b}` : `${b}_${a}`;
}

function renderDeptInfo() {
  const sel = $('info-dept');
  const depts = S.data.departments;
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
      h('td', { text: t.memberViewScope === 'team' ? '팀 전체' : '본인 관계만' }),
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

function renderPeopleAdmin() {
  const t = S.data.team;
  const n = S.data.people.length;
  $('person-count').textContent = `${n} / ${t.maxMembers}`;
  const full = n >= t.maxMembers;
  $('person-limit-msg').hidden = !full;
  $('person-create-btn').disabled = full || !S.data.departments.length;

  const body = clear($('person-table'));
  const people = orderedPeople();
  if (!people.length) body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '5', text: '부서원이 없습니다.' })]));
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
  if (!rows.length) body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '6', text: '관계가 없습니다.' })]));
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

async function loadAudit() {
  const body = clear($('audit-table'));
  body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '6', text: '불러오는 중…' })]));
  try {
    const { entries } = await api('audit_log');
    clear(body);
    if (!entries.length) body.append(h('tr', {}, [h('td', { class: 'empty', colspan: '6', text: '기록이 없습니다.' })]));
    for (const e of entries) {
      body.append(h('tr', {}, [
        h('td', { text: fmtDate(e.at) }),
        h('td', { text: e.userLabel }),
        h('td', { text: AUDIT_LABELS[e.action] || e.action }),
        h('td', { text: e.teamName }),
        h('td', { text: e.target || '-' }),
        h('td', { text: e.summary || '' }),
      ]));
    }
  } catch (e) {
    clear(body).append(h('tr', {}, [h('td', { class: 'empty', colspan: '6', text: e.message })]));
  }
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
  if (sub === 'audit') loadAudit();
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
    if (S.teamId) await api('team_select', { method: 'POST', body: { teamId: S.teamId } }).catch(() => {});
    await loadTeamData();
  }));
  $('btn-go-team-admin').addEventListener('click', () => {
    switchTab('admin');
    switchSub('teams');
    $('team-create-name').focus();
  });

  // 관계도 조작
  $('person-search').addEventListener('input', (e) => { S.searchText = e.target.value; applyHighlight(); });
  $('zoom-slider').addEventListener('input', (e) => { S.zoom = Number(e.target.value); applyZoom(); });
  $('info-dept').addEventListener('change', (e) => { S.infoDeptId = e.target.value; renderDeptInfo(); });

  // 내 관계 (부서원)
  $('my-rel-other').addEventListener('change', () => syncMyForm(true));
  $('my-rel-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const otherId = $('my-rel-other').value;
    const status = getRadio($('my-rel-status'));
    if (!otherId) { toast('상대를 선택하세요.', true); return; }
    if (!status) { toast('관계 상태를 선택하세요.', true); return; }
    const res = await mutate('relationship_upsert', { otherId, status });
    toast(res.created ? '관계를 추가했습니다.' : '관계를 수정했습니다.');
  }));
  $('my-rel-filter').addEventListener('input', (e) => { S.myRelFilter = e.target.value; renderMyPanel(); applyHighlight(); });
  $('my-rel-delete').addEventListener('click', safe(async () => {
    const n = S.mySelected.size;
    if (!n || !confirm(`선택한 관계 ${n}개를 삭제할까요? 두 사람의 점수에 모두 반영됩니다.`)) return;
    await mutate('relationship_delete', { ids: [...S.mySelected] }, { success: `관계 ${n}개를 삭제했습니다.` });
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
      loginId: loginId || undefined,
    }), { success: '부서원을 추가했습니다.' });
    $('person-create-name').value = '';
    $('person-create-login').value = '';
    if (res.tempPassword) showTempPassword(res.loginId, res.tempPassword);
  }));
  $('person-edit-form').addEventListener('submit', safe(async (e) => {
    e.preventDefault();
    const id = e.target.dataset.id;
    if (!id) return;
    await mutate('person_update', teamBody({ personId: id, name: $('person-edit-name').value, departmentId: $('person-edit-dept').value }),
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
    if (!confirm(`${names}\n\n선택한 부서원 ${ids.length}명과 그 사람의 모든 관계, 계정을 함께 삭제합니다. 계속할까요?`)) return;
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

  $('audit-refresh').addEventListener('click', loadAudit);

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
  showLogin('로그아웃했습니다.');
}

// ---------------------------------------------------------------------------
// 시작
// ---------------------------------------------------------------------------
async function boot() {
  bind();
  try {
    applyMe(await api('me'));
    route();
  } catch (e) {
    showLogin(e.status === 401 ? '' : e.message);
  }
}

document.addEventListener('DOMContentLoaded', boot);
