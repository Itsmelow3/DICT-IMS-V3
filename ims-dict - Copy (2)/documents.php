<?php
// api/documents.php
require_once __DIR__ . '/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['ok' => true]); }

$auth   = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Upload directory (relative to project root)
define('UPLOAD_DIR_DOCS', __DIR__ . '/../uploads/documents/');

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR_DOCS)) {
    mkdir(UPLOAD_DIR_DOCS, 0755, true);
}

/* ════════════════════════════════════════════════════════════
   GET – fetch submissions or assignments
════════════════════════════════════════════════════════════ */
if ($method === 'GET' && !isset($_GET['assignments'])) {

    if ($auth['role'] === 'intern') {
        $s = $db->prepare('SELECT * FROM documents WHERE user_id = ? ORDER BY submitted_at DESC');
        $s->execute([$auth['id']]);
        $a = $db->prepare(
            'SELECT * FROM document_assignments
              WHERE target_user_id = ? OR target_user_id IS NULL
              ORDER BY assigned_at DESC'
        );
        $a->execute([$auth['id']]);
        jsonResponse(['success' => true, 'documents' => $s->fetchAll(), 'assignments' => $a->fetchAll()]);
    }

    // Admin / Supervisor: filtered list
    $conditions = ['1=1'];
    $params     = [];

    if (!empty($_GET['type'])) {
        $conditions[] = 'd.doc_type = :type';
        $params[':type'] = $_GET['type'];
    }
    if (!empty($_GET['name'])) {
        $conditions[] = 'ip.full_name LIKE :name';
        $params[':name'] = '%' . $_GET['name'] . '%';
    }
    if (!empty($_GET['intern_id'])) {
        $conditions[] = 'u.intern_id = :iid';
        $params[':iid'] = $_GET['intern_id'];
    }

    $where = implode(' AND ', $conditions);
    $stmt  = $db->prepare(
        "SELECT d.*, ip.full_name, u.intern_id, u.username
           FROM documents d
           JOIN intern_profiles ip ON ip.user_id = d.user_id
           JOIN users u ON u.id = d.user_id
          WHERE $where
          ORDER BY d.submitted_at DESC"
    );
    $stmt->execute($params);
    jsonResponse(['success' => true, 'documents' => $stmt->fetchAll()]);
}

// GET: assignment list (admin)
if ($method === 'GET' && isset($_GET['assignments'])) {
    requireRole('admin', 'supervisor');
    $stmt = $db->query(
        'SELECT da.*, u.username
           FROM document_assignments da
           LEFT JOIN users u ON u.id = da.assigned_by
           ORDER BY da.assigned_at DESC'
    );
    jsonResponse(['success' => true, 'assignments' => $stmt->fetchAll()]);
}

