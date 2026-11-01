<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$driver = require_driver();
$driverId = (int) $driver['id'];
$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $documentType = $_POST['document_type'] ?? '';
    $allowedTypes = ['driver_license', 'vehicle_registration', 'insurance', 'other'];
    $upload = $_FILES['document'] ?? null;
    if (!is_string($documentType) || !in_array($documentType, $allowedTypes, true)) {
        $error = 'Choose a valid document type.';
    } elseif (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'])) {
        $error = 'Choose a file to upload.';
    } elseif ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        $error = match ((int) $upload['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds the 8 MB size limit.',
            UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
            default => 'The upload could not be completed. Try again.',
        };
    } elseif ((int) $upload['size'] < 1 || (int) $upload['size'] > 8 * 1024 * 1024) {
        $error = 'Choose a file smaller than 8 MB.';
    } elseif (!is_uploaded_file((string) $upload['tmp_name'])) {
        $error = 'The uploaded file could not be verified.';
    } else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file((string) $upload['tmp_name']);
        $extensions = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
            $error = 'Only PDF, JPEG, and PNG documents are accepted.';
        } else {
            $storageKey = bin2hex(random_bytes(32));
            $fileStream = fopen((string) $upload['tmp_name'], 'rb');
            if ($fileStream === false) {
                throw new RuntimeException('The uploaded document could not be read.');
            }
            $originalName = str_replace('\\', '/', (string) $upload['name']);
            $originalName = basename($originalName);
            $originalName = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'document', 0, 255);
            $insert = $pdo->prepare(
                "INSERT INTO driver_documents
                    (driver_id, document_type, original_name, storage_key, mime_type, file_contents)
                 VALUES (:driver_id, :document_type, :original_name, :storage_key, :mime_type, :file_contents)"
            );
            $insert->bindValue(':driver_id', $driverId, PDO::PARAM_INT);
            $insert->bindValue(':document_type', $documentType, PDO::PARAM_STR);
            $insert->bindValue(':original_name', $originalName, PDO::PARAM_STR);
            $insert->bindValue(':storage_key', $storageKey, PDO::PARAM_STR);
            $insert->bindValue(':mime_type', $mimeType, PDO::PARAM_STR);
            try {
                $insert->bindValue(':file_contents', $fileStream, PDO::PARAM_LOB);
                $insert->execute();
            } finally {
                fclose($fileStream);
            }
            $_SESSION['driver_document_flash'] = 'Document uploaded for administrator review.';
            redirect('driver-documents.php');
        }
    }
}

$documentsQuery = $pdo->prepare(
    'SELECT id, document_type, original_name, review_status, review_note, uploaded_at
     FROM driver_documents WHERE driver_id = :driver_id ORDER BY uploaded_at DESC'
);
$documentsQuery->execute(['driver_id' => $driverId]);
$documents = $documentsQuery->fetchAll();
$flash = (string) ($_SESSION['driver_document_flash'] ?? '');
unset($_SESSION['driver_document_flash']);

page_start('My documents', 'driver-documents');
?>
<section class="driver-profile-page">
  <div class="driver-profile-heading">
    <div><span class="eyebrow"><span class="eyebrow-dot"></span> PRIVATE DOCUMENTS</span><h1>My documents</h1><p>Upload supporting records for administrator verification.</p></div>
    <span class="profile-secure-badge"><span aria-hidden="true">◈</span> Restricted access</span>
  </div>
  <?php if ($flash !== ''): ?><p class="message message-success profile-message" role="status"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="message message-error profile-message" role="alert"><?= h($error) ?></p><?php endif; ?>
  <div class="driver-profile-layout">
    <section class="driver-profile-card">
      <div class="driver-profile-card-heading"><span class="driver-profile-icon" aria-hidden="true">↑</span><div><h2>Upload a document</h2><p>Accepted formats: PDF, JPEG, or PNG; maximum 8 MB.</p></div></div>
      <form class="form-stack" method="post" action="driver-documents.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <label>Document type
          <select name="document_type" required>
            <option value="driver_license">Driver license</option>
            <option value="vehicle_registration">Vehicle registration</option>
            <option value="insurance">Insurance</option>
            <option value="other">Other supporting document</option>
          </select>
        </label>
        <label>File
          <input type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" />
        </label>
        <button class="button button-primary" type="submit">Upload for review</button>
      </form>
      <p class="profile-security-note">Files are stored outside the public directory and served only after an administrator sign-in check.</p>
    </section>
    <section class="driver-profile-card">
      <div class="driver-profile-card-heading"><span class="driver-profile-icon" aria-hidden="true">✓</span><div><h2>Submitted files</h2><p>Review status and administrator notes.</p></div></div>
      <?php if ($documents === []): ?>
        <p>No documents submitted.</p>
      <?php else: ?>
        <ul class="document-list">
          <?php foreach ($documents as $document): ?>
            <li><strong><?= h(str_replace('_', ' ', (string) $document['document_type'])) ?></strong><span><?= h((string) $document['original_name']) ?></span><span class="admin-status"><?= h((string) $document['review_status']) ?></span><?php if ($document['review_note'] !== null): ?><small><?= h((string) $document['review_note']) ?></small><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</section>
<?php page_end(); ?>
