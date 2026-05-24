<?php
/**
 * One-time maintenance:
 * - rename guardian account emails from generic examples to name-based emails;
 * - assign every student to the single section that matches their grade level.
 *
 * Usage:
 *   php database/update_guardian_emails_and_assign_sections.php
 *   php database/update_guardian_emails_and_assign_sections.php --apply
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$apply = in_array('--apply', $argv, true);
$domain = 'agape-aiis.edu.ph';
$pdo = getDB();

function emailSlug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '.', $value);
    $value = trim((string)$value, '.');
    return $value !== '' ? $value : 'guardian';
}

function guardianBaseEmail(array $guardian, string $domain): string
{
    $first = emailSlug((string)($guardian['first_name'] ?? ''));
    $last = emailSlug((string)($guardian['last_name'] ?? ''));

    if ($first === 'guardian' && $last === 'guardian') {
        return 'guardian.' . (int)$guardian['guardian_id'] . '@' . $domain;
    }

    if ($first === 'guardian') {
        return $last . '.guardian@' . $domain;
    }

    if ($last === 'guardian') {
        return $first . '.guardian@' . $domain;
    }

    return $first . '.' . $last . '@' . $domain;
}

$guardianRows = $pdo->query("
    SELECT
        g.id AS guardian_id,
        g.first_name,
        g.middle_name,
        g.last_name,
        u.id AS user_id,
        u.email
    FROM guardians g
    INNER JOIN users u ON u.id = g.user_id
    ORDER BY g.last_name, g.first_name, g.id
")->fetchAll();

$allUserEmails = [];
foreach ($pdo->query("SELECT id, lower(trim(email)) AS email FROM users WHERE email IS NOT NULL AND trim(email) <> ''") as $row) {
    $allUserEmails[(string)$row['email']] = (int)$row['id'];
}

$plannedEmailUpdates = [];
$reservedEmails = $allUserEmails;

foreach ($guardianRows as $guardian) {
    $userId = (int)$guardian['user_id'];
    $currentEmail = strtolower(trim((string)$guardian['email']));
    $baseEmail = guardianBaseEmail($guardian, $domain);
    $candidate = $baseEmail;
    $suffix = 2;

    while (isset($reservedEmails[$candidate]) && $reservedEmails[$candidate] !== $userId) {
        $local = substr($baseEmail, 0, -strlen('@' . $domain));
        $candidate = $local . '.' . $suffix . '@' . $domain;
        $suffix++;
    }

    $reservedEmails[$candidate] = $userId;

    if ($candidate !== $currentEmail) {
        $plannedEmailUpdates[] = [
            'user_id' => $userId,
            'guardian_id' => (int)$guardian['guardian_id'],
            'name' => trim(($guardian['first_name'] ?? '') . ' ' . ($guardian['last_name'] ?? '')),
            'old_email' => $guardian['email'],
            'new_email' => $candidate,
        ];
    }
}

$sectionsByGrade = [];
$ambiguousGrades = [];
foreach ($pdo->query("SELECT id, name, grade_level FROM sections ORDER BY grade_level, id") as $section) {
    $grade = (string)$section['grade_level'];
    $sectionsByGrade[$grade][] = $section;
}

foreach ($sectionsByGrade as $grade => $sections) {
    if (count($sections) !== 1) {
        $ambiguousGrades[$grade] = $sections;
    }
}

if (!empty($ambiguousGrades)) {
    echo "Cannot assign sections because these grade levels do not have exactly one section:" . PHP_EOL;
    foreach ($ambiguousGrades as $grade => $sections) {
        echo "- {$grade}: " . count($sections) . " sections" . PHP_EOL;
    }
    exit(1);
}

$plannedSectionUpdates = [];
$studentStmt = $pdo->query("
    SELECT
        s.id,
        s.first_name,
        s.last_name,
        s.grade_level,
        s.section_id,
        sec.name AS current_section
    FROM students s
    LEFT JOIN sections sec ON sec.id = s.section_id
    ORDER BY s.grade_level, s.last_name, s.first_name, s.id
");

foreach ($studentStmt as $student) {
    $grade = (string)($student['grade_level'] ?? '');
    if ($grade === '' || empty($sectionsByGrade[$grade][0])) {
        continue;
    }

    $target = $sectionsByGrade[$grade][0];
    if ((int)($student['section_id'] ?? 0) !== (int)$target['id']) {
        $plannedSectionUpdates[] = [
            'student_id' => (int)$student['id'],
            'name' => trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')),
            'grade_level' => $grade,
            'old_section' => $student['current_section'] ?? 'Unassigned',
            'new_section_id' => (int)$target['id'],
            'new_section' => $target['name'],
        ];
    }
}

echo ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'guardian_email_updates=' . count($plannedEmailUpdates) . PHP_EOL;
foreach (array_slice($plannedEmailUpdates, 0, 15) as $row) {
    echo "- guardian #{$row['guardian_id']} {$row['name']}: {$row['old_email']} -> {$row['new_email']}" . PHP_EOL;
}
if (count($plannedEmailUpdates) > 15) {
    echo '- ... plus ' . (count($plannedEmailUpdates) - 15) . ' more guardian email update(s)' . PHP_EOL;
}

echo 'student_section_updates=' . count($plannedSectionUpdates) . PHP_EOL;
foreach (array_slice($plannedSectionUpdates, 0, 15) as $row) {
    echo "- student #{$row['student_id']} {$row['name']} ({$row['grade_level']}): {$row['old_section']} -> {$row['new_section']}" . PHP_EOL;
}
if (count($plannedSectionUpdates) > 15) {
    echo '- ... plus ' . (count($plannedSectionUpdates) - 15) . ' more student section update(s)' . PHP_EOL;
}

if (!$apply) {
    echo 'No changes were written. Re-run with --apply to update the database.' . PHP_EOL;
    exit(0);
}

$updateEmail = $pdo->prepare("UPDATE users SET email = :email WHERE id = :id");
$updateSection = $pdo->prepare("UPDATE students SET section_id = :section_id WHERE id = :id");

$pdo->beginTransaction();
try {
    foreach ($plannedEmailUpdates as $row) {
        $updateEmail->execute([
            ':email' => $row['new_email'],
            ':id' => $row['user_id'],
        ]);
    }

    foreach ($plannedSectionUpdates as $row) {
        $updateSection->execute([
            ':section_id' => $row['new_section_id'],
            ':id' => $row['student_id'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo 'database_update_completed=1' . PHP_EOL;