/* ════════════════════════════════════════════════════════════
   POST – intern submits a document (multipart/form-data)
   Spec: save physical file + INSERT metadata with file_path
════════════════════════════════════════════════════════════ */
if ($method === 'POST' && ($_POST['action'] ?? $body['action'] ?? '') !== 'assign') {

    if ($auth['role'] !== 'intern')
        jsonResponse(['error' => 'Only interns can submit documents.'], 403);

    // Read from $_POST (multipart form) or JSON body
    $title    = trim($_POST['title']    ?? $body['title']    ?? '');
    $docType  = trim($_POST['doc_type'] ?? $body['doc_type'] ?? '');
    $notes    = trim($_POST['notes']    ?? $body['notes']    ?? '');

    // ── Validate required fields ──
    if (!$title)   jsonResponse(['error' => 'Document title is required.'], 400);
    if (!$docType) jsonResponse(['error' => 'Document type is required.'], 400);
    if (!$notes)   jsonResponse(['error' => 'Note/Description is required.'], 400);

    // ── Validate title format: LastName-Type ──
    if (!preg_match('/^[A-Za-z\s]+-[A-Za-z]/', $title))
        jsonResponse(['error' => 'Title must follow format: LastName-Type (e.g. Dela Cruz-Waiver).'], 400);

    // ── Validate doc_type value ──
    $validTypes = ['resume','endorsement','application','nda','waiver','medical','other'];
    if (!in_array($docType, $validTypes, true))
        jsonResponse(['error' => 'Invalid document type.'], 400);

    // ── Handle file upload (spec: save physical file) ──
    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK)
            jsonResponse(['error' => 'File upload error code: ' . $file['error']], 400);

        $maxSize = 10 * 1024 * 1024; // 10 MB
        if ($file['size'] > $maxSize)
            jsonResponse(['error' => 'File exceeds 10 MB limit.'], 400);

        $allowedMimes = ['application/pdf','application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg','image/png'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true))
            jsonResponse(['error' => 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG.'], 400);

        // Safe filename: InternID_timestamp_originalname
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = $auth['id'] . '_' . time() . '_' . $safeName . '.' . $ext;
        $dest     = UPLOAD_DIR_DOCS . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest))
            jsonResponse(['error' => 'Failed to save file to server. Check folder permissions.'], 500);

        $filePath = 'uploads/documents/' . $filename;
    }

    // ── INSERT metadata into database (spec: parallel action) ──
    try {
        $stmt = $db->prepare(
            'INSERT INTO documents (user_id, doc_type, title, notes, file_path)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$auth['id'], $docType, $title, $notes, $filePath]);
        $newId = (int)$db->lastInsertId();
    } catch (\PDOException $e) {
        // If DB insert fails, remove the uploaded file to keep storage clean
        if ($filePath && file_exists(UPLOAD_DIR_DOCS . basename($filePath))) {
            unlink(UPLOAD_DIR_DOCS . basename($filePath));
        }
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    jsonResponse(['success' => true, 'id' => $newId, 'file_path' => $filePath]);
}

/* ════════════════════════════════════════════════════════════
   POST – admin assigns a document to intern(s)
   Supports optional file attachment for interns to download
════════════════════════════════════════════════════════════ */
if ($method === 'POST' && ($_POST['action'] ?? $body['action'] ?? '') === 'assign') {
    requireRole('admin', 'supervisor');

    $title       = trim($_POST['title']       ?? $body['title']       ?? '');
    $description = trim($_POST['description'] ?? $body['description'] ?? '');
    $targetId    = isset($_POST['target_user_id']) ? (int)$_POST['target_user_id']
                 : (isset($body['target_user_id'])  ? (int)$body['target_user_id'] : null);

    if (!$title) jsonResponse(['error' => 'Document title is required.'], 400);

    // Optional file upload for outbound assignment
    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $file     = $_FILES['file'];
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = 'assignment_' . time() . '_' . $safeName . '.' . $ext;
        $dest     = UPLOAD_DIR_DOCS . $filename;
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            $filePath = 'uploads/documents/' . $filename;
        }
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO document_assignments (assigned_by, target_user_id, title, description, file_path)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$auth['id'], $targetId ?: null, $title, $description, $filePath]);
    } catch (\PDOException $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    // Notify intern(s)
    if ($targetId) {
        notify($db, $targetId, 'document_request', 'Document Requested',
            "Admin has requested you submit: {$title}");
    } else {
        $interns = $db->query("SELECT id FROM users WHERE role='intern' AND is_active=1")->fetchAll();
        $ids     = array_column($interns, 'id');
        if ($ids) notify($db, $ids, 'document_request', 'Document Requested',
            "Admin has requested: {$title}");
    }

    jsonResponse(['success' => true, 'file_path' => $filePath]);
}

/* ════════════════════════════════════════════════════════════
   PUT – admin approves or rejects a submission
════════════════════════════════════════════════════════════ */
if ($method === 'PUT') {
    requireRole('admin', 'supervisor');

    $id     = (int)($body['id'] ?? 0);
    $status = trim($body['status'] ?? '');

    if (!$id) jsonResponse(['error' => 'Document ID is required.'], 400);
    if (!in_array($status, ['approved','rejected','pending'], true))
        jsonResponse(['error' => 'Invalid status value.'], 400);

    try {
        $db->prepare(
            'UPDATE documents SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
        )->execute([$status, $auth['id'], $id]);
    } catch (\PDOException $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    jsonResponse(['success' => true]);
}

/* ════════════════════════════════════════════════════════════
   DELETE – intern deletes own submission
   Note: does NOT delete physical files (documents may need
   to be retained for audit; only reports spec requires unlink)
════════════════════════════════════════════════════════════ */
if ($method === 'DELETE') {
    $id  = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Document ID is required.'], 400);

    try {
        if ($auth['role'] === 'intern') {
            $db->prepare('DELETE FROM documents WHERE id = ? AND user_id = ?')
               ->execute([$id, $auth['id']]);
        } else {
            $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
        }
    } catch (\PDOException $e) {
        jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
    }

    jsonResponse(['success' => true]);
}

jsonResponse(['error' => 'Method not allowed.'], 405);
