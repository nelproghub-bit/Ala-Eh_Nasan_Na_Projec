<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

set_exception_handler(static function (Throwable $exception): void {
    $requestId = bin2hex(random_bytes(6));
    error_log('Profile photo request ' . $requestId . ' failed: ' . (string) $exception);
    http_response_code(500);
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'message' => 'The server could not save your photo. It was not changed. Please try again.',
        'request_id' => $requestId,
    ], JSON_THROW_ON_ERROR);
});

$isAdmin = current_admin() !== null;
$isDriver = current_driver() !== null;
if (!$isAdmin && !$isDriver) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Cache-Control: no-store');
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
        echo json_encode(['message' => 'Sign in again before managing your profile photo.'], JSON_THROW_ON_ERROR);
        exit;
    }
    http_response_code(404);
    exit;
}

$account = $isAdmin ? require_admin() : require_driver();
$accountId = (int) $account['id'];
$accountColumn = $isAdmin ? 'admin_id' : 'driver_id';
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=UTF-8');
    if (!csrf_value_is_valid($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['message' => 'Your session token expired. Reload the page and try again.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'remove') {
        $remove = $pdo->prepare('DELETE FROM profile_photos WHERE ' . $accountColumn . ' = :account_id');
        $remove->execute(['account_id' => $accountId]);
        echo json_encode(['message' => 'Your profile photo was removed.'], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action !== 'save') {
        http_response_code(400);
        echo json_encode(['message' => 'Choose a valid profile photo action.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $upload = $_FILES['photo'] ?? null;
    if (
        !is_array($upload) ||
        !isset($upload['error'], $upload['tmp_name'], $upload['size']) ||
        !is_int($upload['error']) ||
        !is_string($upload['tmp_name']) ||
        !is_int($upload['size'])
    ) {
        http_response_code(400);
        echo json_encode(['message' => 'Choose a photo to upload.'], JSON_THROW_ON_ERROR);
        exit;
    }
    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $message = match ((int) $upload['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Choose an image smaller than 5 MB.',
            UPLOAD_ERR_NO_FILE => 'Choose a photo to upload.',
            default => 'The photo upload could not be completed. Try again.',
        };
        http_response_code(400);
        echo json_encode(['message' => $message], JSON_THROW_ON_ERROR);
        exit;
    }
    if (
        (int) $upload['size'] < 1 ||
        (int) $upload['size'] > 5 * 1024 * 1024 ||
        !is_uploaded_file((string) $upload['tmp_name'])
    ) {
        http_response_code(400);
        echo json_encode(['message' => 'Choose a valid image smaller than 5 MB.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file((string) $upload['tmp_name']);
    $imageInfo = @getimagesize((string) $upload['tmp_name']);
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (
        !is_string($mimeType) ||
        !in_array($mimeType, $allowedTypes, true) ||
        !is_array($imageInfo) ||
        !isset($imageInfo['mime'], $imageInfo[0], $imageInfo[1]) ||
        $imageInfo['mime'] !== $mimeType ||
        (int) $imageInfo[0] < 1 ||
        (int) $imageInfo[1] < 1 ||
        (int) $imageInfo[0] * (int) $imageInfo[1] > 16_000_000
    ) {
        http_response_code(400);
        echo json_encode(['message' => 'Use a valid JPEG, PNG, or WebP image up to 16 megapixels.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $imageContents = file_get_contents((string) $upload['tmp_name']);
    if ($imageContents === false || $imageContents === '') {
        http_response_code(400);
        echo json_encode(['message' => 'The selected photo could not be read. Try another image.'], JSON_THROW_ON_ERROR);
        exit;
    }

    $save = $pdo->prepare(
        'INSERT INTO profile_photos (' . $accountColumn . ', mime_type, image_data)
         VALUES (:account_id, :mime_type, :image_data)
         ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), image_data = VALUES(image_data)'
    );
    $save->bindValue(':account_id', $accountId, PDO::PARAM_INT);
    $save->bindValue(':mime_type', $mimeType, PDO::PARAM_STR);
    $save->bindValue(':image_data', $imageContents, PDO::PARAM_LOB);
    $save->execute();

    echo json_encode(['message' => 'Your profile photo was saved.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$photoQuery = $pdo->prepare(
    'SELECT mime_type, image_data FROM profile_photos WHERE ' . $accountColumn . ' = :account_id LIMIT 1'
);
$photoQuery->execute(['account_id' => $accountId]);
$photo = $photoQuery->fetch();
if (
    $photo === false ||
    !in_array($photo['mime_type'], ['image/jpeg', 'image/png', 'image/webp'], true)
) {
    http_response_code(404);
    exit;
}

$imageContents = $photo['image_data'];
if (is_resource($imageContents)) {
    $imageContents = stream_get_contents($imageContents);
}
if (!is_string($imageContents) || $imageContents === '') {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $photo['mime_type']);
header('Content-Length: ' . strlen($imageContents));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
echo $imageContents;
