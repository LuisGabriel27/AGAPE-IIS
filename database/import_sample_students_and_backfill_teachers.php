<?php
/**
 * One-time data helper for the AGAPE AIIS demo/defense database.
 *
 * Imports database/sample_students_guardians_50.csv and fills missing teacher
 * profile fields added by supabase_profile_fields_expansion.sql.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$csvPath = __DIR__ . '/sample_students_guardians_50.csv';

function boolFromCsv(?string $value): bool
{
    return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'true', 'y'], true);
}

function pgBool(bool $value): string
{
    return $value ? 'true' : 'false';
}

function blankToNull(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function requireColumn(PDO $pdo, string $table, string $column): void
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = 'public'
          AND table_name = :table_name
          AND column_name = :column_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    if ((int)$stmt->fetchColumn() === 0) {
        throw new RuntimeException(
            "Missing column {$table}.{$column}. Run database/supabase_profile_fields_expansion.sql first."
        );
    }
}

foreach ([
    ['students', 'middle_name'],
    ['students', 'psa_birth_certificate_no'],
    ['students', 'current_barangay'],
    ['guardians', 'middle_name'],
    ['guardians', 'data_privacy_consent'],
    ['teachers', 'employee_number'],
    ['teachers', 'prc_license_no'],
] as [$table, $column]) {
    requireColumn($pdo, $table, $column);
}

if (!is_file($csvPath)) {
    throw new RuntimeException("CSV file not found: {$csvPath}");
}

$handle = fopen($csvPath, 'r');
if (!$handle) {
    throw new RuntimeException("Unable to open CSV file: {$csvPath}");
}

$headers = fgetcsv($handle);
if (!$headers) {
    throw new RuntimeException('CSV is empty or missing headers.');
}

$studentColumns = [
    'guardian_id',
    'first_name',
    'middle_name',
    'last_name',
    'extension_name',
    'birthdate',
    'gender',
    'grade_level',
    'lrn',
    'psa_birth_certificate_no',
    'place_of_birth',
    'mother_tongue',
    'religion',
    'current_house_street',
    'current_barangay',
    'current_city_municipality',
    'current_province',
    'current_country',
    'current_zip_code',
    'permanent_same_as_current',
    'permanent_house_street',
    'permanent_barangay',
    'permanent_city_municipality',
    'permanent_province',
    'permanent_country',
    'permanent_zip_code',
    'father_first_name',
    'father_middle_name',
    'father_last_name',
    'father_contact_number',
    'mother_first_name',
    'mother_middle_name',
    'mother_maiden_last_name',
    'mother_contact_number',
    'is_ip_community',
    'is_4ps_beneficiary',
    'learner_with_disability',
    'returning_learner',
    'transferee',
    'last_school_attended',
];

$studentPlaceholders = array_map(static fn(string $column): string => ':' . $column, $studentColumns);
$insertStudent = $pdo->prepare("
    INSERT INTO students (" . implode(', ', $studentColumns) . ")
    VALUES (" . implode(', ', $studentPlaceholders) . ")
    RETURNING id
");

$insertGuardian = $pdo->prepare("
    INSERT INTO guardians
        (user_id, first_name, middle_name, last_name, contact_number, address, relationship_to_student,
         occupation, civil_status, nationality, religion, emergency_contact_name, emergency_contact_number,
         data_privacy_consent, data_privacy_consented_at)
    VALUES
        (:user_id, :first_name, :middle_name, :last_name, :contact_number, :address, :relationship,
         :occupation, :civil_status, :nationality, :religion, :emergency_name, :emergency_number,
         TRUE, NOW())
    RETURNING id
");

$updateGuardianMissing = $pdo->prepare("
    UPDATE guardians
    SET first_name = CASE WHEN first_name IS NULL OR btrim(first_name) = '' THEN :first_name ELSE first_name END,
        middle_name = CASE WHEN middle_name IS NULL OR btrim(middle_name) = '' THEN :middle_name ELSE middle_name END,
        last_name = CASE WHEN last_name IS NULL OR btrim(last_name) = '' THEN :last_name ELSE last_name END,
        contact_number = CASE WHEN contact_number IS NULL OR btrim(contact_number) = '' THEN :contact_number ELSE contact_number END,
        address = CASE WHEN address IS NULL OR btrim(address) = '' THEN :address ELSE address END,
        relationship_to_student = CASE WHEN relationship_to_student IS NULL OR btrim(relationship_to_student) = '' THEN :relationship ELSE relationship_to_student END,
        occupation = CASE WHEN occupation IS NULL OR btrim(occupation) = '' THEN :occupation ELSE occupation END,
        civil_status = COALESCE(civil_status, CAST(:civil_status AS civil_status_type)),
        nationality = CASE WHEN nationality IS NULL OR btrim(nationality) = '' THEN :nationality ELSE nationality END,
        religion = CASE WHEN religion IS NULL OR btrim(religion) = '' THEN :religion ELSE religion END,
        emergency_contact_name = CASE WHEN emergency_contact_name IS NULL OR btrim(emergency_contact_name) = '' THEN :emergency_name ELSE emergency_contact_name END,
        emergency_contact_number = CASE WHEN emergency_contact_number IS NULL OR btrim(emergency_contact_number) = '' THEN :emergency_number ELSE emergency_contact_number END,
        data_privacy_consent = TRUE,
        data_privacy_consented_at = COALESCE(data_privacy_consented_at, NOW())
    WHERE id = :guardian_id
");

$findUserByEmailStmt = $pdo->prepare("SELECT * FROM users WHERE lower(btrim(email)) = :email LIMIT 1");
$findGuardianByUserStmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :user_id LIMIT 1");
$findStudentByLrnStmt = $pdo->prepare("SELECT id FROM students WHERE btrim(lrn) = :lrn LIMIT 1");
$insertUserStmt = $pdo->prepare("
    INSERT INTO users (email, password_hash, role, is_active, created_at)
    VALUES (:email, :password_hash, 'guardian', 1, NOW())
    RETURNING id
");

$importedStudents = 0;
$skippedStudents = 0;
$createdUsers = 0;
$createdGuardians = 0;
$reusedGuardians = 0;
$rowNumber = 1;

try {
    $pdo->beginTransaction();

    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        if (count(array_filter($row, static fn($value): bool => trim((string)$value) !== '')) === 0) {
            continue;
        }

        $data = array_combine($headers, $row);
        if ($data === false) {
            throw new RuntimeException("CSV row {$rowNumber} does not match the header count.");
        }

        $lrn = trim((string)$data['lrn']);
        if (!preg_match('/^\d{12}$/', $lrn)) {
            throw new RuntimeException("CSV row {$rowNumber} has invalid LRN: {$lrn}");
        }

        $findStudentByLrnStmt->execute([':lrn' => $lrn]);
        if ($findStudentByLrnStmt->fetchColumn()) {
            $skippedStudents++;
            continue;
        }

        $email = normalizeEmailAddress((string)$data['guardian_email']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException("CSV row {$rowNumber} has invalid guardian email.");
        }

        $findUserByEmailStmt->execute([':email' => $email]);
        $user = $findUserByEmailStmt->fetch();
        if ($user) {
            $userId = (int)$user['id'];
            ensureUserRole($pdo, $userId, 'guardian');
        } else {
            $insertUserStmt->execute([
                ':email' => $email,
                ':password_hash' => password_hash('Guardian@1234', PASSWORD_BCRYPT),
            ]);
            $userId = (int)$insertUserStmt->fetchColumn();
            ensureUserRole($pdo, $userId, 'guardian');
            $createdUsers++;
        }

        $guardianParams = [
            ':user_id' => $userId,
            ':first_name' => trim((string)$data['guardian_first_name']),
            ':middle_name' => trim((string)$data['guardian_middle_name']),
            ':last_name' => trim((string)$data['guardian_last_name']),
            ':contact_number' => blankToNull($data['guardian_contact']),
            ':address' => blankToNull($data['guardian_address']),
            ':relationship' => blankToNull($data['guardian_relationship']),
            ':occupation' => blankToNull($data['guardian_occupation']),
            ':civil_status' => blankToNull($data['guardian_civil_status']) ?? 'others',
            ':nationality' => blankToNull($data['guardian_nationality']) ?? 'Filipino',
            ':religion' => blankToNull($data['guardian_religion']),
            ':emergency_name' => blankToNull($data['guardian_emergency_name']),
            ':emergency_number' => blankToNull($data['guardian_emergency_number']),
        ];

        $findGuardianByUserStmt->execute([':user_id' => $userId]);
        $guardian = $findGuardianByUserStmt->fetch();
        if ($guardian) {
            $guardianId = (int)$guardian['id'];
            $updateGuardianMissing->execute($guardianParams + [':guardian_id' => $guardianId]);
            $reusedGuardians++;
        } else {
            $insertGuardian->execute($guardianParams);
            $guardianId = (int)$insertGuardian->fetchColumn();
            $createdGuardians++;
        }

        $permanentSame = boolFromCsv($data['permanent_same_as_current'] ?? 'Yes');
        $studentParams = [
            ':guardian_id' => $guardianId,
            ':first_name' => trim((string)$data['student_first_name']),
            ':middle_name' => trim((string)$data['student_middle_name']),
            ':last_name' => trim((string)$data['student_last_name']),
            ':extension_name' => blankToNull($data['student_extension']),
            ':birthdate' => blankToNull($data['birthdate']),
            ':gender' => blankToNull($data['gender']),
            ':grade_level' => blankToNull($data['grade_level']),
            ':lrn' => $lrn,
            ':psa_birth_certificate_no' => blankToNull($data['psa_birth_certificate_no']),
            ':place_of_birth' => blankToNull($data['place_of_birth']),
            ':mother_tongue' => blankToNull($data['mother_tongue']),
            ':religion' => blankToNull($data['religion']),
            ':current_house_street' => blankToNull($data['current_house_street']),
            ':current_barangay' => blankToNull($data['current_barangay']),
            ':current_city_municipality' => blankToNull($data['current_city_municipality']),
            ':current_province' => blankToNull($data['current_province']),
            ':current_country' => blankToNull($data['current_country']) ?? 'Philippines',
            ':current_zip_code' => blankToNull($data['current_zip_code']),
            ':permanent_same_as_current' => pgBool($permanentSame),
            ':permanent_house_street' => $permanentSame ? blankToNull($data['current_house_street']) : null,
            ':permanent_barangay' => $permanentSame ? blankToNull($data['current_barangay']) : null,
            ':permanent_city_municipality' => $permanentSame ? blankToNull($data['current_city_municipality']) : null,
            ':permanent_province' => $permanentSame ? blankToNull($data['current_province']) : null,
            ':permanent_country' => $permanentSame ? (blankToNull($data['current_country']) ?? 'Philippines') : 'Philippines',
            ':permanent_zip_code' => $permanentSame ? blankToNull($data['current_zip_code']) : null,
            ':father_first_name' => blankToNull($data['father_first_name']),
            ':father_middle_name' => blankToNull($data['father_middle_name']),
            ':father_last_name' => blankToNull($data['father_last_name']),
            ':father_contact_number' => blankToNull($data['father_contact_number']),
            ':mother_first_name' => blankToNull($data['mother_first_name']),
            ':mother_middle_name' => blankToNull($data['mother_middle_name']),
            ':mother_maiden_last_name' => blankToNull($data['mother_maiden_last_name']),
            ':mother_contact_number' => blankToNull($data['mother_contact_number']),
            ':is_ip_community' => pgBool(boolFromCsv($data['is_ip_community'] ?? 'No')),
            ':is_4ps_beneficiary' => pgBool(boolFromCsv($data['is_4ps_beneficiary'] ?? 'No')),
            ':learner_with_disability' => pgBool(boolFromCsv($data['learner_with_disability'] ?? 'No')),
            ':returning_learner' => pgBool(boolFromCsv($data['returning_learner'] ?? 'No')),
            ':transferee' => pgBool(boolFromCsv($data['transferee'] ?? 'No')),
            ':last_school_attended' => blankToNull($data['last_school_attended']),
        ];
        $insertStudent->execute($studentParams);
        $importedStudents++;
    }

    fclose($handle);

    $teacherRows = $pdo->query("
        SELECT t.*, u.email
        FROM teachers t
        INNER JOIN users u ON u.id = t.user_id
        ORDER BY t.id
    ")->fetchAll();

    $middleNames = ['Santos', 'Reyes', 'Garcia', 'Cruz', 'Lopez', 'Mendoza', 'Flores', 'Ramos'];
    $addresses = [
        '15 Faculty Row, Jaro, Iloilo City',
        '28 Academic Lane, La Paz, Iloilo City',
        '42 Mabini Street, Molo, Iloilo City',
        '7 Rizal Avenue, Pavia, Iloilo',
        '19 Bonifacio Street, Oton, Iloilo',
        '31 Luna Street, Mandurriao, Iloilo City',
    ];
    $specializations = [
        'General Education',
        'Mathematics',
        'Science',
        'English',
        'Filipino',
        'MAPEH',
        'Araling Panlipunan',
        'Values Education',
    ];
    $emergencyLastNames = ['Dela Cruz', 'Santos', 'Reyes', 'Garcia', 'Torres', 'Ramos', 'Navarro', 'Flores'];

    $teacherUpdate = $pdo->prepare("
        UPDATE teachers
        SET middle_name = CASE WHEN middle_name IS NULL OR btrim(middle_name) = '' THEN :middle_name ELSE middle_name END,
            employee_number = CASE WHEN employee_number IS NULL OR btrim(employee_number) = '' THEN :employee_number ELSE employee_number END,
            gender = COALESCE(gender, CAST(:gender AS gender_type)),
            birthdate = COALESCE(birthdate, CAST(:birthdate AS date)),
            civil_status = COALESCE(civil_status, CAST(:civil_status AS civil_status_type)),
            contact_number = CASE WHEN contact_number IS NULL OR btrim(contact_number) = '' THEN :contact_number ELSE contact_number END,
            address = CASE WHEN address IS NULL OR btrim(address) = '' THEN :address ELSE address END,
            position_title = CASE WHEN position_title IS NULL OR btrim(position_title) = '' THEN :position_title ELSE position_title END,
            employment_status = CASE WHEN employment_status IS NULL OR btrim(employment_status) = '' THEN :employment_status ELSE employment_status END,
            date_hired = COALESCE(date_hired, CAST(:date_hired AS date)),
            prc_license_no = CASE WHEN prc_license_no IS NULL OR btrim(prc_license_no) = '' THEN :prc_license_no ELSE prc_license_no END,
            prc_license_expiry = COALESCE(prc_license_expiry, CAST(:prc_license_expiry AS date)),
            specialization = CASE WHEN specialization IS NULL OR btrim(specialization) = '' THEN :specialization ELSE specialization END,
            emergency_contact_name = CASE WHEN emergency_contact_name IS NULL OR btrim(emergency_contact_name) = '' THEN :emergency_contact_name ELSE emergency_contact_name END,
            emergency_contact_number = CASE WHEN emergency_contact_number IS NULL OR btrim(emergency_contact_number) = '' THEN :emergency_contact_number ELSE emergency_contact_number END
        WHERE id = :id
    ");

    $teachersBackfilled = 0;
    foreach ($teacherRows as $index => $teacher) {
        $teacherId = (int)$teacher['id'];
        $sequence = str_pad((string)$teacherId, 4, '0', STR_PAD_LEFT);
        $dept = trim((string)($teacher['department'] ?? ''));
        $teacherUpdate->execute([
            ':middle_name' => $middleNames[$index % count($middleNames)],
            ':employee_number' => 'TCH-2026-' . $sequence,
            ':gender' => $index % 2 === 0 ? 'female' : 'male',
            ':birthdate' => sprintf('%04d-%02d-%02d', 1982 + ($index % 12), ($index % 12) + 1, (($index * 3) % 27) + 1),
            ':civil_status' => $index % 5 === 0 ? 'single' : 'married',
            ':contact_number' => '+6391820' . str_pad((string)$teacherId, 4, '0', STR_PAD_LEFT),
            ':address' => $addresses[$index % count($addresses)],
            ':position_title' => 'Elementary Teacher',
            ':employment_status' => 'Permanent',
            ':date_hired' => sprintf('%04d-06-01', 2015 + ($index % 8)),
            ':prc_license_no' => 'PRC-2026-' . $sequence,
            ':prc_license_expiry' => sprintf('%04d-12-31', 2028 + ($index % 4)),
            ':specialization' => $dept !== '' ? $dept : $specializations[$index % count($specializations)],
            ':emergency_contact_name' => 'Emergency Contact ' . $emergencyLastNames[$index % count($emergencyLastNames)],
            ':emergency_contact_number' => '+6391920' . str_pad((string)$teacherId, 4, '0', STR_PAD_LEFT),
            ':id' => $teacherId,
        ]);
        if ($teacherUpdate->rowCount() > 0) {
            $teachersBackfilled++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($handle && is_resource($handle)) {
        fclose($handle);
    }
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "students_imported={$importedStudents}" . PHP_EOL;
echo "students_skipped_existing_lrn={$skippedStudents}" . PHP_EOL;
echo "guardian_users_created={$createdUsers}" . PHP_EOL;
echo "guardian_profiles_created={$createdGuardians}" . PHP_EOL;
echo "guardian_profiles_reused={$reusedGuardians}" . PHP_EOL;
echo "teachers_backfilled={$teachersBackfilled}" . PHP_EOL;
