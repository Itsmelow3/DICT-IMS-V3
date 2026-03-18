<?php
// api/notifications.php  — DICT IMS v3
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['status' => 'ok']); }

$auth   = requireAuth();
$db     = getDB();
$body   = getBody();
$method = $_SERVER['REQUEST_METHOD'];

/* ── GET ── */
if ($method === 'GET') {
    try {
        $s = $db->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50'
        );
        $s->execute([$auth['id']]);

        $u = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $u->execute([$auth['id']]);

        jsonResponse([
            'status'        => 'success',
            'success'       => true,
            'notifications' => $s->fetchAll(),
            'unread'        => (int)$u->fetchColumn(),
        ]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ── PUT – mark as read ── */
if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    try {
        if ($id) {
            $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
               ->execute([$id, $auth['id']]);
        } else {
            $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')
               ->execute([$auth['id']]);
        }
        jsonResponse(['status' => 'success', 'success' => true]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
