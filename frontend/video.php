<?php
/**
 * Video stream: Veritabanındaki sadece dosya adı (f=) ile uploads/videos/ klasörünü
 * birleştirip videoyu sunar. Accept-Ranges ve Content-Length ile tarayıcı uyumlu oynatma.
 */
error_reporting(0);
ini_set('display_errors', '0');

$fileName = isset($_GET['f']) ? $_GET['f'] : '';
$fileName = basename($fileName);

if ($fileName === '' || preg_match('/[^a-zA-Z0-9_\-\.]/', $fileName)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Geçersiz dosya adı.';
    exit;
}

$uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'uploads');
$videosDir = $uploadsRoot ? realpath($uploadsRoot . DIRECTORY_SEPARATOR . 'videos') : null;

$filePath = null;
if ($videosDir && is_dir($videosDir)) {
    $destinationPath = $videosDir . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($destinationPath) && is_readable($destinationPath)) {
        $filePath = $destinationPath;
    }
}
if (!$filePath && $uploadsRoot && is_dir($uploadsRoot)) {
    $destinationPath = $uploadsRoot . DIRECTORY_SEPARATOR . $fileName;
    if (is_file($destinationPath) && is_readable($destinationPath)) {
        $filePath = $destinationPath;
    }
}
if (!$filePath) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Video dosyası bulunamadı: ' . htmlspecialchars($fileName);
    exit;
}

$fileSize = filesize($filePath);
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mimeMap = [
    'mp4' => 'video/mp4',
    'm4v' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime',
    'avi' => 'video/x-msvideo',
    'mkv' => 'video/x-matroska',
];
$contentType = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'video/mp4';

if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: ' . $contentType);
header('Accept-Ranges: bytes');
header('Content-Length: ' . $fileSize);
header('Cache-Control: public, max-age=3600');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $rangeMatch)) {
    $start = (int) $rangeMatch[1];
    $end = $rangeMatch[2] !== '' ? (int) $rangeMatch[2] : $fileSize - 1;
    $end = min($end, $fileSize - 1);
    $length = $end - $start + 1;
    http_response_code(206);
    header('Content-Length: ' . $length);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    $fp = fopen($filePath, 'rb');
    if ($fp) {
        fseek($fp, $start);
        echo fread($fp, $length);
        fclose($fp);
    }
    exit;
}

readfile($filePath);
