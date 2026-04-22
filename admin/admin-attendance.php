<?php
/**
 * Admin Attendance
 * Camera-based face registration and attendance logging.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
// Settings table is pre-created in Supabase schema
try {
    $pdo->prepare("INSERT INTO settings (\"key\", \"value\") VALUES (:k, :v) ON CONFLICT (\"key\") DO NOTHING")
        ->execute([':k' => 'attendance_module_enabled', ':v' => '1']);
} catch (PDOException $e) { /* ignore if already exists */ }

$attendanceEnabled = attendanceModuleEnabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'toggle_attendance_module') {
    validateCsrf();
    $newValue = ($_POST['attendance_module_enabled'] ?? '0') === '1' ? '1' : '0';

    setSettingValue('attendance_module_enabled', $newValue);
    auditLog('attendance_module_toggled', 'settings', null, ['enabled' => $attendanceEnabled], ['enabled' => $newValue === '1']);

    setFlash('success', $newValue === '1'
        ? 'Attendance module has been enabled.'
        : 'Attendance module has been disabled. Attendance capture and marking are now blocked.');
    redirect(APP_URL . '/admin/admin-attendance.php');
}

function ensureAttendanceTables(PDO $pdo): void
{
    // Tables are pre-created in Supabase schema — no DDL needed at runtime
}

function attendanceJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function hasValidAjaxCsrf(): bool
{
    return !empty($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

function saveAttendanceImage(?string $photoData, int $studentId): ?string
{
    if (empty($photoData) || !str_contains($photoData, ';base64,')) {
        return null;
    }

    [$meta, $raw] = explode(';base64,', $photoData, 2);
    if (!str_starts_with($meta, 'data:image/')) {
        return null;
    }

    $binary = base64_decode($raw, true);
    if ($binary === false) {
        return null;
    }

    $uploadDir = __DIR__ . '/../uploads/attendance-faces';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = sprintf('student-%d-%s.jpg', $studentId, date('YmdHis'));
    $absolutePath = $uploadDir . '/' . $filename;

    if (file_put_contents($absolutePath, $binary) === false) {
        return null;
    }

    return 'uploads/attendance-faces/' . $filename;
}

ensureAttendanceTables($pdo);

$ajax = $_GET['ajax'] ?? '';
if ($ajax !== '') {
    if (!$attendanceEnabled) {
        attendanceJson([
            'status' => 'error',
            'message' => 'Attendance module is currently disabled by admin.',
        ], 423);
    }

    if ($ajax === 'profiles' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query(
            "SELECT p.student_id, p.face_descriptor, p.face_image_path, p.updated_at,
                    s.full_name,
                    sec.name AS section_name
             FROM student_face_profiles p
             INNER JOIN students s ON s.id = p.student_id
             LEFT JOIN sections sec ON sec.id = s.section_id
             ORDER BY s.full_name"
        );

        $profiles = [];
        foreach ($stmt->fetchAll() as $row) {
            $descriptor = json_decode($row['face_descriptor'], true);
            if (!is_array($descriptor) || count($descriptor) !== 128) {
                continue;
            }

            $profiles[] = [
                'student_id' => (int)$row['student_id'],
                'full_name' => $row['full_name'],
                'section_name' => $row['section_name'],
                'updated_at' => $row['updated_at'],
                'face_image_path' => $row['face_image_path'],
                'descriptor' => array_map(static fn($v) => (float)$v, $descriptor),
            ];
        }

        attendanceJson(['status' => 'success', 'profiles' => $profiles]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        attendanceJson(['status' => 'error', 'message' => 'Method not allowed.'], 405);
    }

    if (!hasValidAjaxCsrf()) {
        attendanceJson(['status' => 'error', 'message' => 'Invalid CSRF token.'], 403);
    }

    if ($ajax === 'register') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $descriptorRaw = trim($_POST['descriptor'] ?? '');
        $photoData = $_POST['photo_data'] ?? '';

        if ($studentId < 1 || $descriptorRaw === '') {
            attendanceJson(['status' => 'error', 'message' => 'Student and descriptor are required.'], 422);
        }

        $descriptor = json_decode($descriptorRaw, true);
        if (!is_array($descriptor) || count($descriptor) !== 128) {
            attendanceJson(['status' => 'error', 'message' => 'Invalid face descriptor.'], 422);
        }

        $normalized = [];
        foreach ($descriptor as $value) {
            if (!is_numeric($value)) {
                attendanceJson(['status' => 'error', 'message' => 'Descriptor contains invalid values.'], 422);
            }
            $normalized[] = (float)$value;
        }

        $stmt = $pdo->prepare("SELECT id, full_name FROM students WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $studentId]);
        $student = $stmt->fetch();
        if (!$student) {
            attendanceJson(['status' => 'error', 'message' => 'Student not found.'], 404);
        }

        $imagePath = saveAttendanceImage($photoData, $studentId);

        $stmt = $pdo->prepare(
            "INSERT INTO student_face_profiles (student_id, face_descriptor, face_image_path, created_at, updated_at)
             VALUES (:student_id, :face_descriptor, :face_image_path, NOW(), NOW())
             ON CONFLICT (student_id) DO UPDATE SET
                face_descriptor = EXCLUDED.face_descriptor,
                face_image_path = EXCLUDED.face_image_path,
                updated_at = NOW()"
        );
        $stmt->execute([
            ':student_id' => $studentId,
            ':face_descriptor' => json_encode($normalized),
            ':face_image_path' => $imagePath,
        ]);

        auditLog('register_face_profile', 'student_face_profiles', $studentId, null, [
            'student_id' => $studentId,
            'has_image' => !empty($imagePath),
        ]);

        attendanceJson([
            'status' => 'success',
            'message' => 'Face profile saved successfully.',
            'profile' => [
                'student_id' => (int)$student['id'],
                'full_name' => $student['full_name'],
                'face_image_path' => $imagePath,
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    if ($ajax === 'mark') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $confidenceValue = $_POST['confidence'] ?? null;

        if ($studentId < 1) {
            attendanceJson(['status' => 'error', 'message' => 'Student is required.'], 422);
        }

        $confidence = null;
        if ($confidenceValue !== null && $confidenceValue !== '') {
            $confidence = max(0, min(1, (float)$confidenceValue));
        }

        $stmt = $pdo->prepare(
            "SELECT s.id, s.full_name, sec.name AS section_name
             FROM students s
             LEFT JOIN sections sec ON sec.id = s.section_id
             WHERE s.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $studentId]);
        $student = $stmt->fetch();

        if (!$student) {
            attendanceJson(['status' => 'error', 'message' => 'Student not found.'], 404);
        }

        $existingStmt = $pdo->prepare(
            "SELECT id FROM attendance_logs
             WHERE student_id = :student_id AND attendance_date = CURRENT_DATE
             LIMIT 1"
        );
        $existingStmt->execute([':student_id' => $studentId]);
        $existing = $existingStmt->fetch();

        if ($existing) {
            $updateStmt = $pdo->prepare(
                "UPDATE attendance_logs
                 SET attendance_status = 'present',
                     method = 'face',
                     confidence = :confidence,
                     marked_by = :marked_by,
                     marked_at = NOW()
                 WHERE id = :id"
            );
            $updateStmt->execute([
                ':confidence' => $confidence,
                ':marked_by' => $_SESSION['user_id'] ?? null,
                ':id' => $existing['id'],
            ]);
            $attendanceId = (int)$existing['id'];
            $message = 'Attendance refreshed for today.';
        } else {
            $insertStmt = $pdo->prepare(
                "INSERT INTO attendance_logs (
                    student_id,
                    attendance_date,
                    attendance_status,
                    method,
                    confidence,
                    marked_by,
                    marked_at
                 ) VALUES (
                    :student_id,
                    CURRENT_DATE,
                    'present',
                    'face',
                    :confidence,
                    :marked_by,
                    NOW()
                 ) RETURNING id"
            );
            $insertStmt->execute([
                ':student_id' => $studentId,
                ':confidence' => $confidence,
                ':marked_by' => $_SESSION['user_id'] ?? null,
            ]);
            $attendanceId = (int)$insertStmt->fetchColumn();
            $message = 'Attendance marked for today.';
        }

        auditLog('mark_attendance_face', 'attendance_logs', $attendanceId, null, [
            'student_id' => $studentId,
            'attendance_date' => date('Y-m-d'),
            'confidence' => $confidence,
        ]);

        attendanceJson([
            'status' => 'success',
            'message' => $message,
            'attendance' => [
                'id' => $attendanceId,
                'student_id' => (int)$student['id'],
                'full_name' => $student['full_name'],
                'section_name' => $student['section_name'],
                'attendance_status' => 'present',
                'method' => 'face',
                'confidence' => $confidence,
                'marked_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }

    attendanceJson(['status' => 'error', 'message' => 'Unknown request.'], 404);
}

$students = $pdo->query(
    "SELECT s.id, s.full_name, s.grade_level, sec.name AS section_name
     FROM students s
     LEFT JOIN sections sec ON sec.id = s.section_id
     ORDER BY s.full_name"
)->fetchAll();

$faceProfiles = $pdo->query(
    "SELECT p.student_id, p.face_image_path, p.updated_at,
            s.full_name,
            sec.name AS section_name
     FROM student_face_profiles p
     INNER JOIN students s ON s.id = p.student_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     ORDER BY p.updated_at DESC"
)->fetchAll();

$todayAttendance = $pdo->query(
    "SELECT a.id, a.student_id, a.attendance_status, a.method, a.confidence, a.marked_at,
            s.full_name,
            sec.name AS section_name
     FROM attendance_logs a
     INNER JOIN students s ON s.id = a.student_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     WHERE a.attendance_date = CURRENT_DATE
     ORDER BY a.marked_at DESC"
)->fetchAll();

$pageTitle = 'Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.camera-stage {
    position: relative;
    width: 100%;
    min-height: 280px;
    border: 1px dashed var(--border-color);
    border-radius: 12px;
    background: var(--body-bg);
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.camera-stage video {
    width: 100%;
    max-height: 420px;
    object-fit: cover;
    border-radius: 10px;
    background: #0f172a;
}
.camera-stage.preview-flipped video,
.camera-stage.preview-flipped .camera-overlay {
    transform: scaleX(-1);
}
.camera-overlay {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
}
.camera-placeholder {
    color: var(--text-muted);
    text-align: center;
    font-size: 0.86rem;
    padding: 16px;
}
.capture-preview {
    width: 120px;
    height: 120px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    object-fit: cover;
    background: var(--body-bg);
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    font-weight: 600;
    border-radius: 100px;
    padding: 6px 12px;
}
.status-pill.ready { background: var(--info-light); color: var(--info-dark); }
.status-pill.success { background: var(--success-light); color: var(--success-dark); }
.status-pill.error { background: var(--danger-light); color: var(--danger-dark); }
.status-pill.warning { background: var(--warning-light); color: var(--warning-dark); }
.log-list { max-height: 210px; overflow-y: auto; }
.log-list .list-group-item { font-size: 0.82rem; padding-top: 10px; padding-bottom: 10px; }
@media (max-width: 767px) {
    .camera-stage { min-height: 220px; }
    .camera-stage video { max-height: 320px; }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-camera-video-fill me-2"></i>Face Attendance</h4>
        <p class="text-muted mb-0" style="font-size:0.88rem;">Use camera capture to register face profiles and mark daily attendance.</p>
    </div>
    <div class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
        <?= count($todayAttendance) ?> marked today
    </div>
</div>

<div class="card mb-4">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="fw-semibold mb-1"><i class="bi bi-toggles2 me-2"></i>Attendance Module Status</div>
            <div class="text-muted small">
                Current state:
                <?php if ($attendanceEnabled): ?>
                    <span class="badge badge-status-active ms-1">Enabled</span>
                <?php else: ?>
                    <span class="badge badge-status-inactive ms-1">Disabled</span>
                <?php endif; ?>
            </div>
        </div>
        <form method="POST" class="d-flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="form_action" value="toggle_attendance_module">
            <?php if ($attendanceEnabled): ?>
                <button type="submit" name="attendance_module_enabled" value="0" class="btn btn-outline-danger">
                    <i class="bi bi-pause-circle me-1"></i>Disable Module
                </button>
            <?php else: ?>
                <button type="submit" name="attendance_module_enabled" value="1" class="btn btn-success">
                    <i class="bi bi-play-circle me-1"></i>Enable Module
                </button>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <span><i class="bi bi-camera"></i>Attendance Controls</span>
    </div>
    <div class="card-body">
        <?php if (!$attendanceEnabled): ?>
            <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                <div>
                    Attendance module is disabled. Face registration and scanner actions are currently turned off.
                    Enable the module above to resume attendance operations.
                </div>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-3" id="attendanceTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="register-tab" data-bs-toggle="tab" data-bs-target="#register-tab-pane" type="button" role="tab" aria-controls="register-tab-pane" aria-selected="true">Face Registration</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="scanner-tab" data-bs-toggle="tab" data-bs-target="#scanner-tab-pane" type="button" role="tab" aria-controls="scanner-tab-pane" aria-selected="false">Take Attendance</button>
            </li>
        </ul>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="mirrorFixToggle" checked>
            <label class="form-check-label small text-muted" for="mirrorFixToggle">Fix inverted camera preview</label>
        </div>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="register-tab-pane" role="tabpanel" aria-labelledby="register-tab" tabindex="0">
                <div class="row g-3">
                    <div class="col-lg-4">
                        <label class="form-label">Student <span class="text-danger">*</span></label>
                        <select class="form-select" id="registerStudentId">
                            <option value="">Select student...</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int)$student['id'] ?>">
                                    <?= e($student['full_name']) ?><?= !empty($student['section_name']) ? ' - ' . e($student['section_name']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label class="form-label mt-3">Preferred Camera</label>
                        <select class="form-select" id="registerFacingMode">
                            <option value="environment">Back camera (mobile)</option>
                            <option value="user">Front camera</option>
                        </select>

                        <label class="form-label mt-3">Webcam Device (PC)</label>
                        <select class="form-select" id="registerCameraDevice">
                            <option value="">Auto select camera</option>
                        </select>

                        <div class="d-grid gap-2 mt-3">
                            <button type="button" class="btn btn-outline-primary" id="startRegisterCameraBtn" <?= !$attendanceEnabled ? 'disabled' : '' ?>>
                                <i class="bi bi-camera-video"></i> Start Camera
                            </button>
                            <button type="button" class="btn btn-primary" id="captureRegisterBtn" <?= !$attendanceEnabled ? 'disabled' : '' ?>>
                                <i class="bi bi-camera-fill"></i> Capture and Save Face
                            </button>
                        </div>

                        <div class="mt-3">
                            <span id="registerStatus" class="status-pill ready"><i class="bi bi-info-circle"></i>Ready to register</span>
                        </div>

                        <div class="mt-3 d-flex align-items-center gap-2">
                            <img id="capturePreview" class="capture-preview" alt="Captured preview" style="display:none;">
                            <small class="text-muted">Captured image preview</small>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="camera-stage">
                            <video id="registerVideo" autoplay muted playsinline></video>
                            <canvas id="registerCanvas" style="display:none;"></canvas>
                            <div class="camera-placeholder" id="registerCameraPlaceholder">
                                <i class="bi bi-camera-video-off d-block mb-1" style="font-size:1.2rem;"></i>
                                Camera preview appears here
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="scanner-tab-pane" role="tabpanel" aria-labelledby="scanner-tab" tabindex="0">
                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="d-flex gap-2 align-items-center flex-wrap mb-2">
                            <select class="form-select form-select-sm" id="scanFacingMode" style="max-width:220px;">
                                <option value="environment">Back camera (mobile)</option>
                                <option value="user">Front camera</option>
                            </select>
                            <select class="form-select form-select-sm" id="scanCameraDevice" style="max-width:260px;">
                                <option value="">Auto select webcam (PC)</option>
                            </select>
                            <button type="button" class="btn btn-primary btn-sm" id="startScanBtn" <?= !$attendanceEnabled ? 'disabled' : '' ?>>
                                <i class="bi bi-play-fill"></i> Start Scanner
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="stopScanBtn" <?= !$attendanceEnabled ? 'disabled' : '' ?>>
                                <i class="bi bi-stop-fill"></i> Stop
                            </button>
                            <span id="scanStatus" class="status-pill ready"><i class="bi bi-shield-check"></i>Scanner idle</span>
                        </div>

                        <div class="camera-stage">
                            <video id="scanVideo" autoplay muted playsinline></video>
                            <canvas id="scanCanvas" class="camera-overlay"></canvas>
                            <div class="camera-placeholder" id="scanCameraPlaceholder">
                                <i class="bi bi-camera-video-off d-block mb-1" style="font-size:1.2rem;"></i>
                                Start scanner to detect and mark attendance
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card h-100">
                            <div class="card-header py-2"><span><i class="bi bi-activity"></i>Recognition Log</span></div>
                            <div class="card-body p-0">
                                <ul class="list-group list-group-flush log-list" id="recognitionLog">
                                    <li class="list-group-item text-muted">No activity yet.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-person-bounding-box"></i>Registered Face Profiles</span>
                <span class="badge bg-primary rounded-pill"><?= count($faceProfiles) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Student</th><th>Section</th><th>Last Updated</th></tr></thead>
                        <tbody id="faceProfilesBody">
                        <?php if (empty($faceProfiles)): ?>
                            <tr><td colspan="3" class="text-center text-muted py-3">No registered face profiles yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($faceProfiles as $profile): ?>
                                <tr data-face-student-id="<?= (int)$profile['student_id'] ?>">
                                    <td class="fw-semibold"><?= e($profile['full_name']) ?></td>
                                    <td><?= e($profile['section_name'] ?? 'N/A') ?></td>
                                    <td><?= e(date('M d, Y h:i A', strtotime($profile['updated_at']))) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">
                <span><i class="bi bi-check2-square"></i>Today's Attendance</span>
                <span class="badge bg-success rounded-pill" id="todayAttendanceCount"><?= count($todayAttendance) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead><tr><th>Student</th><th>Section</th><th>Marked Time</th><th>Confidence</th></tr></thead>
                        <tbody id="todayAttendanceBody">
                        <?php if (empty($todayAttendance)): ?>
                            <tr id="noAttendanceRow"><td colspan="4" class="text-center text-muted py-3">No attendance records yet today.</td></tr>
                        <?php else: ?>
                            <?php foreach ($todayAttendance as $record): ?>
                                <tr data-attendance-student-id="<?= (int)$record['student_id'] ?>">
                                    <td class="fw-semibold"><?= e($record['full_name']) ?></td>
                                    <td><?= e($record['section_name'] ?? 'N/A') ?></td>
                                    <td><?= e(date('h:i:s A', strtotime($record['marked_at']))) ?></td>
                                    <td><?= $record['confidence'] !== null ? e(number_format((float)$record['confidence'] * 100, 2)) . '%' : 'N/A' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= APP_URL ?>/assets/js/face-api.min.js"></script>
<script>
(() => {
    const apiUrl = <?= json_encode(APP_URL . '/admin/admin-attendance.php') ?>;
    const csrfToken = <?= json_encode(csrfToken()) ?>;
    const modelsUrl = <?= json_encode(APP_URL . '/models') ?>;
    const attendanceEnabled = <?= $attendanceEnabled ? 'true' : 'false' ?>;

    const registerVideo = document.getElementById('registerVideo');
    const registerCanvas = document.getElementById('registerCanvas');
    const registerStatus = document.getElementById('registerStatus');
    const registerPlaceholder = document.getElementById('registerCameraPlaceholder');
    const capturePreview = document.getElementById('capturePreview');
    const registerCameraDevice = document.getElementById('registerCameraDevice');
    const mirrorFixToggle = document.getElementById('mirrorFixToggle');

    const scanVideo = document.getElementById('scanVideo');
    const scanCanvas = document.getElementById('scanCanvas');
    const scanStatus = document.getElementById('scanStatus');
    const scanPlaceholder = document.getElementById('scanCameraPlaceholder');
    const scanCameraDevice = document.getElementById('scanCameraDevice');
    const registerStage = registerVideo.closest('.camera-stage');
    const scanStage = scanVideo.closest('.camera-stage');

    const recognitionLog = document.getElementById('recognitionLog');
    const todayAttendanceBody = document.getElementById('todayAttendanceBody');
    const todayAttendanceCount = document.getElementById('todayAttendanceCount');
    const faceProfilesBody = document.getElementById('faceProfilesBody');

    const noAttendanceRowId = 'noAttendanceRow';
    const scanCooldownMs = 25000;

    let registerStream = null;
    let scanStream = null;
    let modelsLoaded = false;
    let scanTimer = null;
    let scanBusy = false;

    function applyPreviewOrientationFix() {
        const shouldFlipPreview = mirrorFixToggle ? mirrorFixToggle.checked : true;
        if (registerStage) registerStage.classList.toggle('preview-flipped', shouldFlipPreview);
        if (scanStage) scanStage.classList.toggle('preview-flipped', shouldFlipPreview);
    }
    let faceMatcher = null;
    let profileMap = new Map();
    const recentMarks = new Map();

    function setPill(el, message, type, icon) {
        el.className = `status-pill ${type}`;
        el.innerHTML = `<i class="bi ${icon}"></i>${message}`;
    }

    function addRecognitionLog(message, level = 'secondary') {
        if (!recognitionLog) return;
        const oldPlaceholder = recognitionLog.querySelector('.text-muted');
        if (oldPlaceholder) oldPlaceholder.remove();

        const item = document.createElement('li');
        item.className = 'list-group-item d-flex justify-content-between align-items-start';

        const text = document.createElement('span');
        text.textContent = message;
        text.className = level === 'danger' ? 'text-danger' : (level === 'success' ? 'text-success' : 'text-muted');

        const ts = document.createElement('small');
        ts.className = 'text-muted';
        ts.textContent = new Date().toLocaleTimeString();

        item.appendChild(text);
        item.appendChild(ts);
        recognitionLog.prepend(item);

        while (recognitionLog.children.length > 12) {
            recognitionLog.removeChild(recognitionLog.lastChild);
        }
    }

    function stopStream(stream) {
        if (!stream) return;
        stream.getTracks().forEach(track => track.stop());
    }

    function populateCameraSelect(selectEl, devices, selectedDeviceId = '') {
        if (!selectEl) return;
        selectEl.innerHTML = '';

        const autoOption = document.createElement('option');
        autoOption.value = '';
        autoOption.textContent = selectEl.id === 'registerCameraDevice' ? 'Auto select camera' : 'Auto select webcam (PC)';
        selectEl.appendChild(autoOption);

        devices.forEach((device, index) => {
            const option = document.createElement('option');
            option.value = device.deviceId;
            option.textContent = device.label || `Camera ${index + 1}`;
            selectEl.appendChild(option);
        });

        selectEl.value = selectedDeviceId || '';
    }

    async function refreshCameraDevices(selectedRegister = '', selectedScan = '') {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoInputs = devices.filter(device => device.kind === 'videoinput');
        populateCameraSelect(registerCameraDevice, videoInputs, selectedRegister);
        populateCameraSelect(scanCameraDevice, videoInputs, selectedScan);
    }

    async function startCamera(videoEl, options) {
        const { target, facingMode, deviceId } = options;

        const preferredConstraint = deviceId
            ? { video: { deviceId: { exact: deviceId }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false }
            : { video: { facingMode: { ideal: facingMode }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false };

        const fallbackConstraint = { video: { width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false };

        let stream = null;
        let lastError = null;

        try {
            stream = await navigator.mediaDevices.getUserMedia(preferredConstraint);
        } catch (error) {
            lastError = error;
            try {
                stream = await navigator.mediaDevices.getUserMedia(fallbackConstraint);
            } catch (fallbackError) {
                lastError = fallbackError;
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                } catch (finalError) {
                    lastError = finalError;
                }
            }
        }

        if (!stream) {
            console.error(lastError);
            return false;
        }

        if (target === 'register') {
            stopStream(registerStream);
            registerStream = stream;
            registerPlaceholder.style.display = 'none';
        } else {
            stopStream(scanStream);
            scanStream = stream;
            scanPlaceholder.style.display = 'none';
        }

        videoEl.srcObject = stream;
        await videoEl.play();

        const activeDeviceId = stream.getVideoTracks()[0]?.getSettings?.().deviceId || '';
        await refreshCameraDevices(
            target === 'register' ? activeDeviceId : registerCameraDevice.value,
            target === 'scan' ? activeDeviceId : scanCameraDevice.value
        );

        return true;
    }

    async function ensureModelsLoaded() {
        if (modelsLoaded) return;
        if (typeof faceapi === 'undefined') throw new Error('face-api.js is not available.');

        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(modelsUrl),
            faceapi.nets.faceLandmark68Net.loadFromUri(modelsUrl),
            faceapi.nets.faceRecognitionNet.loadFromUri(modelsUrl)
        ]);
        modelsLoaded = true;
    }

    async function postAction(action, payload) {
        if (!attendanceEnabled) {
            throw new Error('Attendance module is disabled by admin.');
        }

        const body = new URLSearchParams({ csrf_token: csrfToken, ...payload });
        const response = await fetch(`${apiUrl}?ajax=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        });

        const data = await response.json().catch(() => ({ status: 'error', message: 'Unexpected server response.' }));
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Request failed.');
        return data;
    }

    async function fetchProfiles() {
        const response = await fetch(`${apiUrl}?ajax=profiles`, { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Failed to load face profiles.');
        return data.profiles || [];
    }

    function buildMatcher(profiles) {
        const labeled = [];
        profileMap = new Map();

        profiles.forEach(profile => {
            if (!Array.isArray(profile.descriptor) || profile.descriptor.length !== 128) return;
            const descriptor = new Float32Array(profile.descriptor.map(Number));
            labeled.push(new faceapi.LabeledFaceDescriptors(String(profile.student_id), [descriptor]));
            profileMap.set(String(profile.student_id), profile);
        });

        if (!labeled.length) return null;
        return new faceapi.FaceMatcher(labeled, 0.48);
    }

    function upsertAttendanceRow(record) {
        const existingRow = todayAttendanceBody.querySelector(`tr[data-attendance-student-id="${record.student_id}"]`);
        const confidenceText = record.confidence === null || record.confidence === undefined ? 'N/A' : `${(Number(record.confidence) * 100).toFixed(2)}%`;

        if (document.getElementById(noAttendanceRowId)) {
            document.getElementById(noAttendanceRowId).remove();
        }

        let row = existingRow;
        if (!row) {
            row = document.createElement('tr');
            row.dataset.attendanceStudentId = String(record.student_id);
            row.innerHTML = '<td class="fw-semibold"></td><td></td><td></td><td></td>';
            todayAttendanceBody.prepend(row);
        }

        row.cells[0].textContent = record.full_name || 'Unknown';
        row.cells[1].textContent = record.section_name || 'N/A';
        row.cells[2].textContent = new Date(record.marked_at.replace(' ', 'T')).toLocaleTimeString();
        row.cells[3].textContent = confidenceText;

        const count = todayAttendanceBody.querySelectorAll('tr[data-attendance-student-id]').length;
        todayAttendanceCount.textContent = String(count);
    }

    function upsertFaceProfileRow(profile) {
        const existingRow = faceProfilesBody.querySelector(`tr[data-face-student-id="${profile.student_id}"]`);
        let row = existingRow;

        if (!row) {
            const placeholder = faceProfilesBody.querySelector('td.text-center');
            if (placeholder) placeholder.parentElement.remove();

            row = document.createElement('tr');
            row.dataset.faceStudentId = String(profile.student_id);
            row.innerHTML = '<td class="fw-semibold"></td><td></td><td></td>';
            faceProfilesBody.prepend(row);
        }

        row.cells[0].textContent = profile.full_name || 'Unknown';
        row.cells[1].textContent = profile.section_name || 'N/A';
        row.cells[2].textContent = new Date(profile.updated_at.replace(' ', 'T')).toLocaleString();
    }

    async function captureAndRegister() {
        try {
            setPill(registerStatus, 'Preparing face models...', 'ready', 'bi-hourglass-split');
            await ensureModelsLoaded();

            const studentId = document.getElementById('registerStudentId').value;
            if (!studentId) throw new Error('Please select a student first.');

            if (!registerStream) {
                const facingMode = document.getElementById('registerFacingMode').value;
                const deviceId = registerCameraDevice.value;
                const started = await startCamera(registerVideo, { target: 'register', facingMode, deviceId });
                if (!started) throw new Error('Cannot access camera. Check browser camera permission.');
            }

            registerCanvas.width = registerVideo.videoWidth || 640;
            registerCanvas.height = registerVideo.videoHeight || 480;
            const ctx = registerCanvas.getContext('2d');
            ctx.drawImage(registerVideo, 0, 0, registerCanvas.width, registerCanvas.height);

            const detection = await faceapi
                .detectSingleFace(registerCanvas, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptor();

            if (!detection) throw new Error('No face detected. Move closer and try again.');

            const descriptor = Array.from(detection.descriptor);
            const photoData = registerCanvas.toDataURL('image/jpeg', 0.92);

            const data = await postAction('register', {
                student_id: studentId,
                descriptor: JSON.stringify(descriptor),
                photo_data: photoData
            });

            capturePreview.src = photoData;
            capturePreview.style.display = 'block';
            setPill(registerStatus, data.message || 'Face profile saved.', 'success', 'bi-check-circle');
            addRecognitionLog(`Face profile saved: ${data.profile.full_name}`, 'success');
            upsertFaceProfileRow(data.profile);
        } catch (error) {
            console.error(error);
            setPill(registerStatus, error.message || 'Failed to register face.', 'error', 'bi-exclamation-triangle');
            addRecognitionLog(error.message || 'Registration failed.', 'danger');
        }
    }

    async function markAttendance(studentId, distance) {
        const now = Date.now();
        const lastMarked = recentMarks.get(studentId) || 0;
        if (now - lastMarked < scanCooldownMs) return;
        recentMarks.set(studentId, now);

        try {
            const confidence = Math.max(0, Math.min(1, 1 - Number(distance || 0)));
            const data = await postAction('mark', {
                student_id: studentId,
                confidence: confidence.toFixed(5)
            });

            upsertAttendanceRow(data.attendance);
            addRecognitionLog(`Marked present: ${data.attendance.full_name}`, 'success');
            setPill(scanStatus, `Detected ${data.attendance.full_name}`, 'success', 'bi-check2-circle');
        } catch (error) {
            console.error(error);
            addRecognitionLog(error.message || 'Attendance save failed.', 'danger');
            setPill(scanStatus, error.message || 'Attendance save failed.', 'error', 'bi-exclamation-triangle');
        }
    }

    async function scanLoop() {
        if (!faceMatcher || scanBusy || !scanStream) return;
        scanBusy = true;

        try {
            const detections = await faceapi
                .detectAllFaces(scanVideo, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptors();

            const displaySize = { width: scanVideo.videoWidth || 640, height: scanVideo.videoHeight || 480 };
            if (scanCanvas.width !== displaySize.width || scanCanvas.height !== displaySize.height) {
                scanCanvas.width = displaySize.width;
                scanCanvas.height = displaySize.height;
            }

            const resized = faceapi.resizeResults(detections, displaySize);
            const ctx = scanCanvas.getContext('2d');
            ctx.clearRect(0, 0, scanCanvas.width, scanCanvas.height);

            for (const detection of resized) {
                const best = faceMatcher.findBestMatch(detection.descriptor);
                let label = 'Unknown';

                if (best.label !== 'unknown') {
                    const profile = profileMap.get(String(best.label));
                    const name = profile?.full_name || `Student ${best.label}`;
                    label = `${name} ${(1 - best.distance).toFixed(2)}`;
                    await markAttendance(String(best.label), best.distance);
                }

                const drawBox = new faceapi.draw.DrawBox(detection.detection.box, { label });
                drawBox.draw(scanCanvas);
            }
        } catch (error) {
            console.error(error);
        } finally {
            scanBusy = false;
        }
    }

    async function startScanner() {
        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                throw new Error('Camera is not supported by this browser.');
            }

            setPill(scanStatus, 'Loading models and profiles...', 'ready', 'bi-hourglass-split');
            await ensureModelsLoaded();

            const profiles = await fetchProfiles();
            faceMatcher = buildMatcher(profiles);
            if (!faceMatcher) throw new Error('No face profiles found. Register student faces first.');

            const facingMode = document.getElementById('scanFacingMode').value;
            const deviceId = scanCameraDevice.value;
            const started = await startCamera(scanVideo, { target: 'scan', facingMode, deviceId });
            if (!started) throw new Error('Cannot access camera. Check browser camera permission.');

            if (scanTimer) clearInterval(scanTimer);
            scanTimer = setInterval(scanLoop, 1200);
            setPill(scanStatus, 'Scanner running', 'success', 'bi-broadcast-pin');
            addRecognitionLog('Attendance scanner started.', 'secondary');
        } catch (error) {
            console.error(error);
            setPill(scanStatus, error.message || 'Failed to start scanner.', 'error', 'bi-exclamation-triangle');
            addRecognitionLog(error.message || 'Scanner failed to start.', 'danger');
        }
    }

    function stopScanner() {
        if (scanTimer) {
            clearInterval(scanTimer);
            scanTimer = null;
        }

        stopStream(scanStream);
        scanStream = null;
        scanVideo.srcObject = null;

        const ctx = scanCanvas.getContext('2d');
        ctx.clearRect(0, 0, scanCanvas.width, scanCanvas.height);

        scanPlaceholder.style.display = 'block';
        setPill(scanStatus, 'Scanner stopped', 'warning', 'bi-stop-circle');
        addRecognitionLog('Attendance scanner stopped.', 'secondary');
    }

    document.getElementById('startRegisterCameraBtn').addEventListener('click', async () => {
        const facingMode = document.getElementById('registerFacingMode').value;
        const deviceId = registerCameraDevice.value;
        const started = await startCamera(registerVideo, { target: 'register', facingMode, deviceId });
        if (!started) {
            setPill(registerStatus, 'Camera access denied or unavailable.', 'error', 'bi-exclamation-triangle');
            return;
        }
        setPill(registerStatus, 'Camera ready for capture', 'ready', 'bi-camera-video');
    });

    document.getElementById('captureRegisterBtn').addEventListener('click', captureAndRegister);
    document.getElementById('startScanBtn').addEventListener('click', startScanner);
    document.getElementById('stopScanBtn').addEventListener('click', stopScanner);
    if (mirrorFixToggle) {
        mirrorFixToggle.addEventListener('change', applyPreviewOrientationFix);
    }

    applyPreviewOrientationFix();

    if (!attendanceEnabled) {
        setPill(registerStatus, 'Attendance module disabled by admin', 'warning', 'bi-pause-circle');
        setPill(scanStatus, 'Attendance module disabled by admin', 'warning', 'bi-pause-circle');
        addRecognitionLog('Attendance module disabled. Enable the module to register and scan attendance.', 'secondary');
        return;
    }

    refreshCameraDevices().catch(error => {
        console.warn('Unable to list cameras yet:', error);
    });

    window.addEventListener('beforeunload', () => {
        stopStream(registerStream);
        stopStream(scanStream);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
