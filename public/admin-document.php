<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$admin = require_admin();

$documentId = validated_positive_id($_GET['id'] ?? null);
if ($documentId === null) {
    http_response_code(404);
    exit('Document not found.');
}
$query = db()->prepare(
    'SELECT file_contents, mime_type, original_name
     FROM driver_documents WHERE id = :id LIMIT 1'
);
$query->execute(['id' => $documentId]);
$document = $query->fetch();
if (
    $document === false ||
    !in_array($document['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true) ||
    !is_string($document['file_contents'])
) {
    http_response_code(404);
    exit('Document not found.');
}
record_admin_activity(db(), $admin, 'document_downloaded', 'driver_document', $documentId);

$safeName = str_replace(["\r", "\n", '"'], '', (string) $document['original_name']);
header('Content-Type: ' . $document['mime_type']);
header('Content-Disposition: attachment; filename="' . addcslashes($safeName, '\\') . '"');
header('Content-Length: ' . (string) strlen($document['file_contents']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
echo $document['file_contents'];
