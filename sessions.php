<?php
// api/sessions.php  — DICT IMS v3
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['status' => 'ok']); }

$auth   = requireAuth();
$db     = getDB();
$body   = getBody();
$method = $_SERVER['REQUEST_METHOD'];

$VALID_PROVINCES = ['Tuguegarao', 'Quirino', 'Cauayan', 'Santiago', 'Batanes', 'Nueva Vizcaya'];

/* ════════════════════════════════════════════════════════════
   GET – all sessions (province-filtered for interns)
════════════════════════════════════════════════════════════ */
if ($method === 'GET' && !isset($_GET['action'])) {
    try {
        $stmt = $db->query('SELECT * FROM learning_sessions ORDER BY session_date DESC, start_time ASC');
        $all  = $stmt->fetchAll();

        if ($auth['role'] === 'intern') {
            $myProvince = trim($auth['province'] ?? '');
            $all = array_values(array_filter($all, function ($s) use ($myProvince) {
                if (empty($s['target_provinces'])) return true;
                $targets = array_map('trim', explode(',', $s['target_provinces']));
                return in_array($myProvince, $targets, true);
            }));
        }

        jsonResponse(['status' => 'success', 'success' => true, 'sessions' => $all]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   GET – pending access requests
════════════════════════════════════════════════════════════ */
if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'access_requests') {
    requireRole('admin', 'supervisor');

    try {
        $stmt = $db->query(
            "SELECT sar.*, u.username, u.intern_id, ip.full_name
               FROM session_access_requests sar
               JOIN users u ON u.id = sar.user_id
               JOIN intern_profiles ip ON ip.user_id = sar.user_id
              WHERE sar.status = 'pending'
              ORDER BY sar.requested_at DESC"
        );
        jsonResponse(['status' => 'success', 'success' => true, 'requests' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   POST – create session OR request access
════════════════════════════════════════════════════════════ */
if ($method === 'POST') {
    $action = trim($body['action'] ?? 'create');

    /* ── Intern requests session hosting access ── */
    if ($action === 'request_access') {
        if ($auth['role'] !== 'intern') {
            jsonResponse(['status' => 'error', 'message' => 'Only interns can request session access.'], 403);
        }

        try {
            // Check for existing pending request
            $chk = $db->prepare("SELECT id FROM session_access_requests WHERE user_id = ? AND status = 'pending'");
            $chk->execute([$auth['id']]);
            if ($chk->fetch()) {
                jsonResponse(['status' => 'error', 'message' => 'You already have a pending access request.'], 409);
            }

            $db->prepare('INSERT INTO session_access_requests (user_id) VALUES (?)')->execute([$auth['id']]);

            // Notify all admins/supervisors
            $admins = $db->query("SELECT id FROM users WHERE role IN ('admin','supervisor') AND is_active = 1")->fetchAll();
            $ids    = array_column($admins, 'id');
            $ip     = $db->prepare('SELECT full_name FROM intern_profiles WHERE user_id = ?');
            $ip->execute([$auth['id']]);
            $name = $ip->fetchColumn() ?: 'An intern';
            if ($ids) {
                notify($db, $ids, 'general', 'Session Access Request',
                    "{$name} has requested permission to host a Learning Session.");
            }

            jsonResponse(['status' => 'success', 'success' => true, 'message' => 'Access request submitted.']);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    /* ── Create a new learning session ── */
    if ($action === 'create') {

        // Interns need admin approval
        if ($auth['role'] === 'intern') {
            try {
                $chk = $db->prepare('SELECT session_access FROM intern_profiles WHERE user_id = ?');
                $chk->execute([$auth['id']]);
                $row = $chk->fetch();
                if (!$row || !(int)$row['session_access']) {
                    jsonResponse(['status' => 'error', 'message' => 'You do not have permission to create sessions. Request access first.'], 403);
                }
            } catch (PDOException $e) {
                jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
            }
        }

        // ── Validate required fields with isset/empty ──
        $required = ['title', 'host_name', 'session_date', 'start_time', 'platform'];
        foreach ($required as $f) {
            if (empty(trim($body[$f] ?? ''))) {
                jsonResponse(['status' => 'error', 'message' => "Field '{$f}' is required."], 400);
            }
        }

        $title       = trim($body['title']);
        $hostName    = trim($body['host_name']);
        $sessionDate = trim($body['session_date']);
        $startTime   = trim($body['start_time']);
        $endTime     = trim($body['end_time'] ?? '');
        $platform    = trim($body['platform']);
        $meetingLink = trim($body['meeting_link'] ?? '');

        // ── Validate formats ──
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
            jsonResponse(['status' => 'error', 'message' => 'session_date must be in YYYY-MM-DD format.'], 400);
        }
        if (!preg_match('/^\d{2}:\d{2}/', $startTime)) {
            jsonResponse(['status' => 'error', 'message' => 'start_time must be in HH:MM format.'], 400);
        }

        $validPlatforms = ['Google Meet', 'Zoom', 'Other'];
        if (!in_array($platform, $validPlatforms, true)) {
            jsonResponse(['status' => 'error', 'message' => 'Platform must be: Google Meet, Zoom, or Other.'], 400);
        }

        // ── Validate target provinces ──
        $rawProvinces = (array)($body['target_provinces'] ?? []);
        $provinces    = [];
        foreach ($rawProvinces as $p) {
            $p = trim($p);
            if (!in_array($p, $VALID_PROVINCES, true)) {
                jsonResponse(['status' => 'error', 'message' => "Invalid province: {$p}."], 400);
            }
            $provinces[] = $p;
        }
        $provincesStr = implode(',', $provinces);

        try {
            $stmt = $db->prepare(
                'INSERT INTO learning_sessions
                    (title, hosted_by, host_name, platform, meeting_link,
                     session_date, start_time, end_time, target_provinces, created_by_role)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $title, $auth['id'], $hostName, $platform,
                $meetingLink ?: null, $sessionDate, $startTime,
                $endTime ?: null, $provincesStr ?: null, $auth['role'],
            ]);
            $sessId = (int)$db->lastInsertId();

            // Notify targeted interns
            if ($provinces) {
                $placeholders = implode(',', array_fill(0, count($provinces), '?'));
                $internStmt   = $db->prepare(
                    "SELECT u.id FROM users u
                       JOIN intern_profiles ip ON ip.user_id = u.id
                      WHERE u.role = 'intern' AND ip.province IN ({$placeholders})"
                );
                $internStmt->execute($provinces);
            } else {
                $internStmt = $db->query("SELECT id FROM users WHERE role = 'intern' AND is_active = 1");
            }
            $internIds = array_column($internStmt->fetchAll(), 'id');
            if ($internIds) {
                notify($db, $internIds, 'session_created', 'New Learning Session',
                    "A new session \"{$title}\" is scheduled on {$sessionDate} at {$startTime}.", $sessId);
            }

            jsonResponse(['status' => 'success', 'success' => true, 'id' => $sessId, 'message' => 'Session created.']);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    jsonResponse(['status' => 'error', 'message' => 'Unknown action.'], 400);
}

/* ════════════════════════════════════════════════════════════
   PUT – admin approves/denies an access request
════════════════════════════════════════════════════════════ */
if ($method === 'PUT') {
    requireRole('admin', 'supervisor');

    $requestId = (int)($body['request_id'] ?? 0);
    $decision  = trim($body['decision']    ?? '');

    if (!$requestId) { jsonResponse(['status' => 'error', 'message' => 'request_id is required.'], 400); }
    if (!in_array($decision, ['approved', 'denied'], true)) {
        jsonResponse(['status' => 'error', 'message' => "Decision must be 'approved' or 'denied'."], 400);
    }

    try {
        $req = $db->prepare('SELECT * FROM session_access_requests WHERE id = ?');
        $req->execute([$requestId]);
        $r = $req->fetch();
        if (!$r) { jsonResponse(['status' => 'error', 'message' => 'Access request not found.'], 404); }

        $db->prepare(
            'UPDATE session_access_requests SET status = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?'
        )->execute([$decision, $auth['id'], $requestId]);

        if ($decision === 'approved') {
            $db->prepare('UPDATE intern_profiles SET session_access = 1 WHERE user_id = ?')
               ->execute([$r['user_id']]);
        }

        $type = $decision === 'approved' ? 'access_approved' : 'access_denied';
        $msg  = $decision === 'approved'
            ? 'Your request to host Learning Sessions has been approved!'
            : 'Your request to host Learning Sessions was denied.';
        notify($db, (int)$r['user_id'], $type, 'Session Access ' . ucfirst($decision), $msg);

        jsonResponse(['status' => 'success', 'success' => true, 'message' => "Request {$decision}."]); 
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   DELETE – remove a session (admin/supervisor)
════════════════════════════════════════════════════════════ */
if ($method === 'DELETE') {
    requireRole('admin', 'supervisor');

    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { jsonResponse(['status' => 'error', 'message' => 'Session ID is required.'], 400); }

    try {
        $db->prepare('DELETE FROM learning_sessions WHERE id = ?')->execute([$id]);
        jsonResponse(['status' => 'success', 'success' => true, 'message' => 'Session deleted.']);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
