<?php
/**
 * Read-only verification for sample student/guardian import and teacher backfill.
 */

require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();

$checks = [
    'sample_students' => "
        SELECT COUNT(*)
        FROM students
        WHERE lrn BETWEEN '136000000001' AND '136000000050'
    ",
    'sample_guardian_users' => "
        SELECT COUNT(*)
        FROM users
        WHERE email LIKE 'guardian%' AND email LIKE '%@example.com'
    ",
    'sample_guardian_profiles' => "
        SELECT COUNT(*)
        FROM guardians g
        INNER JOIN users u ON u.id = g.user_id
        WHERE u.email LIKE 'guardian%' AND u.email LIKE '%@example.com'
    ",
    'duplicate_sample_lrns' => "
        SELECT COUNT(*)
        FROM (
            SELECT lrn
            FROM students
            WHERE lrn BETWEEN '136000000001' AND '136000000050'
            GROUP BY lrn
            HAVING COUNT(*) > 1
        ) x
    ",
    'teachers_missing_required_new_fields' => "
        SELECT COUNT(*)
        FROM teachers
        WHERE employee_number IS NULL OR btrim(employee_number) = ''
           OR prc_license_no IS NULL OR btrim(prc_license_no) = ''
           OR gender IS NULL
           OR birthdate IS NULL
           OR civil_status IS NULL
           OR address IS NULL OR btrim(address) = ''
           OR position_title IS NULL OR btrim(position_title) = ''
           OR employment_status IS NULL OR btrim(employment_status) = ''
           OR date_hired IS NULL
           OR prc_license_expiry IS NULL
           OR specialization IS NULL OR btrim(specialization) = ''
           OR emergency_contact_name IS NULL OR btrim(emergency_contact_name) = ''
           OR emergency_contact_number IS NULL OR btrim(emergency_contact_number) = ''
    ",
];

foreach ($checks as $name => $sql) {
    echo $name . '=' . $pdo->query($sql)->fetchColumn() . PHP_EOL;
}
