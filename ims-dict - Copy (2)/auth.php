<?php
// api/auth.php
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['ok'=>true]); }

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? ($_GET['action'] ?? '');

// ── LOGIN ──────────────────────────────────────────────────
if ($action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    if (!$username || !$password) jsonResponse(['error'=>'Username and password required.'],400);

    $db   = getDB();
    $stmt = $db->prepare('SELECT id,password_hash,role,province,is_active FROM users WHERE username=?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']))
        jsonResponse(['error'=>'Invalid username or password.'],401);
    if (!$user['is_active']) jsonResponse(['error'=>'Account is disabled.'],403);

    $db->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['role']     = $user['role'];
    $_SESSION['province'] = $user['province'];

    $profile = [];
    if ($user['role'] === 'intern') {
        $ps = $db->prepare('SELECT full_name,province,ojt_hours_required,session_access FROM intern_profiles WHERE user_id=?');
        $ps->execute([$user['id']]);
        $profile = $ps->fetch() ?: [];
    }

    // Get intern_id if intern
    $internId = null;
    if ($user['role'] === 'intern') {
        $s = $db->prepare('SELECT intern_id FROM users WHERE id=?');
        $s->execute([$user['id']]);
        $internId = $s->fetchColumn();
    }

    jsonResponse(['success'=>true,'user_id'=>$user['id'],'username'=>$username,
        'role'=>$user['role'],'province'=>$user['province'],'intern_id'=>$internId,'profile'=>$profile]);
}

// ── LOGOUT ─────────────────────────────────────────────────
if ($action === 'logout') { session_destroy(); jsonResponse(['success'=>true]); }

// ── ME ──────────────────────────────────────────────────────
if ($action === 'me') {
    $auth = requireAuth();
    $db   = getDB();
    $stmt = $db->prepare('SELECT id,username,role,province,intern_id FROM users WHERE id=?');
    $stmt->execute([$auth['id']]);
    $user = $stmt->fetch();

    if ($user['role'] === 'intern') {
        $ps = $db->prepare('SELECT * FROM intern_profiles WHERE user_id=?');
        $ps->execute([$user['id']]);
        $user['profile'] = $ps->fetch() ?: [];
    }
    jsonResponse(['success'=>true,'user'=>$user]);
}

jsonResponse(['error'=>'Unknown action.'],400);
