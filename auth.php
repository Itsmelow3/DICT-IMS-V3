<?php
// api/auth.php  — DICT IMS v3
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$body   = getBody();
$action = trim($body['action'] ?? ($_GET['action'] ?? ''));

/* ════════════════════════════════════════════════════════════
   LOGIN
════════════════════════════════════════════════════════════ */
if ($action === 'login') {
    // ── Method & input check ──
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'error', 'message' => 'POST method required.'], 405);
    }

    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if (empty($username) || empty($password)) {
        jsonResponse(['status' => 'error', 'message' => 'Username and password are required.'], 400);
    }

    $db = getDB();

    try {
        // ── SELECT with prepared statement — ZERO SQL injection ──
        $stmt = $db->prepare(
            'SELECT id, password_hash, role, province, is_active
               FROM users
              WHERE username = ?
              LIMIT 1'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid username or password.'], 401);
        }
        if (!(int)$user['is_active']) {
            jsonResponse(['status' => 'error', 'message' => 'Account is disabled. Contact admin.'], 403);
        }

        // ── UPDATE last_login — prepared statement ──
        $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
           ->execute([$user['id']]);

        // ── Persist session ──
        session_regenerate_id(true);
        $_SESSION['user_id']  = (int)$user['id'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['province'] = $user['province'];

        // ── Fetch profile if intern ──
        $profile  = [];
        $internId = null;

        if ($user['role'] === 'intern') {
            $ps = $db->prepare(
                'SELECT full_name, province, ojt_hours_required, session_access
                   FROM intern_profiles
                  WHERE user_id = ?'
            );
            $ps->execute([$user['id']]);
            $profile = $ps->fetch() ?: [];

            $si = $db->prepare('SELECT intern_id FROM users WHERE id = ?');
            $si->execute([$user['id']]);
            $internId = $si->fetchColumn() ?: null;
        }

        jsonResponse([
            'status'    => 'success',
            'success'   => true,
            'user_id'   => (int)$user['id'],
            'username'  => $username,
            'role'      => $user['role'],
            'province'  => $user['province'],
            'intern_id' => $internId,
            'profile'   => $profile,
        ]);

    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   LOGOUT
════════════════════════════════════════════════════════════ */
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    jsonResponse(['status' => 'success', 'success' => true]);
}

/* ════════════════════════════════════════════════════════════
   ME — fetch current session user
════════════════════════════════════════════════════════════ */
if ($action === 'me') {
    $auth = requireAuth();
    $db   = getDB();

    try {
        $stmt = $db->prepare(
            'SELECT id, username, role, province, intern_id FROM users WHERE id = ?'
        );
        $stmt->execute([$auth['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            session_destroy();
            jsonResponse(['status' => 'error', 'message' => 'Session expired.'], 401);
        }

        if ($user['role'] === 'intern') {
            $ps = $db->prepare('SELECT * FROM intern_profiles WHERE user_id = ?');
            $ps->execute([$user['id']]);
            $user['profile'] = $ps->fetch() ?: [];
        }

        jsonResponse(['status' => 'success', 'success' => true, 'user' => $user]);

    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['status' => 'error', 'message' => 'Unknown action.'], 400);
