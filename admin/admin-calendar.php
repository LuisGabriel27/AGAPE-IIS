<?php
/**
 * Admin Calendar — Full visual calendar with CRUD for school events
 * Only admin can add, edit, delete events.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole('admin');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo    = getDB();
$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$errors = [];

// ── Handle Delete ───────────────────────────────────────
if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $pdo->prepare("DELETE FROM calendar_events WHERE id = :id")->execute([':id' => $id]);
    auditLog('delete_event', 'calendar_events', $id);
    setFlash('success', 'Event deleted.');
    redirect(APP_URL . '/admin/admin-calendar.php');
}

// ── Handle Create / Edit ────────────────────────────────
if (in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $title      = trim($_POST['title'] ?? '');
    $dateStart  = trim($_POST['date_start'] ?? '');
    $dateEnd    = trim($_POST['date_end'] ?? '');
    $type       = trim($_POST['type'] ?? 'event');
    $description = trim($_POST['description'] ?? '');

    if (empty($title)) $errors[] = 'Title is required.';
    if (empty($dateStart) || empty($dateEnd)) $errors[] = 'Date range is required.';

    if (empty($errors)) {
        if ($action === 'create') {
            $stmt = $pdo->prepare("INSERT INTO calendar_events (title, date_start, date_end, type, description, created_by) VALUES (:t, :ds, :de, :tp, :desc, :cb)");
            $stmt->execute([':t'=>$title,':ds'=>$dateStart,':de'=>$dateEnd,':tp'=>$type,':desc'=>$description,':cb'=>$_SESSION['user_id']]);
            auditLog('create_event', 'calendar_events', (int)$pdo->lastInsertId());
            setFlash('success', 'Event created successfully.');
        } else {
            $stmt = $pdo->prepare("UPDATE calendar_events SET title=:t, date_start=:ds, date_end=:de, type=:tp, description=:desc WHERE id=:id");
            $stmt->execute([':t'=>$title,':ds'=>$dateStart,':de'=>$dateEnd,':tp'=>$type,':desc'=>$description,':id'=>$id]);
            auditLog('update_event', 'calendar_events', $id);
            setFlash('success', 'Event updated successfully.');
        }
        redirect(APP_URL . '/admin/admin-calendar.php');
    }
}

$editEvent = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM calendar_events WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $editEvent = $stmt->fetch();
}

// Fetch all events for calendar
$calendarEvents = $pdo->query("SELECT * FROM calendar_events ORDER BY date_start")->fetchAll();
$isAdmin = true;
$calendarFullPage = true;

$pageTitle = 'Academic Year Calendar';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (in_array($action, ['create', 'edit'])): ?>
<!-- Create / Edit Form -->
<div class="card mb-4">
    <div class="card-header">
        <span><i class="bi bi-calendar-plus-fill"></i><?= $action === 'create' ? 'Add New Event' : 'Edit Event' ?></span>
    </div>
    <div class="card-body">
        <form method="POST" id="calendar-form">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Event Title <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title" value="<?= e($editEvent['title'] ?? '') ?>" required placeholder="e.g. Final Examinations">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Event Type</label>
                    <select class="form-select" name="type">
                        <?php foreach (['event'=>'Academic Event','holiday'=>'Holiday','exam'=>'Exam','other'=>'Other'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($editEvent['type'] ?? 'event') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Start Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="date_start" value="<?= e($editEvent['date_start'] ?? '') ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">End Date <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" name="date_end" value="<?= e($editEvent['date_end'] ?? '') ?>" required>
                </div>
                <div class="col-md-9 mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="2" placeholder="Optional description..."><?= e($editEvent['description'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Event</button>
                <a href="<?= APP_URL ?>/admin/admin-calendar.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Visual Calendar Widget -->
<?php require __DIR__ . '/../includes/calendar-widget.php'; ?>

<!-- Events Management Table (Admin Only) -->
<div class="card mt-4">
    <div class="card-header">
        <span><i class="bi bi-list-ul"></i>All Events</span>
        <span class="badge bg-primary rounded-pill"><?= count($calendarEvents) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="calendar-table">
                <thead>
                    <tr><th>Title</th><th>Date Range</th><th>Type</th><th>Description</th><th class="text-center">Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($calendarEvents)): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="bi bi-calendar-x d-block"></i><p>No events found. Click "Add Event" to create one.</p></div></td></tr>
                    <?php else: foreach ($calendarEvents as $ev):
                        $typeColorMap = [
                            'event'   => 'badge-status-enrolled',
                            'holiday' => 'badge-status-rejected',
                            'exam'    => 'badge-status-pending',
                            'other'   => 'badge-status-active',
                        ];
                        $badge = $typeColorMap[$ev['type']] ?? 'badge-status-inactive';
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= e($ev['title']) ?></td>
                        <td>
                            <small>
                                <i class="bi bi-calendar3 me-1 text-muted"></i>
                                <?= e(date('M d, Y', strtotime($ev['date_start']))) ?>
                                <?php if ($ev['date_start'] !== $ev['date_end']): ?>
                                    — <?= e(date('M d, Y', strtotime($ev['date_end']))) ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td><span class="badge <?= $badge ?>"><?= e(ucfirst($ev['type'])) ?></span></td>
                        <td><small class="text-muted"><?= e(mb_substr($ev['description'] ?? '', 0, 50)) ?><?= strlen($ev['description'] ?? '') > 50 ? '…' : '' ?></small></td>
                        <td class="text-center">
                            <div class="d-flex gap-1 justify-content-center">
                                <a href="?action=edit&id=<?= $ev['id'] ?>" class="btn btn-sm btn-outline-primary btn-icon" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="?action=delete&id=<?= $ev['id'] ?>" class="d-inline" onsubmit="return confirm('Delete this event?')">
                                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                    <button class="btn btn-sm btn-outline-danger btn-icon" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
