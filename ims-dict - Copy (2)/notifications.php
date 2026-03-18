<?php
// api/notifications.php
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['ok'=>true]); }
$auth = requireAuth(); $db = getDB();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $s = $db->prepare('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
    $s->execute([$auth['id']]);
    $unread = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0');
    $unread->execute([$auth['id']]);
    jsonResponse(['success'=>true,'notifications'=>$s->fetchAll(),'unread'=>(int)$unread->fetchColumn()]);
}

if ($method === 'PUT') {
    $id = (int)($body['id'] ?? 0);
    if ($id) {
        $db->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?')->execute([$id,$auth['id']]);
    } else {
        $db->prepare('UPDATE notifications SET is_read=1 WHERE user_id=?')->execute([$auth['id']]);
    }
    jsonResponse(['success'=>true]);
}

jsonResponse(['error'=>'Method not allowed.'],405);
