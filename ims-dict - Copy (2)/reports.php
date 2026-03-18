<?php
// api/reports.php
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['ok' => true]); }

$auth   = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

define('UPLOAD_DIR_REPORTS',   __DIR__ . '/../uploads/reports/');
define('UPLOAD_DIR_TEMPLATES', __DIR__ . '/../uploads/templates/');

foreach ([UPLOAD_DIR_REPORTS, UPLOAD_DIR_TEMPLATES] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

/* ════════════════════════════════════════════════════════════
   GET – fetch reports or templates
════════════════════════════════════════════════════════════ */
if ($method === 'GET' && !isset($_GET['templates'])) {

    if ($auth['role'] === 'intern') {
        $stmt = $db->prepare('SELECT * FROM weekly_reports WHERE user_id = ? ORDER BY submitted_at DESC');
        $stmt->execute([$auth['id']]);
        jsonResponse(['success' => true, 'reports' => $stmt->fetchAll()]);
    }

    // Admin / Supervisor: filtered list
    $conditions = ['1=1'];
    $params     = [];
    if (!empty($_GET['week'])) {
        $wk = (int)$_GET['week'];
        if ($wk < 1 || $wk > 52) jsonResponse(['error' => 'Week number must be between 1 and 52.'], 400);
        $conditions[] = 'r.week_number = :week';
        $params[':week'] = $wk;
    }
    if (!empty($_GET['from'])) {
        $conditions[] = 'r.submitted_at >= :from';
        $params[':from'] = $_GET['from'] . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $conditions[] = 'r.submitted_at <= :to';
        $params[':to'] = $_GET['to'] . ' 23:59:59';
    }

    $where = implode(' AND ', $conditions);
    $stmt  = $db->prepare(
        "SELECT r.*, ip.full_name, u.intern_id
           FROM weekly_reports r
           JOIN intern_profiles ip ON ip.user_id = r.user_id
           JOIN users u ON u.id = r.user_id
          WHERE $where
          ORDER BY r.submitted_at DESC"
    );
    $stmt->execute($params);
    jsonResponse(['success' => true, 'reports' => $stmt->fetchAll()]);
}

if ($method === 'GET' && isset($_GET['templates'])) {
    $stmt = $db->query(
        'SELECT rt.*, u.username
           FROM report_templates rt
           JOIN users u ON u.id = rt.uploaded_by
           ORDER BY rt.uploaded_at DESC'
    );
    jsonResponse(['success' => true, 'templates' => $stmt->fetchAll()]);
}

/* ════════════════════════════════════════════════════════════
   POST – intern submits a weekly report (multipart/form-data)
   Spec: save physical PDF/DOC + INSERT metadata with file_path
════════════════════════════════════════════════════════════ */
if ($method === 'POST' && ($_POST['action'] ?? $body['action'] ?? '') !== 'upload_template') {

    if ($auth['role'] !== 'intern')
        jsonResponse(['error' => 'Only interns can submit weekly reports.'], 403);

    $title   = trim($_POST['title']      ?? $body['title']      ?? '');
    $weekNum = trim($_POST['week_number'] ?? $body['week_number'] ?? '');
    $range   = trim($_POST['week_range']  ?? $body['week_range']  ?? '');
    $summary = trim($_POST['summary']    ?? $body['summary']    ?? '');

    // ── Validate required fields ──
    if (!$title)   jsonResponse(['error' => 'Report title is required.'], 400);
    if (!$weekNum) jsonResponse(['error' => 'Week number is required.'], 400);
    if (!$summary) jsonResponse(['error' => 'Summary is required.'], 400);

    // ── Validate week number is an integer ──
    if (!ctype_digit((string)$weekNum) || (int)$weekNum < 1 || (int)$weekNum > 52)
        jsonResponse(['error' => 'Week number must be a whole number between 1 and 52.'], 400);
    $weekNum = (int)$weekNum;

    // ── Validate title format: LastName-Week-N ──
    if (!preg_match('/^[A-Za-z\s]+-Week-\d+$/i', $title))
        jsonResponse(['error' => 'Title must follow format: LastName-Week-N (e.g. Dela Cruz-Week-1).'], 400);

    // ── Handle file upload (spec: save physical PDF/DOC) ──
    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK)
            jsonResponse(['error' => 'File upload error code: ' . $file['error']], 400);

        $maxSize = 20 * 1024 * 1024; // 20 MB
        if ($file['size'] > $maxSize)
            jsonResponse(['error' => 'File exceeds 20 MB limit.'], 400);

        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true))
            jsonResponse(['error' => 'Invalid file type. Only PDF, DOC, and DOCX are allowed.'], 400);

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = $auth['id'] . '_week' . $weekNum . '_' . time() . '_' . $safeName . '.' . $ext;
        $dest     = UPLOAD_DIR_REPORTS . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest))
            jsonResponse(['error' => 'Failed to save file. Check server folder permissions.'], 500);

        $filePath = 'uploads/reports/' . $filename;
    }

    // ── INSERT metadata into database (spec: parallel action) ──
    try {
        $stmt = $db->prepare(
            'INSERT INTO weekly_reports (user_id, title, week_number, week_range, summary, file_path)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$auth['id'], $title, $weekNum, $range, $summary, $filePath]);
        $newId = (int)$db->lastInsertId();
    } catch (\PDOException $e) {
        // DB failed — remove physical file to keep storage consistent
        if ($filePath && file_exists(UPLOAD_DIR_REPORTS . basename($filePath))) {
            unlink(UPLOAD_DIR_REPORTS . basename($filePath));
        }
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    jsonResponse(['success' => true, 'id' => $newId, 'file_path' => $filePath]);
}

/* ════════════════════════════════════════════════════════════
   POST – admin uploads a report template
════════════════════════════════════════════════════════════ */
if ($method === 'POST' && ($_POST['action'] ?? $body['action'] ?? '') === 'upload_template') {
    requireRole('admin', 'supervisor');

    $title       = trim($_POST['title']       ?? $body['title']       ?? '');
    $description = trim($_POST['description'] ?? $body['description'] ?? '');

    if (!$title) jsonResponse(['error' => 'Template title is required.'], 400);

    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file     = $_FILES['file'];
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = 'template_' . time() . '_' . $safeName . '.' . $ext;
        $dest     = UPLOAD_DIR_TEMPLATES . $filename;
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $filePath = 'uploads/templates/' . $filename;
        }
    }

    if (!$filePath) jsonResponse(['error' => 'A template file is required.'], 400);

    try {
        $db->prepare(
            'INSERT INTO report_templates (title, description, file_path, uploaded_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$title, $description, $filePath, $auth['id']]);
    } catch (\PDOException $e) {
        if (file_exists(UPLOAD_DIR_TEMPLATES . basename($filePath))) unlink(UPLOAD_DIR_TEMPLATES . basename($filePath));
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    jsonResponse(['success' => true, 'file_path' => $filePath]);
}

/* ════════════════════════════════════════════════════════════
   PUT – admin reviews / approves a report
════════════════════════════════════════════════════════════ */
if ($method === 'PUT') {
    requireRole('admin', 'supervisor');

    $id     = (int)($body['id'] ?? 0);
    $status = trim($body['status'] ?? '');

    if (!$id) jsonResponse(['error' => 'Report ID is required.'], 400);
    if (!in_array($status, ['reviewed','approved','submitted'], true))
        jsonResponse(['error' => 'Invalid status value.'], 400);

    try {
        $db->prepare('UPDATE weekly_reports SET status = ? WHERE id = ?')->execute([$status, $id]);
    } catch (\PDOException $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    // Notify the intern
    $row = $db->prepare('SELECT user_id, title FROM weekly_reports WHERE id = ?');
    $row->execute([$id]);
    $report = $row->fetch();
    if ($report) {
        notify($db, (int)$report['user_id'], 'report_reviewed', 'Report Reviewed',
            "Your report \"{$report['title']}\" has been {$status}.");
    }

    jsonResponse(['success' => true]);
}

/* ════════════════════════════════════════════════════════════
   DELETE – intern deletes own report
   Spec: DELETE database record AND unlink physical file
════════════════════════════════════════════════════════════ */
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Report ID is required.'], 400);

    // Fetch the record first to get file_path before deleting
    if ($auth['role'] === 'intern') {
        $fetch = $db->prepare('SELECT file_path FROM weekly_reports WHERE id = ? AND user_id = ?');
        $fetch->execute([$id, $auth['id']]);
    } else {
        $fetch = $db->prepare('SELECT file_path FROM weekly_reports WHERE id = ?');
        $fetch->execute([$id]);
    }
    $report = $fetch->fetch();
    if (!$report) jsonResponse(['error' => 'Report not found or access denied.'], 404);

    // ── DELETE from database (spec) ──
    try {
        if ($auth['role'] === 'intern') {
            $db->prepare('DELETE FROM weekly_reports WHERE id = ? AND user_id = ?')
               ->execute([$id, $auth['id']]);
        } else {
            $db->prepare('DELETE FROM weekly_reports WHERE id = ?')->execute([$id]);
        }
    } catch (\PDOException $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    // ── Unlink physical file from server (spec: free up space) ──
    if (!empty($report['file_path'])) {
        $fullPath = __DIR__ . '/../' . $report['file_path'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed.'], 405);
