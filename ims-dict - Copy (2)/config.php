<?php
// api/config.php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ims_dict');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();

function requireAuth(): array {
    if (empty($_SESSION['user_id'])) jsonResponse(['error' => 'Unauthorized'], 401);
    return ['id' => $_SESSION['user_id'], 'role' => $_SESSION['role'], 'province' => $_SESSION['province'] ?? ''];
}

function requireRole(string ...$roles): array {
    $auth = requireAuth();
    if (!in_array($auth['role'], $roles, true)) jsonResponse(['error' => 'Forbidden'], 403);
    return $auth;
}

// Notify one or many users
function notify(PDO $db, int|array $userIds, string $type, string $title, string $message, ?int $relatedId = null): void {
    $ids = is_array($userIds) ? $userIds : [$userIds];
    $stmt = $db->prepare('INSERT INTO notifications (user_id,type,title,message,related_id) VALUES (?,?,?,?,?)');
    foreach ($ids as $uid) {
        $stmt->execute([$uid, $type, $title, $message, $relatedId]);
    }
}
?>
