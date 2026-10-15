# 기여 안내

Team Scope에 관심을 가져 주셔서 감사합니다. 버그 제보, 기능 제안, 문서 개선, 코드 기여 모두 환영합니다.

## 시작하기 전에

- 보안 취약점은 공개 이슈로 올리지 말고 [SECURITY.md](SECURITY.md)의 방법으로 알려 주세요.
- 큰 변경(새 기능, API·데이터 구조 변경)은 먼저 이슈로 방향을 논의해 주세요.
- 이 앱은 사람 사이의 관계 평가라는 **민감한 정보**를 다룹니다. 노출 범위나 권한에 영향을 주는 변경은 그 이유와 영향을 PR에 꼭 적어 주세요.

## 개발 환경

필요한 것: PHP 8.1 이상 (확장: `json`, `session`, `curl`(테스트용), `phar`(백업용)). Node.js나 빌드 도구는 필요 없습니다.

```bash
git clone https://github.com/resious71/Team_N_Scope.git
cd Team_N_Scope
php -S localhost:8000 router.php
# http://localhost:8000 → admin / admin 로그인
```

개발 중에는 `GCBA_DATA_DIR` 환경 변수로 별도 데이터 폴더를 쓰면 편합니다.

```bash
GCBA_DATA_DIR=/tmp/team-scope-dev php -S localhost:8000 router.php
```

## 테스트

PR을 보내기 전에 API 통합 테스트가 모두 통과하는지 확인해 주세요.

```bash
php tests/api_test.php
```

임시 데이터 폴더로 서버를 띄워 인증·권한·저장·백업까지 검사합니다. 서버 로그가 필요하면 `GCBA_TEST_LOG=/tmp/test.log`를 지정하세요.
새 API나 권한 규칙을 추가하면 테스트도 함께 추가해 주세요.

문법 검사:

```bash
for f in public/api/*.php tools/*.php index.php router.php; do php -l "$f"; done
node --check public/app.js   # Node.js가 있다면
```

## 코드 규칙

- **권한은 서버에서 검사**합니다. 화면에서 숨기는 것만으로 권한을 처리하지 마세요.
- 사용자 입력은 DOM에 `textContent`로만 출력합니다 (`innerHTML` 금지).
- 데이터 쓰기는 `with_lock()` / `mutate_team()` 안에서 하고, 파일은 `write_json_atomic()`으로 씁니다.
- 외부 CDN·라이브러리를 추가하지 않습니다 (CSP `script-src 'self'`). 필요한 라이브러리는 `public/lib/`에 둡니다.
- 주석과 화면 문구는 한국어로 씁니다. 주변 코드의 스타일(들여쓰기, 이름 규칙)을 따라 주세요.
- 설정값은 `public/api/storage.php` 상단 상수에 둡니다.

## 커밋과 PR

1. `main`에서 브랜치를 만듭니다 (예: `feature/score-export`, `fix/login-lock`).
2. 변경 단위로 커밋합니다. 커밋 메시지 첫 줄은 무엇을 왜 바꿨는지 짧게 씁니다.
3. 화면이 바뀌면 PR에 스크린샷을 붙여 주세요.
4. 사용자에게 보이는 변경은 [CHANGELOG.md](CHANGELOG.md)의 `[Unreleased]`에 적어 주세요.
5. PR 양식의 체크리스트를 채워 주세요.

## 릴리스 (관리자용)

1. `CHANGELOG.md`의 `[Unreleased]` 내용을 새 버전 항목으로 옮기고, `public/api/storage.php`의 `APP_VERSION`을 올립니다.
2. `main`에 합친 뒤 태그를 만듭니다: `git tag -a vX.Y.Z -m "Team Scope vX.Y.Z" && git push origin vX.Y.Z`
3. GitHub의 Releases에서 해당 태그로 릴리스를 만들고 변경 이력을 붙입니다.

## 행동 강령

모든 참여자는 [행동 강령](CODE_OF_CONDUCT.md)을 지켜 주세요.
