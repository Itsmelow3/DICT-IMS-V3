<?php
// api/attendance.php  — DICT IMS v3
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['status' => 'ok']); }

$auth   = requireAuth();
$db     = getDB();
$body   = getBody();
$method = $_SERVER['REQUEST_METHOD'];

/* ════════════════════════════════════════════════════════════
   GET – list attendance records
════════════════════════════════════════════════════════════ */
if ($method === 'GET') {

    $uid    = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)$auth['id'];
    $month  = (int)($_GET['month']  ?? date('m'));
    $year   = (int)($_GET['year']   ?? date('Y'));
    $status = trim($_GET['status']  ?? '');
    $filter = trim($_GET['filter']  ?? 'month');

    // Interns may only view their own records
    if ($auth['role'] === 'intern' && $uid !== $auth['id']) {
        jsonResponse(['status' => 'error', 'message' => 'Forbidden – you may only view your own attendance.'], 403);
    }

    /* ── Admin/Supervisor: all interns (user_id=0 means "all") ── */
    if (in_array($auth['role'], ['admin', 'supervisor'], true) && $uid === 0) {

        $conditions = ['1=1'];
        $params     = [];

        if (!empty($_GET['date'])) {
            $conditions[]       = 'a.attendance_date = :date';
            $params[':date']    = $_GET['date'];
        } elseif (!empty($_GET['from']) && !empty($_GET['to'])) {
            $conditions[]      = 'a.attendance_date BETWEEN :from AND :to';
            $params[':from']   = $_GET['from'];
            $params[':to']     = $_GET['to'];
        } else {
            $conditions[] = "a.attendance_date BETWEEN DATE_FORMAT(NOW(),'%Y-%m-01') AND LAST_DAY(NOW())";
        }

        if (!empty($status)) {
            $valid = ['Full Day', 'Half Day', 'Early Out', 'Absent', 'In Progress'];
            if (!in_array($status, $valid, true)) {
                jsonResponse(['status' => 'error', 'message' => 'Invalid status filter.'], 400);
            }
            $conditions[]       = 'a.attendance_status = :status';
            $params[':status']  = $status;
        }

        if (!empty($_GET['intern_name'])) {
            $conditions[]       = 'ip.full_name LIKE :iname';
            $params[':iname']   = '%' . $_GET['intern_name'] . '%';
        }

        $where = implode(' AND ', $conditions);
        $sql   = "SELECT a.*, ip.full_name, ip.province, u.intern_id
                    FROM attendance a
                    JOIN intern_profiles ip ON ip.user_id = a.user_id
                    JOIN users u ON u.id = a.user_id
                   WHERE {$where}
                   ORDER BY a.attendance_date DESC, ip.full_name ASC";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsonResponse(['status' => 'success', 'success' => true, 'records' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    /* ── Intern or single-user view ── */
    $conditions = ['a.user_id = :uid'];
    $params     = [':uid' => $uid];

    if ($filter === 'day') {
        $conditions[] = 'a.attendance_date = CURDATE()';
    } elseif ($filter === 'week') {
        $conditions[] = 'YEARWEEK(a.attendance_date, 1) = YEARWEEK(CURDATE(), 1)';
    } else {
        $conditions[]  = 'MONTH(a.attendance_date) = :m AND YEAR(a.attendance_date) = :y';
        $params[':m']  = $month;
        $params[':y']  = $year;
    }

    if (!empty($status)) {
        $conditions[]       = 'a.attendance_status = :status';
        $params[':status']  = $status;
    }

    $where = implode(' AND ', $conditions);

    try {
        $stmt = $db->prepare("SELECT * FROM attendance a WHERE {$where} ORDER BY a.attendance_date DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Total accumulated hours all-time
        $th = $db->prepare('SELECT COALESCE(SUM(hours_rendered), 0) FROM attendance WHERE user_id = ?');
        $th->execute([$uid]);
        $totalHours = (float)$th->fetchColumn();

        jsonResponse([
            'status'      => 'success',
            'success'     => true,
            'records'     => $rows,
            'total_hours' => $totalHours,
        ]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   POST – log Time In / Time Out  (interns only)

   Logic:
   - AM Time In  → INSERT a new row for today
   - AM/PM Time Out, PM Time In → UPDATE the existing row
   - PM Time Out  → UPDATE hours + status, deduct OJT counter
════════════════════════════════════════════════════════════ */
if ($method === 'POST') {

    if ($auth['role'] !== 'intern') {
        jsonResponse(['status' => 'error', 'message' => 'Only interns can log attendance.'], 403);
    }

    // ── Validate action and session from POST body ──
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['status' => 'error', 'message' => 'POST method required.'], 405);
    }

    $action  = trim($body['action']  ?? '');
    $session = strtoupper(trim($body['session'] ?? ''));
    $uid     = (int)$auth['id'];
    $today   = date('Y-m-d');
    $now     = date('H:i:s');

    // ── isset/empty validation ──
    if (empty($session) || !in_array($session, ['AM', 'PM'], true)) {
        jsonResponse(['status' => 'error', 'message' => 'Session must be AM or PM.'], 400);
    }
    if (empty($action) || !in_array($action, ['time_in', 'time_out'], true)) {
        jsonResponse(['status' => 'error', 'message' => 'Action must be time_in or time_out.'], 400);
    }

    $colMap = [
        'AM_time_in'  => 'am_time_in',
        'AM_time_out' => 'am_time_out',
        'PM_time_in'  => 'pm_time_in',
        'PM_time_out' => 'pm_time_out',
    ];
    $col = $colMap["{$session}_{$action}"] ?? null;
    if (!$col) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid session/action combination.'], 400);
    }

    // ── Check for today's existing record ──
    try {
        $chk = $db->prepare('SELECT * FROM attendance WHERE user_id = ? AND attendance_date = ?');
        $chk->execute([$uid, $today]);
        $rec = $chk->fetch();
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }

    /* ── AM Time In: INSERT new row ── */
    if ($col === 'am_time_in') {
        if ($rec) {
            jsonResponse(['status' => 'error', 'message' => 'Morning Time In already recorded for today.'], 409);
        }
        try {
            $ins = $db->prepare(
                'INSERT INTO attendance (user_id, attendance_date, am_time_in, attendance_status)
                 VALUES (?, ?, ?, ?)'
            );
            $ins->execute([$uid, $today, $now, 'In Progress']);

            $chk->execute([$uid, $today]);
            $rec = $chk->fetch();

            jsonResponse([
                'status'  => 'success',
                'success' => true,
                'time'    => $now,
                'column'  => $col,
                'record'  => $rec,
                'message' => 'Morning Time In recorded successfully.',
            ]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    /* ── All subsequent punches: UPDATE ── */
    if (!$rec) {
        jsonResponse(['status' => 'error', 'message' => 'No attendance record for today. Please log Morning Time In first.'], 404);
    }
    if (!empty($rec[$col])) {
        jsonResponse(['status' => 'error', 'message' => ucwords(str_replace('_', ' ', $col)) . ' already recorded.'], 409);
    }

    // Ensure matching Time In exists before Time Out
    if ($action === 'time_out') {
        $inCol = str_replace('_out', '_in', $col);
        if (empty($rec[$inCol])) {
            jsonResponse(['status' => 'error', 'message' => 'Cannot Time Out before Time In for this session.'], 400);
        }
    }

    // PM session requires AM time_in first
    if ($session === 'PM' && $action === 'time_in' && empty($rec['am_time_in'])) {
        jsonResponse(['status' => 'error', 'message' => 'Morning Time In must be recorded first.'], 400);
    }

    try {
        $upd = $db->prepare("UPDATE attendance SET {$col} = ? WHERE user_id = ? AND attendance_date = ?");
        $upd->execute([$now, $uid, $today]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }

    /* ── PM Time Out: recalculate hours and update OJT counter ── */
    if ($col === 'pm_time_out') {
        try {
            $chk->execute([$uid, $today]);
            $rec = $chk->fetch();

            $totalMins = 0;
            if (!empty($rec['am_time_in']) && !empty($rec['am_time_out'])) {
                $totalMins += (int)((strtotime($rec['am_time_out']) - strtotime($rec['am_time_in'])) / 60);
            }
            if (!empty($rec['pm_time_in']) && !empty($rec['pm_time_out'])) {
                $totalMins += (int)((strtotime($rec['pm_time_out']) - strtotime($rec['pm_time_in'])) / 60);
            }
            $totalMins  = max(0, $totalMins);
            $hoursFloat = round($totalMins / 60, 4);

            if ($totalMins >= 480)     $attStatus = 'Full Day';
            elseif ($totalMins >= 240) $attStatus = 'Half Day';
            elseif ($totalMins > 0)    $attStatus = 'Early Out';
            else                       $attStatus = 'Absent';

            $db->prepare(
                'UPDATE attendance
                    SET hours_rendered = ?, minutes_rendered = ?, attendance_status = ?
                  WHERE user_id = ? AND attendance_date = ?'
            )->execute([$hoursFloat, $totalMins, $attStatus, $uid, $today]);

            // Deduct from OJT counter
            $db->prepare(
                'UPDATE intern_profiles
                    SET ojt_hours_required = GREATEST(0, ojt_hours_required - ?)
                  WHERE user_id = ?'
            )->execute([$hoursFloat, $uid]);

        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    // Re-fetch the final record
    try {
        $chk->execute([$uid, $today]);
        $rec = $chk->fetch();
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }

    jsonResponse([
        'status'  => 'success',
        'success' => true,
        'time'    => $now,
        'column'  => $col,
        'record'  => $rec,
        'message' => ($session === 'AM' ? 'Morning' : 'Afternoon') . ' ' . ($action === 'time_in' ? 'Time In' : 'Time Out') . ' recorded successfully.',
    ]);
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
