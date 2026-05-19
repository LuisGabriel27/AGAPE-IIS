<?php
/**
 * One-time maintenance to correct the sample dataset so Preschool has learners.
 *
 * Usage:
 *   php database/rebalance_preschool_students.php
 *   php database/rebalance_preschool_students.php --apply
 */

require_once __DIR__ . '/../includes/db.php';

$apply = in_array('--apply', $argv, true);
$pdo = getDB();

$preschoolRows = [
    ['lrn' => '136000000001', 'birthdate' => '2021-05-14'],
    ['lrn' => '136000000002', 'birthdate' => '2021-08-22'],
    ['lrn' => '136000000003', 'birthdate' => '2021-02-03'],
];

$sectionStmt = $pdo->prepare("SELECT id, name FROM sections WHERE grade_level = :grade ORDER BY id LIMIT 1");
$sectionStmt->execute([':grade' => 'Preschool']);
$preschoolSection = $sectionStmt->fetch();

if (!$preschoolSection) {
    echo "No Preschool section found. Create one first." . PHP_EOL;
    exit(1);
}

$studentStmt = $pdo->prepare("
    SELECT id, first_name, last_name, grade_level, birthdate, section_id
    FROM students
    WHERE lrn = :lrn
    LIMIT 1
");
$students = [];
foreach ($preschoolRows as $row) {
    $studentStmt->execute([':lrn' => $row['lrn']]);
    $student = $studentStmt->fetch();
    if ($student) {
        $student['lrn'] = $row['lrn'];
        $student['target_birthdate'] = $row['birthdate'];
        if (
            $student['grade_level'] !== 'Preschool'
            || $student['birthdate'] !== $row['birthdate']
            || (int)$student['section_id'] !== (int)$preschoolSection['id']
        ) {
            $students[] = $student;
        }
    }
}

echo ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
echo 'target_preschool_section=' . $preschoolSection['name'] . ' (#' . $preschoolSection['id'] . ')' . PHP_EOL;
echo 'students_to_move=' . count($students) . PHP_EOL;

foreach ($students as $student) {
    echo '- ' . $student['first_name'] . ' ' . $student['last_name']
        . ' LRN ' . $student['lrn']
        . ': ' . $student['grade_level']
        . ' -> Preschool, birthdate ' . $student['birthdate']
        . ' -> ' . $student['target_birthdate']
        . PHP_EOL;
}

if (!$apply) {
    echo 'No changes were written. Re-run with --apply to update the database.' . PHP_EOL;
    exit(0);
}

$updateStmt = $pdo->prepare("
    UPDATE students
    SET grade_level = 'Preschool',
        birthdate = :birthdate,
        section_id = :section_id
    WHERE lrn = :lrn
");

$pdo->beginTransaction();
try {
foreach ($preschoolRows as $row) {
    $updateStmt->execute([
        ':birthdate' => $row['birthdate'],
            ':section_id' => (int)$preschoolSection['id'],
            ':lrn' => $row['lrn'],
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo 'preschool_rebalance_completed=1' . PHP_EOL;
