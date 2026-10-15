# 운영 문서

## 1. 배치

```
/srv/gcba/            # 저장소 (예시 경로)
  public/             # ← 웹 서버 document root는 여기만
  tools/  tests/
  data/               # 웹 루트 밖, PHP 실행 사용자만 읽기/쓰기 (0700)
/var/backups/gcba/    # 백업 (웹 루트·data 밖, 0700)
```

- `data/`는 **로컬 디스크**에 둡니다 (NFS 등 네트워크 파일시스템은 `flock`이 불안정할 수 있음).
- 권한: `chown -R www-data: /srv/gcba/data && chmod 700 /srv/gcba/data`
- 리버스 프록시 뒤에서 실제 클라이언트 IP·HTTPS를 인식해야 하면 PHP-FPM 환경 변수 `GCBA_TRUST_PROXY=1`을 설정합니다 (신뢰할 수 있는 프록시만 `X-Forwarded-*`를 설정하는 경우에만).

## 2. 웹 서버 설정

### nginx + PHP-FPM

```nginx
server {
    listen 80;
    server_name gcba.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name gcba.example.com;
    root /srv/gcba/public;
    index index.html;

    ssl_certificate     /etc/letsencrypt/live/gcba.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gcba.example.com/privkey.pem;

    server_tokens off;
    client_max_body_size 3m;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "same-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;

    # API만 PHP로 실행 (auth.php·storage.php 직접 호출 차단)
    location = /api/index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api/index.php;
        fastcgi_param GCBA_DATA_DIR /srv/gcba/data;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

### Apache

```apache
<VirtualHost *:443>
    ServerName gcba.example.com
    DocumentRoot /srv/gcba/public
    SSLEngine on
    # SSLCertificateFile / SSLCertificateKeyFile ...

    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "same-origin"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    SetEnv GCBA_DATA_DIR /srv/gcba/data

    <FilesMatch "^(auth|storage)\.php$">
        Require all denied
    </FilesMatch>
</VirtualHost>
<VirtualHost *:80>
    ServerName gcba.example.com
    Redirect permanent / https://gcba.example.com/
</VirtualHost>
```

> `data/`를 부득이하게 웹 루트 안에 둘 경우 Apache는 `data/.htaccess`에 `Require all denied`, nginx는 `location ^~ /data/ { deny all; }`이 필요합니다. 기본 배치(웹 루트 밖)에서는 필요 없습니다.

### PHP (php.ini / FPM pool)

```ini
display_errors = Off
log_errors = On
error_log = /var/log/php/gcba-error.log   ; 웹 루트 밖
expose_php = Off
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Lax
session.use_strict_mode = 1
session.gc_maxlifetime = 7200            ; 앱의 세션 유휴 만료(120분)와 동일
post_max_size = 3M
```

앱도 실행 시 세션 관련 값을 직접 설정하지만, 서버 기본값을 맞춰 두면 다른 PHP 앱의 세션 GC가 먼저 세션을 지우는 일을 막을 수 있습니다. 세션 저장 경로를 이 앱 전용으로 분리하는 것을 권장합니다 (`session.save_path`).

## 3. 관리자 계정 (CLI 전용, 서버 셸 필요)

```bash
sudo -u www-data php tools/admin_cli.php create-admin <loginId> [--generate]
sudo -u www-data php tools/admin_cli.php reset-password <loginId> [--generate]
sudo -u www-data php tools/admin_cli.php unlock <loginId>
sudo -u www-data php tools/admin_cli.php list-admins
```

생성한 파일의 소유자가 웹 서버 사용자가 되도록 PHP 실행 사용자로 실행합니다. 관리자는 모든 팀의 관계와 입력자를 볼 수 있으므로 최소 인원만 둡니다.

## 4. 백업 및 복구

### 백업 (매일)

```cron
30 3 * * * cd /srv/gcba && GCBA_DATA_DIR=/srv/gcba/data php tools/backup.php /var/backups/gcba >> /var/log/gcba-backup.log 2>&1
```

- `data/` 전체를 `gcba-data-YYYYMMDD-HHMMSS.tar.gz`로 묶습니다 (쓰기 잠금을 잡은 상태로 묶어 파일 간 일관성 보장, 권한 0600).
- 14일이 지난 백업과 보관 기간(12개월)이 지난 활동 로그를 삭제합니다.
- 비밀번호 해시가 들어 있으므로 백업 폴더는 소유자만 접근할 수 있게 하고, 가능하면 다른 서버·스토리지에도 사본을 둡니다 (예: `rsync`).
- `data/trash/`는 팀 삭제 실수에 대비한 보관일 뿐 백업을 대체하지 않습니다.

### 복구 절차

1. 서비스 중지 (또는 점검 페이지로 전환): `systemctl stop php8.3-fpm`
2. 현재 data 보관: `mv /srv/gcba/data /srv/gcba/data.broken-$(date +%Y%m%d)`
3. 백업 해제: `tar xzf /var/backups/gcba/gcba-data-YYYYMMDD-HHMMSS.tar.gz -C /srv/gcba` (압축 안의 경로는 `data/...`)
4. 권한 확인: `chown -R www-data: /srv/gcba/data && chmod -R go-rwx /srv/gcba/data`
5. 서비스 재개 후 관리자 로그인·팀 데이터 조회로 확인

분기마다 1회 별도 서버에서 위 절차로 복구 테스트를 합니다.

### 삭제된 팀 복구

`data/trash/{teamId}_{시각}.json` 파일을 내려받아 관리자 탭 > 팀 관리 > 가져오기로 새 팀으로 복원합니다. 계정은 복원되지 않으므로(부서원은 계정 없는 상태) 필요하면 다시 발급합니다.

## 5. 활동 로그

- `data/audit/YYYY-MM.log` (JSON Lines). 관리자 탭에서 최근 100건을 볼 수 있습니다.
- 보관 기간은 `storage.php`의 `AUDIT_RETENTION_MONTHS`(기본 12개월)이며 백업 스크립트가 지난 파일을 삭제합니다. 바꾸면 개인정보 안내문(`PRIVACY_NOTICE`)의 보관 기간 문구와 `PRIVACY_NOTICE_VERSION`도 함께 갱신합니다.

## 6. 장애 시 확인할 것

| 증상 | 확인 |
|---|---|
| 500 "데이터 파일이 손상되었습니다" | PHP 오류 로그의 `[gcba] JSON decode failed` 경로 확인 → 최근 백업에서 해당 파일만 복원 |
| 모든 요청이 500 "데이터 폴더를 만들 수 없습니다" | `data/` 경로·권한 (`GCBA_DATA_DIR`) |
| 로그인 후 곧바로 로그아웃됨 | HTTPS가 아닌데 `session.cookie_secure=1`, 또는 프록시 뒤에서 `GCBA_TRUST_PROXY` 미설정 |
| 관리자 잠김 | `php tools/admin_cli.php unlock <loginId>` |
| DB 전환 검토 | 동시 사용자 100명 초과, 팀 파일 5MB 초과, 저장 API 1초 초과가 반복될 때 |
