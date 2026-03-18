<?php
// api/documents.php  — DICT IMS v3
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { jsonResponse(['status' => 'ok']); }

$auth   = requireAuth();
$db     = getDB();
$body   = getBody();
$method = $_SERVER['REQUEST_METHOD'];

define('UPLOAD_DIR_DOCS', __DIR__ . '/../uploads/documents/');

// Ensure upload directory exists
if (!is_dir(UPLOAD_DIR_DOCS)) {
    if (!mkdir(UPLOAD_DIR_DOCS, 0755, true)) {
        jsonResponse(['status' => 'error', 'message' => 'Could not create upload directory. Check server permissions.'], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   GET – fetch submissions or assignments
════════════════════════════════════════════════════════════ */
if ($method === 'GET' && !isset($_GET['assignments'])) {

    if ($auth['role'] === 'intern') {
        try {
            $s = $db->prepare('SELECT * FROM documents WHERE user_id = ? ORDER BY submitted_at DESC');
            $s->execute([$auth['id']]);

            $a = $db->prepare(
                'SELECT * FROM document_assignments
                  WHERE target_user_id = ? OR target_user_id IS NULL
                  ORDER BY assigned_at DESC'
            );
            $a->execute([$auth['id']]);

            jsonResponse([
                'status'      => 'success',
                'success'     => true,
                'documents'   => $s->fetchAll(),
                'assignments' => $a->fetchAll(),
            ]);
        } catch (PDOException $e) {
            jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
        }
    }

    // Admin / Supervisor
    requireRole('admin', 'supervisor');

    $conditions = ['1=1'];
    $params     = [];

    if (!empty($_GET['type'])) {
        $conditions[]       = 'd.doc_type = :type';
        $params[':type']    = $_GET['type'];
    }
    if (!empty($_GET['name'])) {
        $conditions[]       = 'ip.full_name LIKE :name';
        $params[':name']    = '%' . $_GET['name'] . '%';
    }
    if (!empty($_GET['intern_id'])) {
        $conditions[]       = 'u.intern_id = :iid';
        $params[':iid']     = $_GET['intern_id'];
    }

    $where = implode(' AND ', $conditions);

    try {
        $stmt = $db->prepare(
            "SELECT d.*, ip.full_name, u.intern_id, u.username
               FROM documents d
               JOIN intern_profiles ip ON ip.user_id = d.user_id
               JOIN users u ON u.id = d.user_id
              WHERE {$where}
              ORDER BY d.submitted_at DESC"
        );
        $stmt->execute($params);
        jsonResponse(['status' => 'success', 'success' => true, 'documents' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

// GET: admin assignment list
if ($method === 'GET' && isset($_GET['assignments'])) {
    requireRole('admin', 'supervisor');
    try {
        $stmt = $db->query(
            'SELECT da.*, u.username
               FROM document_assignments da
               LEFT JOIN users u ON u.id = da.assigned_by
               ORDER BY da.assigned_at DESC'
        );
        jsonResponse(['status' => 'success', 'success' => true, 'assignments' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   POST – intern submits a document  (multipart/form-data)
   Spec: enctype="multipart/form-data" | move_uploaded_file |
         save only the file path to DB, not the file binary
════════════════════════════════════════════════════════════ */
$postAction = $_POST['action'] ?? $body['action'] ?? '';

if ($method === 'POST' && $postAction !== 'assign') {

    if ($auth['role'] !== 'intern') {
        jsonResponse(['status' => 'error', 'message' => 'Only interns can submit documents.'], 403);
    }

    // ── Validate required $_POST fields with isset/empty ──
    $title   = trim($_POST['title']    ?? '');
    $docType = trim($_POST['doc_type'] ?? '');
    $notes   = trim($_POST['notes']    ?? '');

    if (empty($title))   { jsonResponse(['status' => 'error', 'message' => 'Document title is required.'], 400); }
    if (empty($docType)) { jsonResponse(['status' => 'error', 'message' => 'Document type is required.'], 400); }
    if (empty($notes))   { jsonResponse(['status' => 'error', 'message' => 'Note/Description is required.'], 400); }

    // ── Validate title format: LastName-Type ──
    if (!preg_match('/^[A-Za-z\s]+-[A-Za-z]/', $title)) {
        jsonResponse(['status' => 'error', 'message' => 'Title must follow format: LastName-Type (e.g. Dela Cruz-Waiver).'], 400);
    }

    // ── Validate doc_type value ──
    $validTypes = ['resume', 'endorsement', 'application', 'nda', 'waiver', 'medical', 'other'];
    if (!in_array($docType, $validTypes, true)) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid document type.'], 400);
    }

    // ── Handle file upload (spec: enctype multipart/form-data) ──
    $filePath = null;
    if (!empty($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['status' => 'error', 'message' => 'File upload error code: ' . $file['error']], 400);
        }

        $maxSize = 10 * 1024 * 1024; // 10 MB
        if ($file['size'] > $maxSize) {
            jsonResponse(['status' => 'error', 'message' => 'File exceeds 10 MB limit.'], 400);
        }

        $allowedMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/jpeg',
            'image/png',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true)) {
            jsonResponse(['status' => 'error', 'message' => 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG.'], 400);
        }

        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = $auth['id'] . '_' . time() . '_' . $safeName . '.' . $ext;
        $dest     = UPLOAD_DIR_DOCS . $filename;

        // ── Spec: physically move the file using move_uploaded_file() ──
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            jsonResponse(['status' => 'error', 'message' => 'Failed to save file. Check folder permissions on: uploads/documents/'], 500);
        }

        // ── Spec: save only the file PATH to DB, not the binary ──
        $filePath = 'uploads/documents/' . $filename;
    }

    // ── INSERT with prepared statement — ZERO SQL injection ──
    try {
        $stmt = $db->prepare(
            'INSERT INTO documents (user_id, doc_type, title, notes, file_path)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$auth['id'], $docType, $title, $notes, $filePath]);
        $newId = (int)$db->lastInsertId();

        jsonResponse([
            'status'    => 'success',
            'success'   => true,
            'id'        => $newId,
            'file_path' => $filePath,
            'message'   => 'Document submitted successfully.',
        ]);
    } catch (PDOException $e) {
        // DB failed — remove physical file to keep storage clean
        if ($filePath && file_exists(UPLOAD_DIR_DOCS . basename($filePath))) {
            unlink(UPLOAD_DIR_DOCS . basename($filePath));
        }
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   POST – admin assigns a document request to intern(s)
════════════════════════════════════════════════════════════ */
if ($method === 'POST' && $postAction === 'assign') {
    requireRole('admin', 'supervisor');

    $title       = trim($_POST['title']       ?? $body['title']       ?? '');
    $description = trim($_POST['description'] ?? $body['description'] ?? '');
    $targetId    = isset($_POST['target_user_id'])
        ? (int)$_POST['target_user_id']
        : (isset($body['target_user_id']) ? (int)$body['target_user_id'] : null);

    if (empty($title)) {
        jsonResponse(['status' => 'error', 'message' => 'Document title is required.'], 400);
    }

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
        $stmt->execute([$auth['id'], $targetId ?: null, $title, $description ?: null, $filePath]);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }

    // Notify intern(s)
    if ($targetId) {
        notify($db, $targetId, 'document_request', 'Document Requested',
            "Admin has requested you submit: {$title}");
    } else {
        try {
            $interns = $db->query("SELECT id FROM users WHERE role = 'intern' AND is_active = 1")->fetchAll();
            $ids     = array_column($interns, 'id');
            if ($ids) {
                notify($db, $ids, 'document_request', 'Document Requested',
                    "Admin has requested: {$title}");
            }
        } catch (PDOException $e) {
            error_log('Notification broadcast failed: ' . $e->getMessage());
        }
    }

    jsonResponse(['status' => 'success', 'success' => true, 'file_path' => $filePath, 'message' => 'Assignment sent.']);
}

/* ════════════════════════════════════════════════════════════
   PUT – admin approves/rejects a submission
════════════════════════════════════════════════════════════ */
if ($method === 'PUT') {
    requireRole('admin', 'supervisor');

    $id     = (int)($body['id']     ?? 0);
    $status = trim($body['status'] ?? '');

    if (!$id)     { jsonResponse(['status' => 'error', 'message' => 'Document ID is required.'], 400); }
    if (!in_array($status, ['approved', 'rejected', 'pending'], true)) {
        jsonResponse(['status' => 'error', 'message' => 'Invalid status. Must be: approved, rejected, or pending.'], 400);
    }

    try {
        $db->prepare(
            'UPDATE documents SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
        )->execute([$status, $auth['id'], $id]);

        jsonResponse(['status' => 'success', 'success' => true, 'message' => "Document marked as {$status}."]); 
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

/* ════════════════════════════════════════════════════════════
   DELETE – intern deletes own pending submission
════════════════════════════════════════════════════════════ */
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { jsonResponse(['status' => 'error', 'message' => 'Document ID is required.'], 400); }

    try {
        if ($auth['role'] === 'intern') {
            $db->prepare('DELETE FROM documents WHERE id = ? AND user_id = ?')
               ->execute([$id, $auth['id']]);
        } else {
            requireRole('admin', 'supervisor');
            $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
        }
        jsonResponse(['status' => 'success', 'success' => true, 'message' => 'Document deleted.']);
    } catch (PDOException $e) {
        jsonResponse(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['status' => 'error', 'message' => 'Method not allowed.'], 405);
