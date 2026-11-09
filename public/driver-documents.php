<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/driver-documents.php';
$driver = require_driver();
$driverId = (int) $driver['id'];
$pdo = db();
$error = '';
$documentType = 'driver_license';

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
            $originalName = str_replace('\\', '/', (string) $upload['name']);
            $originalName = basename($originalName);
            $originalName = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName) ?: 'document', 0, 255);
            try {
                store_driver_document($pdo, $driverId, $documentType, $originalName, $mimeType, (string) $upload['tmp_name']);
                $_SESSION['driver_document_flash'] = 'Document uploaded successfully. Your administrator will review it.';
                redirect('driver-documents.php');
            } catch (Throwable $exception) {
                error_log('Document upload failed: ' . $exception->getMessage());
                $error = 'We could not save your document. Please try again.';
            }
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
$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach ($documents as $document) $counts[$document['review_status']]++;
$statusLabels = ['pending' => 'Pending review', 'approved' => 'Approved', 'rejected' => 'Needs attention'];

page_start('My documents', 'driver-documents');
?>
<link rel="stylesheet" href="assets/driver-documents.css?v=<?= filemtime(__DIR__ . '/assets/driver-documents.css') ?>">
<section class="documents-page">
  <header class="documents-heading">
    <div><span class="documents-eyebrow">DRIVER VERIFICATION</span><h1>My documents</h1><p>Keep your records in one place and follow their review progress.</p></div>
    <span class="documents-private">✓ Private &amp; protected</span>
  </header>
  <?php if ($flash !== ''): ?><p class="documents-message success" role="status"><?= h($flash) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="documents-message error" role="alert"><?= h($error) ?> Please choose your file again.</p><?php endif; ?>
  <div class="documents-summary" aria-label="Document review summary">
    <?php foreach ($statusLabels as $state => $label): ?>
      <div class="documents-stat"><span class="documents-dot <?= h($state) ?>"></span><span><?= h($label) ?></span><strong><?= $counts[$state] ?></strong></div>
    <?php endforeach; ?>
  </div>
  <div class="documents-layout">
    <section class="documents-card documents-upload" aria-labelledby="upload-heading">
      <div class="documents-card-heading"><span class="documents-icon" aria-hidden="true">↑</span><div><h2 id="upload-heading">Upload a document</h2><p>Submit a clear, readable copy.</p></div></div>
      <form id="document-upload-form" method="post" action="driver-documents.php" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="MAX_FILE_SIZE" value="8388608">
        <label for="document-type">Document type</label>
        <select id="document-type" name="document_type" required>
          <?php foreach (DRIVER_DOCUMENT_TYPES as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $documentType === $value ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="documents-file-zone" id="document-drop-zone">
          <span class="documents-file-symbol" aria-hidden="true">↑</span>
          <label for="document-file">Choose a file to upload</label>
          <p id="document-file-help">PDF, JPG or PNG  -  Up to 8 MB</p>
          <input id="document-file" type="file" name="document" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" aria-describedby="document-file-help document-file-status">
          <p id="document-file-status" role="status">No file selected</p>
        </div>
        <button class="documents-submit" type="submit">Upload for review <span aria-hidden="true">↑</span></button>
      </form>
      <div class="documents-tip"><strong>Before you upload</strong><p>Include all edges of the document. Check that names, dates and registration details are readable.</p></div>
      <p class="documents-privacy-note">Only you and authorized administrators can access your files.</p>
    </section>
    <section class="documents-card documents-history" aria-labelledby="history-heading">
      <div class="documents-card-heading"><div><h2 id="history-heading">Submitted documents <span class="documents-total"><?= count($documents) ?></span></h2><p>Your upload history and administrator feedback.</p></div></div>
      <?php if ($documents === []): ?>
        <div class="documents-empty"><span class="documents-empty-icon" aria-hidden="true">▤</span><h3>Your documents will appear here</h3><p>Upload your first document to send it for review. You can check its status here after submission.</p></div>
      <?php else: ?>
        <ul class="documents-list">
          <?php foreach ($documents as $document): ?>
            <li class="documents-item">
              <div class="documents-item-top"><h3><?= h(DRIVER_DOCUMENT_TYPES[$document['document_type']] ?? 'Supporting document') ?></h3><span class="documents-badge <?= h($document['review_status']) ?>"><?= h($statusLabels[$document['review_status']]) ?></span></div>
              <p class="documents-filename"><?= h((string) $document['original_name']) ?></p>
              <div class="documents-item-meta"><time datetime="<?= h(date('c', strtotime($document['uploaded_at']))) ?>">Uploaded <?= h(date('M j, Y  -  g:i A', strtotime($document['uploaded_at']))) ?></time><a href="driver-document.php?id=<?= (int) $document['id'] ?>" aria-label="Download <?= h((string) $document['original_name']) ?>">Download ↓</a></div>
              <?php if (!empty($document['review_note'])): ?><div class="documents-feedback"><strong>Administrator note</strong><p><?= nl2br(h((string) $document['review_note'])) ?></p></div><?php endif; ?>
              <?php if ($document['review_status'] === 'rejected'): ?><p class="documents-retry">Review the feedback, then upload a corrected copy using the form.</p><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</section>
<script src="assets/driver-documents.js?v=<?= filemtime(__DIR__ . '/assets/driver-documents.js') ?>" defer></script>
<?php page_end(); ?>
