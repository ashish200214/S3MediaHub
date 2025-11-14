<?php
require __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// --- CONFIG (use environment variables in production) ---
$bucket = getenv('S3_BUCKET') ?: 's3lempworkflow';
$region = getenv('AWS_REGION') ?: 'us-east-1';

// RDS / MySQL config via env vars (recommended)
$dbHost = getenv('DB_HOST') ?: 'database-1.c6r88gusachg.us-east-1.rds.amazonaws.com';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'mydb';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: 'ashish2002';

// Basic request validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Upload error code: ' . $file['error']]);
    exit;
}

// sanitize and read optional targetFolder
$targetFolder = isset($_POST['targetFolder']) ? trim($_POST['targetFolder']) : '';
$targetFolder = preg_replace('#^\/*#', '', $targetFolder); // remove leading slashes
$targetFolder = preg_replace('#\/*$#', '', $targetFolder); // remove trailing slashes

// Basic mime check (image or video)
$mime = mime_content_type($file['tmp_name']);
$allowedPrefix = ['image/', 'video/'];
$ok = false;
foreach ($allowedPrefix as $p) {
    if (strpos($mime, $p) === 0) { $ok = true; break; }
}
if (!$ok) {
    http_response_code(400);
    echo json_encode(['error' => 'Only image and video files allowed (detected: ' . $mime . ')']);
    exit;
}

// Build unique S3 key
$originalName = basename($file['name']);
$timestamp = round(microtime(true) * 1000);
$safeName = rawurlencode($originalName);
$key = ($targetFolder !== '' ? ($targetFolder . '/') : '') . $timestamp . '_' . $safeName;

// Create S3 client (uses env creds or IAM role)
$s3 = new S3Client([
    'version' => 'latest',
    'region'  => $region,
    // credentials are automatically loaded from env / profile / IAM role
]);

try {
    // Upload to S3 using the temp file (memory-friendly)
    $result = $s3->putObject([
        'Bucket' => $bucket,
        'Key'    => $key,
        'SourceFile' => $file['tmp_name'],
        'ContentType'=> $mime,
        // 'ACL' => 'public-read' // optional - set if you want public access
    ]);

    // Build S3 URL (adjust if you use custom domain/cloudfront)
    $s3Url = "https://{$bucket}.s3.amazonaws.com/{$key}";

    // --- Now insert metadata into RDS (MySQL) ---
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

    // PDO options
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);

    $sql = "INSERT INTO uploads (original_filename, s3_key, s3_url, mime_type, size_bytes, target_folder)
            VALUES (:original_filename, :s3_key, :s3_url, :mime_type, :size_bytes, :target_folder)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':original_filename' => $originalName,
        ':s3_key' => $key,
        ':s3_url' => $s3Url,
        ':mime_type' => $mime,
        ':size_bytes' => (int)$file['size'],
        ':target_folder' => $targetFolder
    ]);

    $insertId = (int)$pdo->lastInsertId();

    header('Content-Type: application/json');
    echo json_encode([
        'message' => 'Uploaded and DB record created',
        'db_id' => $insertId,
        'key' => $key,
        'url' => $s3Url,
        's3_etag' => $result['ETag'] ?? null
    ]);

} catch (AwsException $e) {
    // S3 error
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'S3 upload failed', 'message' => $e->getMessage()]);
    exit;
} catch (PDOException $e) {
    // DB error - optionally consider rolling back the S3 object (delete) if DB insert fails
    // Attempt to delete uploaded S3 object to keep consistency
    try {
        $s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
    } catch (Exception $ex) {
        // ignore deletion errors but log them in production
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'DB insert failed', 'message' => $e->getMessage()]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unexpected error', 'message' => $e->getMessage()]);
    exit;
}
