<?php
// api/config.php  — DICT IMS v3
// ─────────────────────────────────────────────────────────────
// Central configuration: DB, PDO, auth helpers, JSON responses
// ─────────────────────────────────────────────────────────────

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'dict_ims');   // updated to match new schema

/* ── Singleton PDO connection ── */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // REQUIRED: throw exceptions
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,                    // Real prepared statements
                ]
            );
        } catch (PDOException $e) {
            // Surface the real DB error immediately — no silent failures
            jsonResponse(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()], 500);
        }
    }
    return $pdo;
}

/* ── Standardised JSON response (never return HTML on error) ── */
function jsonResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    // Backward compat: map 'success'→'status':'success' and 'error'→'status':'error'
    if (!isset($data['status'])) {
        if (isset($data['success']) && $data['success'] === true) {
            $data['status'] = 'success';
        } elseif (isset($data['error'])) {
            $data['status'] = 'error';
            $data['message'] = $data['error'];
        }
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── Session bootstrap ── */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ── Auth helpers ── */
function requireAuth(): array {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['status' => 'error', 'message' => 'Unauthorized. Please log in.'], 401);
    }
    return [
        'id'       => (int)$_SESSION['user_id'],
        'role'     => $_SESSION['role'],
        'province' => $_SESSION['province'] ?? '',
    ];
}

function requireRole(string ...$roles): array {
    $auth = requireAuth();
    if (!in_array($auth['role'], $roles, true)) {
        jsonResponse(['status' => 'error', 'message' => 'Forbidden. Insufficient permissions.'], 403);
    }
    return $auth;
}

/* ── Notification helper ── */
function notify(PDO $db, int|array $userIds, string $type, string $title, string $message, ?int $relatedId = null): void {
    $ids  = is_array($userIds) ? $userIds : [$userIds];
    $stmt = $db->prepare(
        'INSERT INTO notifications (user_id, type, title, message, related_id) VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($ids as $uid) {
        try {
            $stmt->execute([(int)$uid, $type, $title, $message, $relatedId]);
        } catch (PDOException $e) {
            error_log('Notification insert failed for uid ' . $uid . ': ' . $e->getMessage());
        }
    }
}

/* ── Input helpers ── */
function getBody(): array {
    static $body = null;
    if ($body === null) {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
    }
    return $body;
}
?>
