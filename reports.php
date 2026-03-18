<?php
// api/reports.php  — DICT IMS v3
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['status' => 'ok']); }

$auth   = requireAuth();
$db     = getDB();
$body   = getBody();
$method = $_SERVER['REQUEST_METHOD'];

define('UPLOAD_DIR_REPORTS',   __DIR__ . '/../uploads/reports/');
define('UPLOAD_DIR_TEMPLATES', __DIR__ . '/../uploads/templates/');

foreach ([UPLOAD_DIR_REPORTS, UPLOAD_DIR_TEMPLATES] as $dir) {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true)) {
            jsonResponse(['status' => 'error', 'message' => 'Could not create upload directory: ' . $dir], 500);
        }
    }
}

/* ════════════════════════════════════════════════════════════
   GET – fetch reports or templates
════════════════════════════════════════════════════════════ */
if ($method === 'GET' && !isset($_GET['templates'])) {

    if ($auth['role'] === 'intern') {
        try {
            $stmt = $db->prepare('SELECT * FROM weekly_reports WHERE user_id = ? ORDER BY submitted_at DESC');
            $stmt->execute([$auth['id']]);
            jsonResponse(['status' => 'success', 'success' => true, 'reports' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    requireRole('admin', 'supervisor');

    $conditions = ['1=1'];
    $params     = [];

    if (!empty($_GET['week'])) {
        $wk = (int)$_GET['week'];
        if ($wk < 1 || $wk > 52) {
            jsonResponse(['status' => 'error', 'message' => 'Week number must be between 1 and 52.'], 400);
        }
        $conditions[]       = 'r.week_number = :week';
        $params[':week']    = $wk;
    }
    if (!empty($_GET['from'])) {
        $conditions[]       = 'r.submitted_at >= :from';
        $params[':from']    = $_GET['from'] . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $conditions[]       = 'r.submitted_at <= :to';
        $params[':to']      = $_GET['to'] . ' 23:59:59';
    }

    $where = implode(' AND ', $conditions);

    try {
        $stmt = $db->prepare(
            "SELECT r.*, ip.full_name, u.intern_id
               FROM weekly_reports r
               JOIN intern_profiles ip ON ip.user_id = r.user_id
               JOIN users u ON u.id = r.user_id
              WHERE {$where}
              ORDER BY r.submitted_at DESC"
        );
        $stmt->execute($params);
        jsonResponse(['status' => 'success', 'success' => true, 'reports' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

if ($method === 'GET' && isset($_GET['templates'])) {
    try {
        $stmt = $db->query(
            'SELECT rt.*, u.username
               FROM report_templates rt
               JOIN users u ON u.id = rt.uploaded_by
               ORDER BY rt.uploaded_at DESC'
        );
        jsonResponse(['status' => 'success', 'success' => true, 'templates' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   POST – intern submits a weekly report (multipart/form-data)
   Spec: enctype="multipart/form-data" | move_uploaded_file |
         save only file path to DB
════════════════════════════════════════════════════════════ */
$postAction = $_POST['action'] ?? $body['action'] ?? '';

if ($method === 'POST' && $postAction !== 'upload_template') {

    if ($auth['role'] !== 'intern') {
        jsonResponse(['status' => 'error', 'message' => 'Only interns can submit weekly reports.'], 403);
    }

    // ── Validate required fields with isset/empty ──
    $title   = trim($_POST['title']       ?? '');
    $weekNum = trim($_POST['week_number'] ?? '');
    $range   = trim($_POST['week_range']  ?? '');
    $summary = trim($_POST['summary']     ?? '');

    if (empty($title))   { jsonResponse(['status' => 'error', 'message' => 'Report title is required.'], 400); }
    if (empty($weekNum)) { jsonResponse(['status' => 'error', 'message' => 'Week number is required.'], 400); }
    if (empty($summary)) { jsonResponse(['status' => 'error', 'message' => 'Summary is required.'], 400); }

    // ── Validate week number is integer 1-52 ──
    if (!ctype_digit((string)$weekNum) || (int)$weekNum < 1 || (int)$weekNum > 52) {
        jsonResponse(['status' => 'error', 'message' => 'Week number must be a whole number between 1 and 52.'], 400);
    }
    $weekNum = (int)$weekNum;

    // ── Validate title format: LastName-Week-N ──
    if (!preg_match('/^[A-Za-z\s]+-Week-\d+$/i', $title)) {
        jsonResponse(['status' => 'error', 'message' => 'Title must follow format: LastName-Week-N (e.g. Dela Cruz-Week-1).'], 400);
    }

    // ── Handle file upload (spec: save physical file) ──
    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['status' => 'error', 'message' => 'File upload error code: ' . $file['error']], 400);
        }

        $maxSize = 20 * 1024 * 1024; // 20 MB
        if ($file['size'] > $maxSize) {
            jsonResponse(['status' => 'error', 'message' => 'File exceeds 20 MB limit.'], 400);
        }

        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true)) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid file type. Only PDF, DOC, and DOCX are allowed.'], 400);
        }

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = $auth['id'] . '_week' . $weekNum . '_' . time() . '_' . $safeName . '.' . $ext;
        $dest     = UPLOAD_DIR_REPORTS . $filename;

        // ── Spec: physically move the file ──
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            jsonResponse(['status' => 'error', 'message' => 'Failed to save file. Check permissions on: uploads/reports/'], 500);
        }

        // ── Spec: save only the path to DB ──
        $filePath = 'uploads/reports/' . $filename;
    }

    // ── INSERT with prepared statement ──
    try {
        $stmt = $db->prepare(
            'INSERT INTO weekly_reports (user_id, title, week_number, week_range, summary, file_path)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$auth['id'], $title, $weekNum, $range ?: null, $summary, $filePath]);
        $newId = (int)$db->lastInsertId();

        jsonResponse([
            'status'    => 'success',
            'success'   => true,
            'id'        => $newId,
            'file_path' => $filePath,
            'message'   => 'Report submitted successfully.',
        ]);
    } catch (PDOException $e) {
        // DB failed — remove physical file to keep storage consistent
        if ($filePath && file_exists(UPLOAD_DIR_REPORTS . basename($filePath))) {
            unlink(UPLOAD_DIR_REPORTS . basename($filePath));
        }
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   POST – admin uploads a report template
════════════════════════════════════════════════════════════ */
if ($method === 'POST' && $postAction === 'upload_template') {
    requireRole('admin', 'supervisor');

    $title       = trim($_POST['title']       ?? $body['title']       ?? '');
    $description = trim($_POST['description'] ?? $body['description'] ?? '');

    if (empty($title)) {
        jsonResponse(['status' => 'error', 'message' => 'Template title is required.'], 400);
    }

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

    if (!$filePath) {
        jsonResponse(['status' => 'error', 'message' => 'A template file is required.'], 400);
    }

    try {
        $db->prepare(
            'INSERT INTO report_templates (title, description, file_path, uploaded_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$title, $description ?: null, $filePath, $auth['id']]);

        jsonResponse(['status' => 'success', 'success' => true, 'file_path' => $filePath, 'message' => 'Template uploaded.']);
    } catch (PDOException $e) {
        if (file_exists(UPLOAD_DIR_TEMPLATES . basename($filePath))) {
            unlink(UPLOAD_DIR_TEMPLATES . basename($filePath));
        }
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   PUT – admin reviews/approves a report
════════════════════════════════════════════════════════════ */
if ($method === 'PUT') {
    requireRole('admin', 'supervisor');

    $id     = (int)($body['id']     ?? 0);
    $status = trim($body['status'] ?? '');

    if (!$id) { jsonResponse(['status' => 'error', 'message' => 'Report ID is required.'], 400); }
    if (!in_array($status, ['reviewed', 'approved', 'submitted'], true)) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid status. Must be: reviewed, approved, or submitted.'], 400);
    }

    try {
        $db->prepare('UPDATE weekly_reports SET status = ? WHERE id = ?')->execute([$status, $id]);

        // Notify intern
        $row = $db->prepare('SELECT user_id, title FROM weekly_reports WHERE id = ?');
        $row->execute([$id]);
        $report = $row->fetch();
        if ($report) {
            notify($db, (int)$report['user_id'], 'report_reviewed', 'Report Reviewed',
                "Your report \"{$report['title']}\" has been {$status}.");
        }

        jsonResponse(['status' => 'success', 'success' => true, 'message' => "Report marked as {$status}."]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   DELETE – intern/admin deletes a report
   Spec: DELETE from database AND unlink physical file
════════════════════════════════════════════════════════════ */
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { jsonResponse(['status' => 'error', 'message' => 'Report ID is required.'], 400); }

    try {
        // Fetch file path before deleting
        if ($auth['role'] === 'intern') {
            $fetch = $db->prepare('SELECT file_path FROM weekly_reports WHERE id = ? AND user_id = ?');
            $fetch->execute([$id, $auth['id']]);
        } else {
            requireRole('admin', 'supervisor');
            $fetch = $db->prepare('SELECT file_path FROM weekly_reports WHERE id = ?');
            $fetch->execute([$id]);
        }
        $report = $fetch->fetch();

        if (!$report) {
            jsonResponse(['status' => 'error', 'message' => 'Report not found or access denied.'], 404);
        }

        // ── DELETE from DB ──
        if ($auth['role'] === 'intern') {
            $db->prepare('DELETE FROM weekly_reports WHERE id = ? AND user_id = ?')
               ->execute([$id, $auth['id']]);
        } else {
            $db->prepare('DELETE FROM weekly_reports WHERE id = ?')->execute([$id]);
        }

        // ── Spec: unlink physical file to free up server space ──
        if (!empty($report['file_path'])) {
            $fullPath = __DIR__ . '/../' . $report['file_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
        }

        jsonResponse(['status' => 'success', 'success' => true, 'message' => 'Report deleted.']);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
