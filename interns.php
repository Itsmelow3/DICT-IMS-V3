<?php
// api/interns.php  — DICT IMS v3
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['status' => 'ok']); }

$auth   = requireAuth();
$db     = getDB();
$body   = getBody();
$method = $_SERVER['REQUEST_METHOD'];

/* ════════════════════════════════════════════════════════════
   GET – intern profile or admin list
════════════════════════════════════════════════════════════ */
if ($method === 'GET') {

    // Intern fetching own profile (or admin fetching a single intern via ?me=1)
    if ($auth['role'] === 'intern' || isset($_GET['me'])) {
        try {
            $stmt = $db->prepare(
                'SELECT ip.*, u.username, u.intern_id, u.role, u.last_login, u.province AS user_province
                   FROM intern_profiles ip
                   JOIN users u ON u.id = ip.user_id
                  WHERE ip.user_id = ?'
            );
            $stmt->execute([$auth['id']]);
            $profile = $stmt->fetch();

            if (!$profile) {
                jsonResponse(['status' => 'error', 'message' => 'Profile not found.'], 404);
            }

            // Total hours rendered
            $th = $db->prepare('SELECT COALESCE(SUM(hours_rendered), 0) FROM attendance WHERE user_id = ?');
            $th->execute([$auth['id']]);
            $profile['hours_rendered'] = (float)$th->fetchColumn();

            jsonResponse(['status' => 'success', 'success' => true, 'profile' => $profile]);

        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    // Admin/Supervisor: list all interns with optional filters
    requireRole('admin', 'supervisor');

    $search   = '%' . trim($_GET['search']   ?? '') . '%';
    $status   = trim($_GET['status']   ?? '');
    $province = trim($_GET['province'] ?? '');

    $conditions = [
        "u.role = 'intern'",
        "(ip.full_name LIKE :search OR u.username LIKE :search OR u.intern_id LIKE :search)",
    ];
    $params = [':search' => $search];

    if (!empty($status)) {
        $conditions[]        = 'ip.status = :status';
        $params[':status']   = $status;
    }
    if (!empty($province)) {
        $conditions[]        = 'ip.province = :province';
        $params[':province'] = $province;
    }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $sql = "SELECT ip.*, u.username, u.intern_id, u.last_login,
                   u.province AS user_province, u.is_active,
                   COALESCE(att.hours, 0) AS hours_rendered,
                   COALESCE(doc.cnt, 0)   AS doc_count
              FROM intern_profiles ip
              JOIN users u ON u.id = ip.user_id
              LEFT JOIN (
                  SELECT user_id, SUM(hours_rendered) AS hours FROM attendance GROUP BY user_id
              ) att ON att.user_id = ip.user_id
              LEFT JOIN (
                  SELECT user_id, COUNT(*) AS cnt FROM documents GROUP BY user_id
              ) doc ON doc.user_id = ip.user_id
              {$where}
             ORDER BY ip.full_name ASC";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['status' => 'success', 'success' => true, 'interns' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   POST – enroll new intern (admin/supervisor only)
════════════════════════════════════════════════════════════ */
if ($method === 'POST') {
    requireRole('admin', 'supervisor');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'error', 'message' => 'POST method required.'], 405);
    }

    $username  = trim($body['username']           ?? '');
    $internId  = trim($body['intern_id']          ?? '');
    $password  = $body['password']                ?? '';
    $targetHrs = (int)($body['ojt_hours_required'] ?? 480);
    $province  = trim($body['province']           ?? '');

    // ── Validate required fields with isset/empty ──
    if (empty($username)) {
        jsonResponse(['status' => 'error', 'message' => 'Username is required.'], 400);
    }
    if (empty($internId)) {
        jsonResponse(['status' => 'error', 'message' => 'Intern ID is required.'], 400);
    }
    if (empty($password)) {
        jsonResponse(['status' => 'error', 'message' => 'Password is required.'], 400);
    }

    // ── Format validation: YY-XXXX ──
    if (!preg_match('/^\d{2}-\d{4}$/', $internId)) {
        jsonResponse(['status' => 'error', 'message' => 'Intern ID must follow format YY-XXXX (e.g. 25-0001).'], 400);
    }

    if ($targetHrs < 1 || $targetHrs > 9999) {
        jsonResponse(['status' => 'error', 'message' => 'OJT hours must be between 1 and 9999.'], 400);
    }

    try {
        // ── Check for duplicates with prepared statement ──
        $chk = $db->prepare('SELECT id FROM users WHERE username = ? OR intern_id = ?');
        $chk->execute([$username, $internId]);
        if ($chk->fetch()) {
            jsonResponse(['status' => 'error', 'message' => 'Username or Intern ID is already taken.'], 409);
        }

        // ── Hash password before saving ──
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // ── INSERT user ──
        $db->prepare(
            'INSERT INTO users (username, intern_id, password_hash, role, province) VALUES (?, ?, ?, ?, ?)'
        )->execute([$username, $internId, $hash, 'intern', $province]);

        $uid = (int)$db->lastInsertId();

        // ── INSERT profile ──
        $db->prepare(
            'INSERT INTO intern_profiles (user_id, ojt_hours_required, province) VALUES (?, ?, ?)'
        )->execute([$uid, $targetHrs, $province]);

        jsonResponse([
            'status'    => 'success',
            'success'   => true,
            'user_id'   => $uid,
            'intern_id' => $internId,
            'message'   => 'Intern enrolled successfully.',
        ]);

    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   PUT – update profile, toggle session access, set status
════════════════════════════════════════════════════════════ */
if ($method === 'PUT') {

    $action = trim($body['action'] ?? 'profile');

    /* ── Admin: toggle session access ── */
    if ($action === 'toggle_session_access') {
        requireRole('admin', 'supervisor');

        $uid    = (int)($body['user_id']        ?? 0);
        $access = (int)($body['session_access'] ?? 0);

        if (!$uid) {
            jsonResponse(['status' => 'error', 'message' => 'user_id is required.'], 400);
        }

        try {
            $db->prepare('UPDATE intern_profiles SET session_access = ? WHERE user_id = ?')
               ->execute([$access, $uid]);

            $type = $access ? 'access_approved' : 'access_denied';
            $msg  = $access
                ? 'You have been granted permission to create Learning Sessions.'
                : 'Your Learning Session creation access has been revoked.';
            notify($db, $uid, $type, 'Session Access Updated', $msg);

            jsonResponse(['status' => 'success', 'success' => true]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    /* ── Admin: activate/deactivate intern ── */
    if ($action === 'set_status') {
        requireRole('admin', 'supervisor');

        $uid    = (int)($body['user_id'] ?? 0);
        $status = trim($body['status']   ?? '');

        if (!$uid) {
            jsonResponse(['status' => 'error', 'message' => 'user_id is required.'], 400);
        }
        if (!in_array($status, ['active', 'inactive', 'graduated'], true)) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid status value.'], 400);
        }

        try {
            $db->prepare('UPDATE intern_profiles SET status = ? WHERE user_id = ?')
               ->execute([$status, $uid]);
            jsonResponse(['status' => 'success', 'success' => true]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    /* ── Intern / Admin: update profile fields ── */
    $uid = ($auth['role'] !== 'intern' && !empty($body['user_id']))
         ? (int)$body['user_id']
         : $auth['id'];

    $allowed = [
        'full_name', 'email', 'contact_number', 'address', 'age',
        'gender', 'school', 'course', 'province', 'ojt_hours_required',
    ];

    $sets   = [];
    $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]   = "{$f} = ?";
            $params[] = ($body[$f] === '' || $body[$f] === null) ? null : $body[$f];
        }
    }

    if (empty($sets)) {
        jsonResponse(['status' => 'error', 'message' => 'Nothing to update. Provide at least one field.'], 400);
    }

    $params[] = $uid;

    try {
        $db->prepare('UPDATE intern_profiles SET ' . implode(', ', $sets) . ' WHERE user_id = ?')
           ->execute($params);

        // Sync province to users table
        if (isset($body['province'])) {
            $db->prepare('UPDATE users SET province = ? WHERE id = ?')
               ->execute([$body['province'], $uid]);
        }

        jsonResponse(['status' => 'success', 'success' => true, 'message' => 'Profile saved successfully.']);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   DELETE – remove intern (admin only)
════════════════════════════════════════════════════════════ */
if ($method === 'DELETE') {
    requireRole('admin');

    $uid = (int)($_GET['user_id'] ?? 0);
    if (!$uid) {
        jsonResponse(['status' => 'error', 'message' => 'user_id is required.'], 400);
    }

    try {
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        jsonResponse(['status' => 'success', 'success' => true, 'message' => 'Intern removed.']);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
