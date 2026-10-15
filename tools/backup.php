<?php
/**
 * data/ 백업 (cron에서 매일 실행).
 *
 *   php tools/backup.php [백업 폴더]
 *
 * 백업 폴더 기본값: 환경 변수 GCBA_BACKUP_DIR, 없으면 저장소 루트의 backups/
 * (웹 루트·data/ 밖이어야 함). 최근 BACKUP_KEEP_DAYS(14)일분만 보관하고,
 * 보관 기간이 지난 활동 로그(audit/*.log)도 함께 정리한다.
 *
 * crontab 예: 30 3 * * * cd /srv/gcba && php tools/backup.php >> /var/log/gcba-backup.log 2>&1
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/public/api/storage.php';

$dest = $argv[1] ?? (getenv('GCBA_BACKUP_DIR') ?: dirname(__DIR__) . '/backups');
$dest = rtrim($dest, '/');
$dataDir = realpath(data_dir());
if ($dataDir === false) {
    fwrite(STDERR, "data 폴더가 없습니다: " . data_dir() . PHP_EOL);
    exit(1);
}
umask(0077);
if (!is_dir($dest) && !mkdir($dest, 0700, true)) {
    fwrite(STDERR, "백업 폴더를 만들 수 없습니다: {$dest}" . PHP_EOL);
    exit(1);
}
$destReal = realpath($dest);
if (str_starts_with($destReal . '/', $dataDir . '/')) {
    fwrite(STDERR, "백업 폴더는 data/ 밖이어야 합니다." . PHP_EOL);
    exit(1);
}

$pruned = prune_audit();
$stamp = date('Ymd-His');
$archive = "{$destReal}/gcba-data-{$stamp}.tar.gz";
$tarPath = "{$destReal}/gcba-data-{$stamp}.tar";

// 쓰기 잠금을 잡은 상태에서 묶어 파일 간 일관성을 보장 (보통 수 초 이내)
with_lock(function () use ($dataDir, $tarPath) {
    $tar = new PharData($tarPath);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dataDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        $path = $file->getPathname();
        if (!$file->isFile() || str_contains($path, '.tmp.')) {
            continue;
        }
        $tar->addFile($path, 'data/' . substr($path, strlen($dataDir) + 1));
    }
    $tar->compress(Phar::GZ);
    unset($tar);
});
@unlink($tarPath);
chmod($archive, 0600);

// 보관 기간이 지난 백업 삭제
$removed = 0;
$cutoff = time() - BACKUP_KEEP_DAYS * 86400;
foreach (glob("{$destReal}/gcba-data-*.tar.gz") ?: [] as $f) {
    if (filemtime($f) < $cutoff && @unlink($f)) {
        $removed++;
    }
}

echo date('c') . " 백업 완료: {$archive} (" . filesize($archive) . " bytes), 오래된 백업 {$removed}개·활동 로그 {$pruned}개 삭제" . PHP_EOL;
