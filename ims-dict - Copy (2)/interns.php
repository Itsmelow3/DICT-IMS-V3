<?php
// api/interns.php
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['ok'=>true]); }

$auth   = requireAuth();
$db     = getDB();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────
if ($method === 'GET') {

    // Intern: own profile
    if ($auth['role'] === 'intern' || isset($_GET['me'])) {
        $stmt = $db->prepare(
            'SELECT ip.*, u.username, u.intern_id, u.role, u.last_login, u.province AS user_province
               FROM intern_profiles ip
               JOIN users u ON u.id = ip.user_id
              WHERE ip.user_id=?');
        $stmt->execute([$auth['id']]);
        $profile = $stmt->fetch();
        $th = $db->prepare('SELECT COALESCE(SUM(hours_rendered),0) FROM attendance WHERE user_id=?');
        $th->execute([$auth['id']]);
        $profile['hours_rendered'] = (float)$th->fetchColumn();
        jsonResponse(['success'=>true,'profile'=>$profile]);
    }

    // Admin/Supervisor: list all interns
    $search   = '%' . trim($_GET['search'] ?? '') . '%';
    $status   = $_GET['status']   ?? '';
    $province = $_GET['province'] ?? '';

    $conditions = ["u.role = 'intern'", "(ip.full_name LIKE :search OR u.username LIKE :search OR u.intern_id LIKE :search)"];
    $params = [':search' => $search];
    if ($status)   { $conditions[] = "ip.status = :status";     $params[':status']   = $status; }
    if ($province) { $conditions[] = "ip.province = :province"; $params[':province'] = $province; }

    $where = 'WHERE ' . implode(' AND ', $conditions);
    $sql = "SELECT ip.*, u.username, u.intern_id, u.last_login, u.province AS user_province,
                   COALESCE(att.hours,0) AS hours_rendered,
                   COALESCE(doc.cnt,0)   AS doc_count
              FROM intern_profiles ip
              JOIN users u ON u.id = ip.user_id
              LEFT JOIN (SELECT user_id, SUM(hours_rendered) AS hours FROM attendance GROUP BY user_id) att ON att.user_id=ip.user_id
              LEFT JOIN (SELECT user_id, COUNT(*) AS cnt FROM documents GROUP BY user_id) doc ON doc.user_id=ip.user_id
              $where
             ORDER BY ip.full_name";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse(['success'=>true,'interns'=>$stmt->fetchAll()]);
}

// ── POST: Enroll new intern (admin/supervisor only) ───────
if ($method === 'POST') {
    requireRole('admin','supervisor');
    $username  = trim($body['username']  ?? '');
    $intern_id = trim($body['intern_id'] ?? '');
    $password  = $body['password']       ?? '';
    $target_hrs= (int)($body['ojt_hours_required'] ?? 480);
    $province  = $body['province']       ?? '';

    if (!$username || !$intern_id || !$password)
        jsonResponse(['error'=>'username, intern_id, and password are required.'],400);

    // Format validation: YY-XXXX
    if (!preg_match('/^\d{2}-\d{4}$/', $intern_id))
        jsonResponse(['error'=>'Intern ID must follow format YY-XXXX (e.g. 25-0001).'],400);

    // Check duplicates
    $chk = $db->prepare('SELECT id FROM users WHERE username=? OR intern_id=?');
    $chk->execute([$username,$intern_id]);
    if ($chk->fetch()) jsonResponse(['error'=>'Username or Intern ID already taken.'],409);

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare('INSERT INTO users (username,intern_id,password_hash,role,province) VALUES (?,?,?,?,?)')
       ->execute([$username,$intern_id,$hash,'intern',$province]);
    $uid = (int)$db->lastInsertId();

    $db->prepare('INSERT INTO intern_profiles (user_id,ojt_hours_required,province) VALUES (?,?,?)')
       ->execute([$uid,$target_hrs,$province]);

    jsonResponse(['success'=>true,'user_id'=>$uid,'intern_id'=>$intern_id]);
}

// ── PUT: Update profile (intern updates own; admin can update any) ──
if ($method === 'PUT') {
    $action = $body['action'] ?? 'profile';

    // Admin: toggle session access
    if ($action === 'toggle_session_access') {
        requireRole('admin','supervisor');
        $uid    = (int)($body['user_id'] ?? 0);
        $access = (int)($body['session_access'] ?? 0);
        $db->prepare('UPDATE intern_profiles SET session_access=? WHERE user_id=?')->execute([$access,$uid]);

        // Notify intern
        $type = $access ? 'access_approved' : 'access_denied';
        $msg  = $access ? 'You have been granted permission to create Learning Sessions.'
                        : 'Your Learning Session creation access has been revoked.';
        notify($db, $uid, $type, 'Session Access Updated', $msg);
        jsonResponse(['success'=>true]);
    }

    // Admin: deactivate/activate
    if ($action === 'set_status') {
        requireRole('admin','supervisor');
        $uid    = (int)($body['user_id'] ?? 0);
        $status = $body['status'] ?? 'inactive';
        $db->prepare('UPDATE intern_profiles SET status=? WHERE user_id=?')->execute([$status,$uid]);
        jsonResponse(['success'=>true]);
    }

    // Intern or admin: update profile fields
    $uid = ($auth['role'] !== 'intern' && isset($body['user_id'])) ? (int)$body['user_id'] : $auth['id'];
    $allowed = ['full_name','email','contact_number','address','age','gender','school','course','province','ojt_hours_required'];
    $sets = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f,$body)) {
            $sets[]    = "$f=?";
            $params[]  = $body[$f] === '' ? null : $body[$f];
        }
    }
    if (!$sets) jsonResponse(['error'=>'Nothing to update.'],400);
    $params[] = $uid;
    $db->prepare('UPDATE intern_profiles SET '.implode(',',$sets).' WHERE user_id=?')->execute($params);

    // Sync province to users table too
    if (isset($body['province'])) {
        $db->prepare('UPDATE users SET province=? WHERE id=?')->execute([$body['province'],$uid]);
    }
    jsonResponse(['success'=>true]);
}

// ── DELETE: Remove intern (admin only) ───────────────────
if ($method === 'DELETE') {
    requireRole('admin');
    $uid = (int)($_GET['user_id'] ?? 0);
    if (!$uid) jsonResponse(['error'=>'user_id required.'],400);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
    jsonResponse(['success'=>true]);
}

jsonResponse(['error'=>'Method not allowed.'],405);
