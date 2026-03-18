<?php
// api/attendance.php
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['ok' => true]); }

$auth   = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

/* ════════════════════════════════════════════════════════════
   GET – list attendance records
════════════════════════════════════════════════════════════ */
if ($method === 'GET') {

    $uid    = (int)($_GET['user_id'] ?? $auth['id']);
    $month  = (int)($_GET['month']   ?? date('m'));
    $year   = (int)($_GET['year']    ?? date('Y'));
    $status = trim($_GET['status'] ?? '');
    $filter = trim($_GET['filter'] ?? 'month');

    // Interns may only view their own records
    if ($auth['role'] === 'intern' && $uid !== $auth['id'])
        jsonResponse(['error' => 'Forbidden – you may only view your own attendance.'], 403);

    /* ── Admin / Supervisor: fetch all interns ── */
    if (in_array($auth['role'], ['admin','supervisor'], true) && isset($_GET['user_id']) && (int)$_GET['user_id'] === 0) {

        $conditions = ['1=1'];
        $params     = [];

        // Date filters (prepared, not interpolated)
        if (!empty($_GET['date'])) {
            $conditions[] = 'a.attendance_date = :date';
            $params[':date'] = $_GET['date'];
        } elseif (!empty($_GET['from']) && !empty($_GET['to'])) {
            $conditions[] = 'a.attendance_date BETWEEN :from AND :to';
            $params[':from'] = $_GET['from'];
            $params[':to']   = $_GET['to'];
        } else {
            $conditions[] = "a.attendance_date BETWEEN DATE_FORMAT(NOW(),'%Y-%m-01') AND LAST_DAY(NOW())";
        }

        if ($status) {
            $valid = ['Full Day','Half Day','Early Out','Absent','In Progress'];
            if (!in_array($status, $valid, true))
                jsonResponse(['error' => 'Invalid status filter.'], 400);
            $conditions[] = 'a.attendance_status = :status';
            $params[':status'] = $status;
        }

        if (!empty($_GET['intern_name'])) {
            $conditions[] = 'ip.full_name LIKE :iname';
            $params[':iname'] = '%' . $_GET['intern_name'] . '%';
        }

        $where = implode(' AND ', $conditions);
        $sql   = "SELECT a.*, ip.full_name, ip.province, u.intern_id
                    FROM attendance a
                    JOIN intern_profiles ip ON ip.user_id = a.user_id
                    JOIN users u ON u.id = a.user_id
                   WHERE $where
                   ORDER BY a.attendance_date DESC, ip.full_name ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        jsonResponse(['success' => true, 'records' => $stmt->fetchAll()]);
    }

    /* ── Intern: own records ── */
    $conditions = ['a.user_id = :uid'];
    $params     = [':uid' => $uid];

    if ($filter === 'day') {
        $conditions[] = 'a.attendance_date = CURDATE()';
    } elseif ($filter === 'week') {
        $conditions[] = 'YEARWEEK(a.attendance_date, 1) = YEARWEEK(CURDATE(), 1)';
    } else {
        // Default: month
        $conditions[] = 'MONTH(a.attendance_date) = :m AND YEAR(a.attendance_date) = :y';
        $params[':m'] = $month;
        $params[':y'] = $year;
    }

    if ($status) {
        $conditions[] = 'a.attendance_status = :status';
        $params[':status'] = $status;
    }

    $where = implode(' AND ', $conditions);
    $stmt  = $db->prepare("SELECT * FROM attendance a WHERE $where ORDER BY a.attendance_date DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Total accumulated hours all time
    $th = $db->prepare('SELECT COALESCE(SUM(hours_rendered), 0) FROM attendance WHERE user_id = ?');
    $th->execute([$uid]);
    $totalHours = (float)$th->fetchColumn();

    jsonResponse(['success' => true, 'records' => $rows, 'total_hours' => $totalHours]);
}

/* ════════════════════════════════════════════════════════════
   POST – log Time In / Time Out  (interns only)

   Logic per spec:
   - AM Time In  → INSERT a new row for today (one row per day)
   - AM Time Out → UPDATE the existing row
   - PM Time In  → UPDATE the existing row
   - PM Time Out → UPDATE the existing row
                   THEN recalculate & UPDATE intern OJT hours
════════════════════════════════════════════════════════════ */
if ($method === 'POST') {

    if ($auth['role'] !== 'intern')
        jsonResponse(['error' => 'Only interns can log attendance.'], 403);

    $action  = trim($body['action']  ?? '');
    $session = strtoupper(trim($body['session'] ?? ''));
    $uid     = (int)$auth['id'];
    $today   = date('Y-m-d');
    $now     = date('H:i:s');

    // Validate inputs
    if (!in_array($session, ['AM','PM'], true))
        jsonResponse(['error' => 'Session must be AM or PM.'], 400);
    if (!in_array($action, ['time_in','time_out'], true))
        jsonResponse(['error' => 'Action must be time_in or time_out.'], 400);

    // Map to column name
    $colMap = [
        'AM_time_in'  => 'am_time_in',
        'AM_time_out' => 'am_time_out',
        'PM_time_in'  => 'pm_time_in',
        'PM_time_out' => 'pm_time_out',
    ];
    $col = $colMap["{$session}_{$action}"] ?? null;
    if (!$col) jsonResponse(['error' => 'Invalid session/action combination.'], 400);

    // Fetch today's existing row (if any)
    $chk = $db->prepare('SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ?');
    $chk->execute([$uid, $today]);
    $rec = $chk->fetch();

    /* ── AM Time In: must INSERT (spec rule) ── */
    if ($col === 'am_time_in') {
        if ($rec)
            jsonResponse(['error' => 'Morning Time In already recorded for today.'], 409);

        try {
            $ins = $db->prepare(
                'INSERT INTO attendance (user_id, attendance_date, am_time_in, attendance_status)
                 VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$uid, $today, $now, 'In Progress']);
        } catch (\PDOException $e) {
            jsonResponse(['error' => 'Database error on insert: ' . $e->getMessage()], 500);
        }

        $chk->execute([$uid, $today]);
        $rec = $chk->fetch();
        jsonResponse(['success' => true, 'time' => $now, 'column' => $col, 'record' => $rec]);
    }

    /* ── All subsequent punches: must UPDATE (spec rule) ── */
    if (!$rec)
        jsonResponse(['error' => 'No attendance record found for today. Please Time In (Morning) first.'], 404);

    if ($rec[$col])
        jsonResponse(['error' => ucwords(str_replace('_', ' ', $col)) . ' already recorded.'], 409);

    // Ensure the matching Time In exists before allowing Time Out
    if ($action === 'time_out') {
        $inCol = str_replace('_out', '_in', $col);
        if (empty($rec[$inCol]))
            jsonResponse(['error' => 'Cannot Time Out before Time In for this session.'], 400);
    }

    // Ensure AM is completed before PM starts
    if ($session === 'PM' && $action === 'time_in' && empty($rec['am_time_in']))
        jsonResponse(['error' => 'Morning Time In must be recorded first.'], 400);

    try {
        $upd = $db->prepare("UPDATE attendance SET {$col} = ? WHERE user_id = ? AND attendance_date = ?");
        $upd->execute([$now, $uid, $today]);
    } catch (\PDOException $e) {
        jsonResponse(['error' => 'Database error on update: ' . $e->getMessage()], 500);
    }

    /* ── PM Time Out: recalculate hours and deduct from OJT counter (spec rule) ── */
    if ($col === 'pm_time_out') {
        // Re-fetch with the new pm_time_out value
        $chk->execute([$uid, $today]);
        $rec = $chk->fetch();

        $totalMins = 0;
        if ($rec['am_time_in'] && $rec['am_time_out']) {
            $totalMins += (int)((strtotime($rec['am_time_out']) - strtotime($rec['am_time_in'])) / 60);
        }
        if ($rec['pm_time_in'] && $rec['pm_time_out']) {
            $totalMins += (int)((strtotime($rec['pm_time_out']) - strtotime($rec['pm_time_in'])) / 60);
        }
        $totalMins  = max(0, $totalMins);
        $hoursFloat = round($totalMins / 60, 4);

        // Determine attendance status
        if ($totalMins >= 480)      $attStatus = 'Full Day';
        elseif ($totalMins >= 240)  $attStatus = 'Half Day';
        elseif ($totalMins > 0)     $attStatus = 'Early Out';
        else                        $attStatus = 'Absent';

        try {
            $db->prepare(
                'UPDATE attendance SET hours_rendered=?, minutes_rendered=?, attendance_status=? WHERE user_id=? AND attendance_date=?'
            )->execute([$hoursFloat, $totalMins, $attStatus, $uid, $today]);
        } catch (\PDOException $e) {
            jsonResponse(['error' => 'Failed to save hours: ' . $e->getMessage()], 500);
        }

        // Deduct hours from OJT counter (spec: UPDATE interns table)
        try {
            $db->prepare(
                'UPDATE intern_profiles
                    SET ojt_hours_required = GREATEST(0, ojt_hours_required - ?)
                  WHERE user_id = ?'
            )->execute([$hoursFloat, $uid]);
        } catch (\PDOException $e) {
            // Non-fatal — log but don't block the response
            error_log('OJT deduction failed for user ' . $uid . ': ' . $e->getMessage());
        }
    }

    // Return updated record
    $chk->execute([$uid, $today]);
    $rec = $chk->fetch();
    jsonResponse(['success' => true, 'time' => $now, 'column' => $col, 'record' => $rec]);
}

jsonResponse(['error' => 'Method not allowed.'], 405);
