<?php
/**
 * Video ekleme/güncelleme işlemi (POST ile videoOperations.php'den yönlendirilir)
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/dbConnection.php';
require_once __DIR__ . '/includes/authHelper.php';

$currentUser = checkLogin();
requireRole(['admin', 'editor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['save_video'])) {
    header('Location: videoOperations.php');
    exit;
}

$videoId = isset($_POST['video_id']) ? (int) $_POST['video_id'] : null;
$videoTitle = sanitize($_POST, 'title');
$videoSlug = sanitize($_POST, 'slug');
$videoUrlFromPost = trim((string) ($_POST['video_url'] ?? ''));
if ($videoUrlFromPost !== '' && strlen($videoUrlFromPost) > 2048) {
    $videoUrlFromPost = '';
}
$thumbnailUrl = sanitize($_POST, 'thumbnail_url');
$description = sanitize($_POST, 'description');
$categoryIds = [];
if (isset($_POST['category_ids']) && is_array($_POST['category_ids'])) {
    $categoryIds = array_map('intval', $_POST['category_ids']);
} elseif (!empty($_POST['category_ids'])) {
    $categoryIds = is_array($_POST['category_ids']) ? array_map('intval', $_POST['category_ids']) : [(int) $_POST['category_ids']];
}
$categoryIds = array_filter($categoryIds, function ($id) { return $id > 0; });
$galleryRaw = $_POST['gallery_json'] ?? '[]';
$galleryDecoded = json_decode($galleryRaw, true);
$galleryJson = is_array($galleryDecoded) ? json_encode(array_values(array_filter(array_map(function ($x) {
    $url = is_string($x) ? $x : (isset($x['url']) ? $x['url'] : '');
    return $url ? ['url' => $url] : null;
}, $galleryDecoded)))) : '[]';

if (!$videoTitle) {
    setFlash('error', 'Başlık zorunludur.');
    header('Location: videoOperations.php?' . ($videoId ? 'edit=' . $videoId : 'add=1'));
    exit;
}

// ——— Video yükleme: proje kökü uploads/videos/ (tam yol, otomatik klasör)
$videoUrl = null;
$projectRoot = dirname(__DIR__);
$videoUploadDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'videos' . DIRECTORY_SEPARATOR;

$uploadError = $_FILES['video_file']['error'] ?? null;
$hasUpload = !empty($_FILES['video_file']['tmp_name']) && is_uploaded_file($_FILES['video_file']['tmp_name']);
$canSkipFile = $videoId || $videoUrlFromPost !== '';

if ($uploadError !== null && $uploadError !== UPLOAD_ERR_OK) {
    if (($uploadError === UPLOAD_ERR_NO_FILE && $canSkipFile)) {
        // Dosya yok ama URL dolu veya düzenlemede mevcut video kalacak
    } else {
        $uploadErrorMessages = [
            UPLOAD_ERR_INI_SIZE => 'Dosya boyutu PHP limitini aşıyor (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'Dosya boyutu form limitini aşıyor.',
            UPLOAD_ERR_PARTIAL => 'Dosya kısmen yüklendi. Lütfen tekrar deneyin.',
            UPLOAD_ERR_NO_FILE => 'Video dosyası seçin veya yukarıdaki Video URL alanını doldurun.',
            UPLOAD_ERR_NO_TMP_DIR => 'Sunucuda geçici klasör bulunamadı.',
            UPLOAD_ERR_CANT_WRITE => 'Dosya diske yazılamadı. Klasör yazma iznini kontrol edin.',
            UPLOAD_ERR_EXTENSION => 'Bir PHP eklentisi yüklemeyi durdurdu.',
        ];
        setFlash('error', 'Video yükleme hatası: ' . ($uploadErrorMessages[$uploadError] ?? 'Hata kodu: ' . $uploadError));
        header('Location: videoOperations.php?' . ($videoId ? 'edit=' . $videoId : 'add=1'));
        exit;
    }
}

if ($hasUpload) {
    $allowedVideo = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'];
    $originalName = $_FILES['video_file']['name'];
    $tmpPath = $_FILES['video_file']['tmp_name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION) ?: '');

    if (!in_array($ext, $allowedVideo, true)) {
        if ($ext === '' && is_file($tmpPath) && function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = @finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                $mimeToExt = ['video/mp4' => 'mp4', 'video/x-mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-msvideo' => 'avi', 'video/x-matroska' => 'mkv'];
                if (!empty($mime) && isset($mimeToExt[$mime])) {
                    $ext = $mimeToExt[$mime];
                } elseif (!empty($mime) && strpos($mime, 'video/') === 0) {
                    $ext = 'mp4';
                }
            }
        }
        if ($ext === '' && !empty($_FILES['video_file']['type']) && strpos($_FILES['video_file']['type'], 'video/') === 0) {
            $ext = 'mp4';
        }
        if (!in_array($ext, $allowedVideo, true)) {
            setFlash('error', 'Geçersiz video formatı. İzin verilen: ' . implode(', ', $allowedVideo) . '. Lütfen .mp4 veya desteklenen bir format seçin.');
            header('Location: videoOperations.php?' . ($videoId ? 'edit=' . $videoId : 'add=1'));
            exit;
        }
    }

    if (!is_dir($videoUploadDir)) {
        if (!@mkdir($videoUploadDir, 0755, true)) {
            setFlash('error', 'uploads/videos klasörü oluşturulamadı. Klasör izinlerini kontrol edin.');
            header('Location: videoOperations.php?' . ($videoId ? 'edit=' . $videoId : 'add=1'));
            exit;
        }
    }

    // İsimlendirme: orijinal ad slugify + video id + benzersiz zaman (örn: lineup_5_170821_123456.mp4)
    $baseName = pathinfo($originalName, PATHINFO_FILENAME);
    $slug = preg_replace('/[^a-zA-Z0-9_\-\p{L}]/u', '_', $baseName ?: 'video');
    $slug = trim($slug, '_');
    $slug = substr($slug ?: 'video', 0, 60);
    $slug = strtolower($slug);
    $uniqueId = $videoId ? (string) $videoId : 'new';
    $timeStamp = time();
    $uploadedFileName = $slug . '_' . $uniqueId . '_' . $timeStamp . '.' . $ext;
    $destinationPath = $videoUploadDir . $uploadedFileName;

    if (!move_uploaded_file($tmpPath, $destinationPath)) {
        setFlash('error', 'Video dosyası klasöre taşınamadı. Klasör yolu ve yazma iznini kontrol edin.');
        header('Location: videoOperations.php?' . ($videoId ? 'edit=' . $videoId : 'add=1'));
        exit;
    }
    if (!is_file($destinationPath) || !is_readable($destinationPath)) {
        @unlink($destinationPath);
        setFlash('error', 'Video kaydedildi ancak dosya okunamıyor. Klasör izinlerini kontrol edin.');
        header('Location: videoOperations.php?' . ($videoId ? 'edit=' . $videoId : 'add=1'));
        exit;
    }

    $videoUrl = $uploadedFileName;
} elseif ($videoUrlFromPost !== '') {
    $videoUrl = $videoUrlFromPost;
} elseif ($videoId) {
    $stmt = $pdo->prepare('SELECT video_url, thumbnail_url FROM videos WHERE id = ? AND is_deleted = 0');
    $stmt->execute([$videoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $videoUrl = $row['video_url'];
        if ($videoUrl !== null && $videoUrl !== '' && strpos($videoUrl, 'http') !== 0) {
            $videoUrl = basename($videoUrl);
        }
        if (empty($thumbnailUrl) && !empty($row['thumbnail_url'])) {
            $thumbnailUrl = $row['thumbnail_url'];
        }
    }
}

if ($videoUrl === null || $videoUrl === '') {
    setFlash('error', 'Video dosyası yükleyin veya Video URL alanına YouTube / MP4 linki girin.');
    header('Location: videoOperations.php?' . ($videoId ? 'edit=' . $videoId : 'add=1'));
    exit;
}

if (!$videoSlug) {
    $videoSlug = slugify($videoTitle);
}

// Thumbnail: proje kökü uploads/thumbnails/
$thumbDir = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR;
if (!is_dir($thumbDir)) {
    @mkdir($thumbDir, 0755, true);
}
if (!empty($_FILES['thumbnail_file']['tmp_name']) && is_uploaded_file($_FILES['thumbnail_file']['tmp_name'])) {
    $ext = pathinfo($_FILES['thumbnail_file']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $safeExt = in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? strtolower($ext) : 'jpg';
    $thumbName = 'thumb_' . ($videoId ?: 'new') . '_' . time() . '.' . $safeExt;
    if (move_uploaded_file($_FILES['thumbnail_file']['tmp_name'], $thumbDir . $thumbName)) {
        $thumbnailUrl = '/uploads/thumbnails/' . $thumbName;
    }
}

try {
    if ($videoId) {
        $stmt = $pdo->prepare(
            'UPDATE videos SET title = ?, slug = ?, video_url = ?, thumbnail_url = ?, description = ?, gallery_json = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0'
        );
        $stmt->execute([$videoTitle, $videoSlug, $videoUrl, $thumbnailUrl ?: null, $description ?: null, $galleryJson, $videoId]);
        $pdo->prepare('DELETE FROM video_categories WHERE video_id = ?')->execute([$videoId]);
        $insertVideoId = $videoId;
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO videos (author_id, title, slug, video_url, thumbnail_url, description, gallery_json, is_published, is_deleted) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0)'
        );
        $stmt->execute([$currentUser['id'], $videoTitle, $videoSlug, $videoUrl, $thumbnailUrl ?: null, $description ?: null, $galleryJson]);
        $insertVideoId = (int) $pdo->lastInsertId();
    }

    foreach ($categoryIds as $cid) {
        if ($cid > 0) {
            $pdo->prepare('INSERT INTO video_categories (video_id, category_id) VALUES (?, ?)')->execute([$insertVideoId, $cid]);
        }
    }

    setFlash('success', $videoId ? 'Video güncellendi.' : 'Video eklendi.');
} catch (Exception $e) {
    setFlash('error', 'Kayıt sırasında hata: ' . $e->getMessage());
}
header('Location: videoOperations.php');
exit;
