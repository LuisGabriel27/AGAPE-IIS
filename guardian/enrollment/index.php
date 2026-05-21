<?php
/**
 * Guardian Enrollment — Combined Registration
 * Step 1: Guardian Profile (skip if already complete)
 * Step 2: Student Information
 * Step 3: Enrollment Details → Payment
 */

require_once __DIR__ . '/../../includes/session-check.php';
requireRole('guardian');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo    = getDB();
$userId = $_SESSION['user_id'];

$guardian = getOrCreateGuardianProfile($pdo, (int)$userId);

if (!$guardian) {
    setFlash('danger', 'Guardian profile could not be loaded. Please complete your profile first.');
    redirect(APP_URL . '/guardian/complete-profile.php');
}

// Determine if guardian profile is considered complete
$profileComplete = !empty($guardian['last_name'])
    && !empty($guardian['contact_number'])
    && !empty($guardian['relationship_to_student']);

$step    = (int)($_POST['step'] ?? $_GET['step'] ?? ($profileComplete ? 2 : 1));
$errors  = [];
$readPostedBool = static fn(string $key): bool => ($_POST[$key] ?? '0') === '1';
$sections = $pdo->query("SELECT id, name, grade_level, capacity FROM sections ORDER BY grade_level, name")->fetchAll();
$requiredDocuments = requiredEnrollmentDocuments();
$requirementDescriptions = requiredEnrollmentDocumentDescriptions();
$allowedRequirementExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
$allowedRequirementMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
$maxRequirementFileSize = 5 * 1024 * 1024;
$studentEnrollmentColumns = [
    'first_name',
    'middle_name',
    'last_name',
    'extension_name',
    'birthdate',
    'gender',
    'grade_level',
    'section_id',
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
    'ip_group',
    'is_4ps_beneficiary',
    'four_ps_household_id',
    'learner_with_disability',
    'disability_type',
    'returning_learner',
    'transferee',
    'last_grade_level_completed',
    'last_school_year_completed',
    'last_school_attended',
    'previous_school_id',
];
$studentBooleanColumns = [
    'permanent_same_as_current',
    'is_ip_community',
    'is_4ps_beneficiary',
    'learner_with_disability',
    'returning_learner',
    'transferee',
];
$truthyDbValue = static fn(mixed $value): bool => in_array($value, [true, 1, '1', 't', 'true', 'yes', 'on'], true);
$studentRecordToEnrollData = static function (array $student) use ($studentEnrollmentColumns, $studentBooleanColumns, $truthyDbValue): array {
    $data = ['student_id' => (int)($student['id'] ?? 0)];
    foreach ($studentEnrollmentColumns as $column) {
        if (in_array($column, $studentBooleanColumns, true)) {
            $data[$column] = $truthyDbValue($student[$column] ?? false);
        } elseif ($column === 'section_id') {
            $data[$column] = (int)($student[$column] ?? 0);
        } else {
            $data[$column] = (string)($student[$column] ?? '');
        }
    }
    return $data;
};

$studentStmt = $pdo->prepare("
    SELECT *
    FROM students
    WHERE guardian_id = :guardian_id
    ORDER BY grade_level, last_name, first_name, id
");
$studentStmt->execute([':guardian_id' => $guardian['id']]);
$guardianStudents = $studentStmt->fetchAll();
$guardianStudentsById = [];
foreach ($guardianStudents as $studentRow) {
    $guardianStudentsById[(int)$studentRow['id']] = $studentRow;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    // ── STEP 1 → 2: Save guardian profile ─────────────────
    if ($step === 2) {
        $gFirstName   = trim($_POST['g_first_name'] ?? '');
        $gMiddleName  = trim($_POST['g_middle_name'] ?? '');
        $gLastName    = trim($_POST['g_last_name'] ?? '');
        $gExtension   = normalizeNameExtension($_POST['g_extension_name'] ?? '');
        $gContact     = normalizePhoneNumber11($_POST['g_contact'] ?? '');
        $gAddress     = trim($_POST['g_address'] ?? '');
        $gRel         = trim($_POST['g_relationship'] ?? '');
        $gOccupation  = trim($_POST['g_occupation'] ?? '');
        $gCivilStatus = trim($_POST['g_civil_status'] ?? '');
        $gNationality = trim($_POST['g_nationality'] ?? 'Filipino');
        $gReligion    = trim($_POST['g_religion'] ?? '');
        $gEmergName   = trim($_POST['g_emergency_name'] ?? '');
        $gEmergNum    = normalizePhoneNumber11($_POST['g_emergency_number'] ?? '');

        if (empty($gLastName))  $errors[] = 'Your last name is required.';
        if (empty($gContact))   $errors[] = 'Contact number is required.';
        if (empty($gRel))       $errors[] = 'Relationship to student is required.';
        if (!isValidNameExtension($gExtension)) $errors[] = nameExtensionErrorMessage();
        if (!isValidPhoneNumber11($gContact, true)) $errors[] = phoneNumberErrorMessage('Contact number');
        if (!isValidPhoneNumber11($gEmergNum)) $errors[] = phoneNumberErrorMessage('Emergency contact number');

        if (empty($errors)) {
            $pdo->prepare("
                UPDATE guardians
                SET first_name = :fn, middle_name = :mn, last_name = :ln, extension_name = :ext,
                    contact_number = :contact, address = :addr,
                    relationship_to_student = :rel, occupation = :occ,
                    civil_status = :cs, nationality = :nat, religion = :rel2,
                    emergency_contact_name = :en, emergency_contact_number = :ec,
                    data_privacy_consent = TRUE,
                    data_privacy_consented_at = COALESCE(data_privacy_consented_at, NOW())
                WHERE user_id = :uid
            ")->execute([
                ':fn'      => $gFirstName,
                ':mn'      => $gMiddleName,
                ':ln'      => $gLastName,
                ':ext'     => nullIfBlank($gExtension),
                ':contact' => $gContact,
                ':addr'    => $gAddress ?: null,
                ':rel'     => $gRel,
                ':occ'     => $gOccupation ?: null,
                ':cs'      => $gCivilStatus ?: null,
                ':nat'     => $gNationality,
                ':rel2'    => $gReligion ?: null,
                ':en'      => $gEmergName ?: null,
                ':ec'      => $gEmergNum ?: null,
                ':uid'     => $userId,
            ]);
            // Re-fetch updated guardian
            $stmt = $pdo->prepare("SELECT * FROM guardians WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $userId]);
            $guardian = $stmt->fetch();
        } else {
            $step = 1;
        }
    }

    // ── STEP 2 → 3: Save student info in session ──────────
    if ($step === 3 && empty($errors)) {
        $selectedStudentId = (int)($_POST['existing_student_id'] ?? 0);
        $selectedStudent = null;
        if ($selectedStudentId > 0) {
            $selectedStudent = $guardianStudentsById[$selectedStudentId] ?? null;
            if (!$selectedStudent) {
                $errors[] = 'Selected student was not found under your guardian account.';
                $step = 2;
            }
        }

        $_SESSION['enroll'] = [
            'student_id'                   => $selectedStudent ? $selectedStudentId : 0,
            'first_name'                  => trim($_POST['first_name'] ?? ''),
            'middle_name'                 => trim($_POST['middle_name'] ?? ''),
            'last_name'                   => trim($_POST['last_name'] ?? ''),
            'extension_name'              => normalizeNameExtension($_POST['extension_name'] ?? ''),
            'birthdate'                   => trim($_POST['birthdate'] ?? ''),
            'gender'                      => trim($_POST['gender'] ?? ''),
            'lrn'                         => trim($_POST['lrn'] ?? ''),
            'psa_birth_certificate_no'    => trim($_POST['psa_birth_certificate_no'] ?? ''),
            'place_of_birth'              => trim($_POST['place_of_birth'] ?? ''),
            'mother_tongue'               => trim($_POST['mother_tongue'] ?? ''),
            'religion'                    => trim($_POST['religion'] ?? ''),
            'current_house_street'        => trim($_POST['current_house_street'] ?? ''),
            'current_barangay'            => trim($_POST['current_barangay'] ?? ''),
            'current_city_municipality'   => trim($_POST['current_city_municipality'] ?? ''),
            'current_province'            => trim($_POST['current_province'] ?? ''),
            'current_country'             => trim($_POST['current_country'] ?? 'Philippines') ?: 'Philippines',
            'current_zip_code'            => trim($_POST['current_zip_code'] ?? ''),
            'permanent_same_as_current'   => $readPostedBool('permanent_same_as_current'),
            'permanent_house_street'      => trim($_POST['permanent_house_street'] ?? ''),
            'permanent_barangay'          => trim($_POST['permanent_barangay'] ?? ''),
            'permanent_city_municipality' => trim($_POST['permanent_city_municipality'] ?? ''),
            'permanent_province'          => trim($_POST['permanent_province'] ?? ''),
            'permanent_country'           => trim($_POST['permanent_country'] ?? 'Philippines') ?: 'Philippines',
            'permanent_zip_code'          => trim($_POST['permanent_zip_code'] ?? ''),
            'father_first_name'           => trim($_POST['father_first_name'] ?? ''),
            'father_middle_name'          => trim($_POST['father_middle_name'] ?? ''),
            'father_last_name'            => trim($_POST['father_last_name'] ?? ''),
            'father_contact_number'       => normalizePhoneNumber11($_POST['father_contact_number'] ?? ''),
            'mother_first_name'           => trim($_POST['mother_first_name'] ?? ''),
            'mother_middle_name'          => trim($_POST['mother_middle_name'] ?? ''),
            'mother_maiden_last_name'     => trim($_POST['mother_maiden_last_name'] ?? ''),
            'mother_contact_number'       => normalizePhoneNumber11($_POST['mother_contact_number'] ?? ''),
            'is_ip_community'             => $readPostedBool('is_ip_community'),
            'ip_group'                    => trim($_POST['ip_group'] ?? ''),
            'is_4ps_beneficiary'          => $readPostedBool('is_4ps_beneficiary'),
            'four_ps_household_id'        => trim($_POST['four_ps_household_id'] ?? ''),
            'learner_with_disability'     => $readPostedBool('learner_with_disability'),
            'disability_type'             => trim($_POST['disability_type'] ?? ''),
            'returning_learner'           => $readPostedBool('returning_learner'),
            'transferee'                  => $readPostedBool('transferee'),
            'last_grade_level_completed'  => trim($_POST['last_grade_level_completed'] ?? ''),
            'last_school_year_completed'  => trim($_POST['last_school_year_completed'] ?? ''),
            'last_school_attended'        => trim($_POST['last_school_attended'] ?? ''),
            'previous_school_id'          => trim($_POST['previous_school_id'] ?? ''),
        ];

        if ($selectedStudent) {
            $selectedStudentData = $studentRecordToEnrollData($selectedStudent);
            $_SESSION['enroll']['grade_level'] = $selectedStudentData['grade_level'];
            $_SESSION['enroll']['section_id'] = $selectedStudentData['section_id'];
        }

        if ($_SESSION['enroll']['permanent_same_as_current']) {
            $_SESSION['enroll']['permanent_house_street'] = $_SESSION['enroll']['current_house_street'];
            $_SESSION['enroll']['permanent_barangay'] = $_SESSION['enroll']['current_barangay'];
            $_SESSION['enroll']['permanent_city_municipality'] = $_SESSION['enroll']['current_city_municipality'];
            $_SESSION['enroll']['permanent_province'] = $_SESSION['enroll']['current_province'];
            $_SESSION['enroll']['permanent_country'] = $_SESSION['enroll']['current_country'];
            $_SESSION['enroll']['permanent_zip_code'] = $_SESSION['enroll']['current_zip_code'];
        }

        if (empty($_SESSION['enroll']['last_name'])) {
            $errors[] = 'Student last name is required.';
            $step = 2;
        }

        if ($_SESSION['enroll']['lrn'] !== '' && !preg_match('/^\d{12}$/', $_SESSION['enroll']['lrn'])) {
            $errors[] = 'LRN must be exactly 12 digits.';
            $step = 2;
        }

        if (!isValidNameExtension($_SESSION['enroll']['extension_name'])) {
            $errors[] = nameExtensionErrorMessage();
            $step = 2;
        }

        foreach ([
            'father_contact_number' => 'Father contact number',
            'mother_contact_number' => 'Mother contact number',
        ] as $field => $label) {
            if (!isValidPhoneNumber11($_SESSION['enroll'][$field] ?? '')) {
                $errors[] = phoneNumberErrorMessage($label);
                $step = 2;
            }
        }

        if (empty($errors)) {
            try {
                $duplicate = findDuplicateStudent(
                    $pdo,
                    $_SESSION['enroll']['first_name'],
                    $_SESSION['enroll']['last_name'],
                    $_SESSION['enroll']['lrn'],
                    (int)($_SESSION['enroll']['student_id'] ?? 0)
                );

                if ($duplicate) {
                    $duplicateName = format_name($duplicate['first_name'] ?? '', $duplicate['last_name'] ?? '');
                    if (($duplicate['duplicate_type'] ?? '') === 'lrn') {
                        $errors[] = 'A student with this LRN already exists: ' . $duplicateName . '. Please contact the registrar if this is your child.';
                    } else {
                        $errors[] = 'A student with the same full name already exists: ' . $duplicateName . '. Please contact the registrar if this is your child.';
                    }
                    $step = 2;
                }
            } catch (Exception $e) {
                error_log('Enrollment duplicate check error: ' . $e->getMessage());
                $errors[] = 'Unable to check for duplicate students. Please try again.';
                $step = 2;
            }
        }
    }

    // ── STEP 3 → Submit: Create records ───────────────────
    if ($step === 4 && empty($errors)) {
        $_SESSION['enroll']['grade_level'] = trim($_POST['grade_level'] ?? '');
        $_SESSION['enroll']['section_id']  = (int)($_POST['section_id'] ?? 0);
        $_SESSION['enroll']['school_year'] = trim($_POST['school_year'] ?? currentSchoolYear());
        $_SESSION['enroll']['term']        = trim($_POST['term'] ?? '1st Semester');
        $requiredDocuments = requiredEnrollmentDocumentsForGrade($_SESSION['enroll']['grade_level'] ?? '');
        $uploadedRequirements = [];

        $enrollData = $_SESSION['enroll'] ?? [];
        if (empty($enrollData['last_name']) || empty($enrollData['grade_level'])) {
            $errors[] = 'Enrollment data is incomplete.';
            $step = 2;
        }

        if (empty($errors)) {
            $fileInfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
            foreach ($requiredDocuments as $docKey => $docLabel) {
                $uploadError = $_FILES['requirements']['error'][$docKey] ?? UPLOAD_ERR_NO_FILE;
                $originalName = (string)($_FILES['requirements']['name'][$docKey] ?? '');
                $tmpName = (string)($_FILES['requirements']['tmp_name'][$docKey] ?? '');
                $size = (int)($_FILES['requirements']['size'][$docKey] ?? 0);

                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                    $errors[] = $docLabel . ' is required.';
                    continue;
                }

                if ($uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($tmpName)) {
                    $errors[] = 'Unable to upload ' . $docLabel . '. Please try again.';
                    continue;
                }

                if ($size > $maxRequirementFileSize) {
                    $errors[] = $docLabel . ' must be 5MB or smaller.';
                    continue;
                }

                $safeOriginal = substr(basename(str_replace('\\', '/', $originalName)), 0, 255);
                $extension = strtolower(pathinfo($safeOriginal, PATHINFO_EXTENSION));
                if (!in_array($extension, $allowedRequirementExtensions, true)) {
                    $errors[] = $docLabel . ' must be a PDF, JPG, JPEG, or PNG file.';
                    continue;
                }

                $mimeType = $fileInfo ? (string)finfo_file($fileInfo, $tmpName) : (string)($_FILES['requirements']['type'][$docKey] ?? '');
                if (!in_array($mimeType, $allowedRequirementMimeTypes, true)) {
                    $errors[] = $docLabel . ' has an unsupported file type.';
                    continue;
                }

                $uploadedRequirements[$docKey] = [
                    'label' => $docLabel,
                    'original_name' => $safeOriginal,
                    'tmp_name' => $tmpName,
                    'extension' => $extension,
                    'mime_type' => $mimeType,
                    'size' => $size,
                ];
            }
            if ($fileInfo) {
                finfo_close($fileInfo);
            }

            if (!empty($errors)) {
                $step = 3;
            }
        }

        if (empty($errors)) {
            $movedRequirementFiles = [];
            $committed = false;
            try {
                $pdo->beginTransaction();

                $studentColumns = array_merge(['guardian_id'], $studentEnrollmentColumns);
                $studentParams = [];
                foreach ($studentColumns as $column) {
                    if ($column === 'guardian_id') {
                        $studentParams[':' . $column] = $guardian['id'];
                        continue;
                    }
                    $value = $enrollData[$column] ?? null;
                    if (is_bool($value)) {
                        $studentParams[':' . $column] = $value ? 'true' : 'false';
                    } elseif ($column === 'section_id') {
                        $sectionId = (int)($value ?? 0);
                        $studentParams[':' . $column] = $sectionId > 0 ? $sectionId : null;
                    } elseif (is_int($value) || $value === null) {
                        $studentParams[':' . $column] = $value;
                    } elseif (in_array($column, ['first_name', 'middle_name', 'last_name'], true)) {
                        $studentParams[':' . $column] = trim((string)$value);
                    } elseif (in_array($column, ['current_country', 'permanent_country'], true)) {
                        $studentParams[':' . $column] = trim((string)$value) !== '' ? trim((string)$value) : 'Philippines';
                    } else {
                        $value = trim((string)$value);
                        $studentParams[':' . $column] = $value === '' ? null : $value;
                    }
                }

                $existingStudentId = (int)($enrollData['student_id'] ?? 0);
                if ($existingStudentId > 0) {
                    $updateColumns = array_values(array_filter($studentEnrollmentColumns, static fn(string $column): bool => $column !== 'student_id'));
                    $setParts = array_map(static fn(string $column): string => "{$column} = :{$column}", $updateColumns);
                    $stmt = $pdo->prepare("
                        UPDATE students
                        SET " . implode(', ', $setParts) . "
                        WHERE id = :student_id
                          AND guardian_id = :guardian_id
                    ");
                    $stmt->execute($studentParams + [':student_id' => $existingStudentId]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException('Selected student was not found under your guardian account.');
                    }
                    $studentId = $existingStudentId;
                } else {
                    $placeholders = array_map(static fn(string $col): string => ':' . $col, $studentColumns);
                    $stmt = $pdo->prepare("
                        INSERT INTO students (" . implode(', ', $studentColumns) . ")
                        VALUES (" . implode(', ', $placeholders) . ")
                        RETURNING id
                    ");
                    $stmt->execute($studentParams);
                    $studentId = (int)$stmt->fetchColumn();
                }

                $existingEnrollmentStmt = $pdo->prepare("
                    SELECT id
                    FROM enrollments
                    WHERE student_id = :student_id
                      AND school_year = :school_year
                      AND term = :term
                    LIMIT 1
                ");
                $existingEnrollmentStmt->execute([
                    ':student_id' => $studentId,
                    ':school_year' => $enrollData['school_year'],
                    ':term' => $enrollData['term'],
                ]);
                if ($existingEnrollmentStmt->fetchColumn()) {
                    throw new RuntimeException('This student already has an enrollment for the selected school year and term.');
                }

                $initialStatus = enrollmentStatusForDocumentCount(count($uploadedRequirements), count($requiredDocuments));
                $stmt = $pdo->prepare("
                    INSERT INTO enrollments (student_id, school_year, term, status, payment_submitted_at)
                    VALUES (:sid, :sy, :term, :status, NULL) RETURNING id
                ");
                $stmt->execute([
                    ':sid'    => $studentId,
                    ':sy'     => $enrollData['school_year'],
                    ':term'   => $enrollData['term'],
                    ':status' => $initialStatus,
                ]);
                $enrollmentId = (int)$stmt->fetchColumn();

                $docStmt = $pdo->prepare("
                    INSERT INTO enrollment_documents
                        (enrollment_id, document_type, original_name, file_path, mime_type, file_size, uploaded_by, review_status, reviewer_note, reviewed_by, reviewed_at)
                    VALUES
                        (:enrollment_id, :document_type, :original_name, :file_path, :mime_type, :file_size, :uploaded_by, 'pending', NULL, NULL, NULL)
                    ON CONFLICT (enrollment_id, document_type)
                    DO UPDATE SET
                        original_name = EXCLUDED.original_name,
                        file_path = EXCLUDED.file_path,
                        mime_type = EXCLUDED.mime_type,
                        file_size = EXCLUDED.file_size,
                        uploaded_by = EXCLUDED.uploaded_by,
                        uploaded_at = NOW(),
                        review_status = 'pending',
                        reviewer_note = NULL,
                        reviewed_by = NULL,
                        reviewed_at = NULL
                ");

                foreach ($uploadedRequirements as $docKey => $file) {
                    $storedName = $docKey . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $file['extension'];
                    $storedPath = storeEnrollmentDocumentUpload(
                        $file['tmp_name'],
                        $enrollmentId,
                        $storedName,
                        $file['mime_type']
                    );
                    $movedRequirementFiles[] = $storedPath;

                    $docStmt->execute([
                        ':enrollment_id' => $enrollmentId,
                        ':document_type' => $docKey,
                        ':original_name' => $file['original_name'],
                        ':file_path' => $storedPath,
                        ':mime_type' => $file['mime_type'],
                        ':file_size' => $file['size'],
                        ':uploaded_by' => $userId,
                    ]);
                }

                $pdo->commit();
                $committed = true;
                unset($_SESSION['enroll']);

                try {
                    auditLog('enrollment_submitted', 'enrollments', $enrollmentId, null, [
                        'student_id'  => $studentId,
                        'school_year' => $enrollData['school_year'],
                        'term'        => $enrollData['term'],
                        'documents'   => array_keys($uploadedRequirements),
                    ]);
                } catch (Exception $auditError) {
                    error_log('Enrollment audit error: ' . $auditError->getMessage());
                }

                setFlash('success', 'Enrollment requirements uploaded. The Enrollment Clerk can now review the files, assess the enrollment, and validate its status.');
                redirect(APP_URL . '/guardian/dashboard.php');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (!$committed) {
                    foreach ($movedRequirementFiles as $path) {
                        deleteEnrollmentDocumentStoredFile($path);
                    }
                }
                error_log('Enrollment creation error: ' . $e->getMessage());
                if (str_contains($e->getMessage(), 'already has an enrollment')) {
                    $errors[] = $e->getMessage();
                } elseif (str_contains($e->getMessage(), 'Selected student was not found')) {
                    $errors[] = 'Selected student was not found under your guardian account.';
                } else {
                    $errors[] = 'An error occurred while saving enrollment. Please try again.';
                }
                $step = 3;
            }
        }
    }
}

$enrollData = $_SESSION['enroll'] ?? [];
$stepRequiredDocuments = requiredEnrollmentDocumentsForGrade($enrollData['grade_level'] ?? '');
$hasMeaningfulStudentData = isset($enrollData['student_id'])
    || trim((string)($enrollData['last_name'] ?? '')) !== ''
    || trim((string)($enrollData['first_name'] ?? '')) !== ''
    || trim((string)($enrollData['lrn'] ?? '')) !== '';
if ($step === 2 && !$hasMeaningfulStudentData && !empty($guardianStudents)) {
    $enrollData = $studentRecordToEnrollData($guardianStudents[0]);
}
$selectedStudentIdForForm = (int)($enrollData['student_id'] ?? ($guardianStudents[0]['id'] ?? 0));
$guardianStudentPrefill = [];
foreach ($guardianStudents as $studentRow) {
    $data = $studentRecordToEnrollData($studentRow);
    $guardianStudentPrefill[(string)$studentRow['id']] = $data;
}
$totalSteps = $profileComplete ? 3 : 4; // if profile is already done, skip step 1 visually
$pageTitle  = 'Enrollment';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h4 class="fw-bold mb-0"><i class="bi bi-pencil-square me-2"></i>Student Enrollment</h4>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <a class="btn btn-outline-primary btn-sm" href="<?= APP_URL ?>/guardian/enrollment/certificate.php" target="_blank" rel="noopener">
            <i class="bi bi-printer me-1"></i>Print Certificate
        </a>
    </div>
</div>

<?php
// Build step labels based on whether profile step is needed
if (!$profileComplete) {
    $stepLabels = ['1' => 'Parent Data', '2' => 'Student Info', '3' => 'Upload Requirements', '4' => 'Clerk Review'];
    $displayStep = $step;
} else {
    $stepLabels = ['2' => 'Student Info', '3' => 'Upload Requirements', '4' => 'Clerk Review'];
    $displayStep = $step - 1;
}
$totalDisplaySteps = count($stepLabels);
$stepKeys = array_keys($stepLabels);
?>

<div class="step-indicator mb-4">
    <?php foreach ($stepKeys as $idx => $k):
        $label = $stepLabels[$k];
        $isActive = ((int)$k === $step);
        $isCompleted = ((int)$k < $step);
        $cls = $isActive ? 'active' : ($isCompleted ? 'completed' : '');
    ?>
    <?php if ($idx > 0): ?><div class="step-line"></div><?php endif; ?>
    <div class="step <?= e($cls) ?>">
        <span class="step-number"><?= $isCompleted ? '&#10003;' : e((string)($idx + 1)) ?></span>
        <span><?= e($label) ?></span>
    </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card">
<div class="card-body p-4">

<?php if ($step === 1): ?>
    <!-- ────────────────────────────────────────────────── -->
    <!-- STEP 1: Guardian / Parent Details                  -->
    <!-- ────────────────────────────────────────────────── -->
    <h5 class="fw-bold mb-3"><i class="bi bi-person-lines-fill me-2"></i>Step 1: Parent / Guardian Data</h5>
    <p class="text-muted small mb-4">Please complete the parent data needed by the Registrar before enrollment assessment.</p>
    <form method="POST" action="" id="guardian-profile-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="step" value="2">

        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="g_last_name"
                       value="<?= e($guardian['last_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">First Name</label>
                <input type="text" class="form-control" name="g_first_name"
                       value="<?= e($guardian['first_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="g_middle_name"
                       value="<?= e($guardian['middle_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Extension</label>
                <select class="form-select" name="g_extension_name">
                    <?php foreach (nameExtensionOptions() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= e(normalizeNameExtension($guardian['extension_name'] ?? '') === $value ? 'selected' : '') ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                <input class="form-control" name="g_contact"
                       value="<?= e($guardian['contact_number'] ?? '') ?>" <?= phoneInputAttributes(true) ?>>
            </div>
            <div class="col-12 mb-3">
                <label class="form-label">Home Address</label>
                <textarea class="form-control" name="g_address" rows="2"><?= e($guardian['address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Relationship to Student <span class="text-danger">*</span></label>
                <select class="form-select" name="g_relationship" required>
                    <option value="">Select...</option>
                    <?php foreach (['Parent','Guardian','Sibling','Grandparent','Aunt/Uncle','Other'] as $r): ?>
                        <option value="<?= e($r) ?>" <?= e(($guardian['relationship_to_student'] ?? '') === $r ? 'selected' : '') ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Occupation</label>
                <input type="text" class="form-control" name="g_occupation"
                       value="<?= e($guardian['occupation'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Civil Status</label>
                <select class="form-select" name="g_civil_status">
                    <option value="">Select...</option>
                    <?php foreach (['single','married','widowed','separated','others'] as $cs): ?>
                        <option value="<?= e($cs) ?>" <?= e(($guardian['civil_status'] ?? '') === $cs ? 'selected' : '') ?>><?= e(ucfirst($cs)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Nationality</label>
                <input type="text" class="form-control" name="g_nationality"
                       value="<?= e($guardian['nationality'] ?? 'Filipino') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Religion</label>
                <input type="text" class="form-control" name="g_religion"
                       value="<?= e($guardian['religion'] ?? '') ?>">
            </div>
        </div>

        <h6 class="fw-semibold mb-3 mt-2">Emergency Contact</h6>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Name</label>
                <input type="text" class="form-control" name="g_emergency_name"
                       value="<?= e($guardian['emergency_contact_name'] ?? '') ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Emergency Contact Number</label>
                <input class="form-control" name="g_emergency_number"
                       value="<?= e($guardian['emergency_contact_number'] ?? '') ?>" <?= phoneInputAttributes() ?>>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            Save &amp; Continue <i class="bi bi-arrow-right ms-1"></i>
        </button>
    </form>

<?php elseif ($step === 2): ?>
    <!-- ────────────────────────────────────────────────── -->
    <!-- STEP 2: Student Information                        -->
    <!-- ────────────────────────────────────────────────── -->
    <h5 class="fw-bold mb-3"><i class="bi bi-person-badge me-2"></i>Step <?= $profileComplete ? '1' : '2' ?>: Student Information</h5>
    <form method="POST" action="" id="enrollment-step-student">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="step" value="3">

        <?php if (!empty($guardianStudents)): ?>
            <div class="mb-3">
                <label class="form-label">Student to Enroll</label>
                <select class="form-select" name="existing_student_id" id="existing-student-select">
                    <?php foreach ($guardianStudents as $studentOption): ?>
                        <?php
                        $studentOptionId = (int)$studentOption['id'];
                        $studentOptionName = trim(format_name($studentOption['first_name'] ?? '', $studentOption['last_name'] ?? '') . ' ' . ($studentOption['middle_name'] ?? ''));
                        ?>
                        <option value="<?= $studentOptionId ?>" <?= $selectedStudentIdForForm === $studentOptionId ? 'selected' : '' ?>>
                            <?= e($studentOptionName) ?><?= !empty($studentOption['grade_level']) ? ' - ' . e(formatGradeLevel((string)$studentOption['grade_level'])) : '' ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="0" <?= $selectedStudentIdForForm === 0 ? 'selected' : '' ?>>Enroll a new student</option>
                </select>
            </div>
        <?php else: ?>
            <input type="hidden" name="existing_student_id" value="0">
        <?php endif; ?>

        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Student Last Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="last_name"
                       value="<?= e($enrollData['last_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Student First Name</label>
                <input type="text" class="form-control" name="first_name"
                       value="<?= e($enrollData['first_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Middle Name</label>
                <input type="text" class="form-control" name="middle_name"
                       value="<?= e($enrollData['middle_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Extension</label>
                <select class="form-select" name="extension_name">
                    <?php foreach (nameExtensionOptions() as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= e(normalizeNameExtension($enrollData['extension_name'] ?? '') === $value ? 'selected' : '') ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Birthdate</label>
                <input type="date" class="form-control" name="birthdate"
                       value="<?= e($enrollData['birthdate'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Gender</label>
                <select class="form-select" name="gender">
                    <option value="">Select...</option>
                    <?php foreach (['male','female','other'] as $g): ?>
                        <option value="<?= e($g) ?>" <?= ($enrollData['gender'] ?? '') === $g ? 'selected' : '' ?>><?= e(ucfirst($g)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Learner Reference Number (LRN)</label>
                <input type="text" class="form-control" name="lrn"
                       value="<?= e($enrollData['lrn'] ?? '') ?>"
                       maxlength="12" placeholder="12-digit LRN">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">PSA Birth Certificate No.</label>
                <input type="text" class="form-control" name="psa_birth_certificate_no"
                       value="<?= e($enrollData['psa_birth_certificate_no'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Place of Birth</label>
                <input type="text" class="form-control" name="place_of_birth"
                       value="<?= e($enrollData['place_of_birth'] ?? '') ?>">
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">Mother Tongue</label>
                <input type="text" class="form-control" name="mother_tongue"
                       value="<?= e($enrollData['mother_tongue'] ?? '') ?>">
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">Religion</label>
                <input type="text" class="form-control" name="religion"
                       value="<?= e($enrollData['religion'] ?? '') ?>">
            </div>
        </div>

        <h6 class="fw-semibold mb-3 mt-2">Address</h6>
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Current House / Street</label>
                <input type="text" class="form-control" name="current_house_street"
                       value="<?= e($enrollData['current_house_street'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Current Barangay</label>
                <input type="text" class="form-control" name="current_barangay"
                       value="<?= e($enrollData['current_barangay'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">City / Municipality</label>
                <input type="text" class="form-control" name="current_city_municipality"
                       value="<?= e($enrollData['current_city_municipality'] ?? '') ?>">
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">ZIP Code</label>
                <input type="text" class="form-control" name="current_zip_code"
                       value="<?= e($enrollData['current_zip_code'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Province</label>
                <input type="text" class="form-control" name="current_province"
                       value="<?= e($enrollData['current_province'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="current_country"
                       value="<?= e($enrollData['current_country'] ?? 'Philippines') ?>">
            </div>
            <div class="col-md-4 mb-3 d-flex align-items-end">
                <div class="form-check">
                    <input type="hidden" name="permanent_same_as_current" value="0">
                    <input class="form-check-input" type="checkbox" name="permanent_same_as_current" value="1" id="enroll-same-address"
                           <?= !isset($enrollData['permanent_same_as_current']) || !empty($enrollData['permanent_same_as_current']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enroll-same-address">Permanent address is same</label>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Permanent House / Street</label>
                <input type="text" class="form-control" name="permanent_house_street"
                       value="<?= e($enrollData['permanent_house_street'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Permanent Barangay</label>
                <input type="text" class="form-control" name="permanent_barangay"
                       value="<?= e($enrollData['permanent_barangay'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">City / Municipality</label>
                <input type="text" class="form-control" name="permanent_city_municipality"
                       value="<?= e($enrollData['permanent_city_municipality'] ?? '') ?>">
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">ZIP Code</label>
                <input type="text" class="form-control" name="permanent_zip_code"
                       value="<?= e($enrollData['permanent_zip_code'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Province</label>
                <input type="text" class="form-control" name="permanent_province"
                       value="<?= e($enrollData['permanent_province'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Country</label>
                <input type="text" class="form-control" name="permanent_country"
                       value="<?= e($enrollData['permanent_country'] ?? 'Philippines') ?>">
            </div>
        </div>

        <h6 class="fw-semibold mb-3 mt-2">Parents</h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Father Last Name</label>
                <input type="text" class="form-control" name="father_last_name"
                       value="<?= e($enrollData['father_last_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Father First Name</label>
                <input type="text" class="form-control" name="father_first_name"
                       value="<?= e($enrollData['father_first_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Father Middle Name</label>
                <input type="text" class="form-control" name="father_middle_name"
                       value="<?= e($enrollData['father_middle_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Father Contact</label>
                <input class="form-control" name="father_contact_number"
                       value="<?= e($enrollData['father_contact_number'] ?? '') ?>" <?= phoneInputAttributes() ?>>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Mother Maiden Last Name</label>
                <input type="text" class="form-control" name="mother_maiden_last_name"
                       value="<?= e($enrollData['mother_maiden_last_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Mother First Name</label>
                <input type="text" class="form-control" name="mother_first_name"
                       value="<?= e($enrollData['mother_first_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Mother Middle Name</label>
                <input type="text" class="form-control" name="mother_middle_name"
                       value="<?= e($enrollData['mother_middle_name'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Mother Contact</label>
                <input class="form-control" name="mother_contact_number"
                       value="<?= e($enrollData['mother_contact_number'] ?? '') ?>" <?= phoneInputAttributes() ?>>
            </div>
        </div>

        <h6 class="fw-semibold mb-3 mt-2">Learner Background</h6>
        <div class="row">
            <div class="col-md-3 mb-3">
                <input type="hidden" name="is_ip_community" value="0">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="is_ip_community" value="1" id="enroll-is-ip"
                           <?= !empty($enrollData['is_ip_community']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enroll-is-ip">Indigenous Peoples</label>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">IP Group</label>
                <input type="text" class="form-control" name="ip_group"
                       value="<?= e($enrollData['ip_group'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <input type="hidden" name="is_4ps_beneficiary" value="0">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="is_4ps_beneficiary" value="1" id="enroll-is-4ps"
                           <?= !empty($enrollData['is_4ps_beneficiary']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enroll-is-4ps">4Ps Beneficiary</label>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">4Ps Household ID</label>
                <input type="text" class="form-control" name="four_ps_household_id"
                       value="<?= e($enrollData['four_ps_household_id'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <input type="hidden" name="learner_with_disability" value="0">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="learner_with_disability" value="1" id="enroll-has-disability"
                           <?= !empty($enrollData['learner_with_disability']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enroll-has-disability">Learner with Disability</label>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Disability Type</label>
                <input type="text" class="form-control" name="disability_type"
                       value="<?= e($enrollData['disability_type'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <input type="hidden" name="returning_learner" value="0">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="returning_learner" value="1" id="enroll-returning"
                           <?= !empty($enrollData['returning_learner']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enroll-returning">Returning Learner</label>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <input type="hidden" name="transferee" value="0">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="transferee" value="1" id="enroll-transferee"
                           <?= !empty($enrollData['transferee']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="enroll-transferee">Transferee</label>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Last Grade Completed</label>
                <input type="text" class="form-control" name="last_grade_level_completed"
                       value="<?= e($enrollData['last_grade_level_completed'] ?? '') ?>">
            </div>
            <div class="col-md-3 mb-3">
                <label class="form-label">Last School Year</label>
                <input type="text" class="form-control" name="last_school_year_completed"
                       value="<?= e($enrollData['last_school_year_completed'] ?? '') ?>">
            </div>
            <div class="col-md-4 mb-3">
                <label class="form-label">Last School Attended</label>
                <input type="text" class="form-control" name="last_school_attended"
                       value="<?= e($enrollData['last_school_attended'] ?? '') ?>">
            </div>
            <div class="col-md-2 mb-3">
                <label class="form-label">School ID</label>
                <input type="text" class="form-control" name="previous_school_id"
                       value="<?= e($enrollData['previous_school_id'] ?? '') ?>">
            </div>
        </div>

        <div class="d-flex gap-2">
            <?php if (!$profileComplete): ?>
                <a href="?step=1" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                Next: Upload Requirements <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>
    </form>

<?php elseif ($step === 3): ?>
    <!-- ────────────────────────────────────────────────── -->
    <!-- STEP 3: Enrollment Details                         -->
    <!-- ────────────────────────────────────────────────── -->
    <h5 class="fw-bold mb-3"><i class="bi bi-journal-check me-2"></i>Step <?= $profileComplete ? '2' : '3' ?>: Upload Enrollment Requirements</h5>
    <form method="POST" action="" id="enrollment-step-details" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="step" value="4">

        <div class="mb-3">
            <label class="form-label">Grade Level <span class="text-danger">*</span></label>
            <select class="form-select" name="grade_level" required>
                <option value="">Select...</option>
                <?php foreach (basicEducationGradeLevels() as $gl => $label): ?>
                    <option value="<?= e($gl) ?>" <?= ($enrollData['grade_level'] ?? '') == $gl ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Section</label>
            <select class="form-select" name="section_id">
                <option value="0">To be assigned</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?= (int)$sec['id'] ?>" <?= ($enrollData['section_id'] ?? 0) == $sec['id'] ? 'selected' : '' ?>>
                        <?= e($sec['name']) ?> (<?= e(formatGradeLevel((string)$sec['grade_level'])) ?>, Capacity: <?= e((string)$sec['capacity']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">School Year</label>
                <input type="text" class="form-control" name="school_year"
                       value="<?= e($enrollData['school_year'] ?? currentSchoolYear()) ?>" readonly>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Term</label>
                <select class="form-select" name="term">
                    <option value="1st Semester" <?= ($enrollData['term'] ?? '1st Semester') === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
                    <option value="2nd Semester" <?= ($enrollData['term'] ?? '') === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
                </select>
            </div>
        </div>

        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-1"></i>
            Upload clear copies of the required enrollment documents. The Enrollment Clerk will review these files before payment assessment and final status validation.
        </div>

        <div class="row">
            <?php foreach ($stepRequiredDocuments as $docKey => $docLabel): ?>
                <div class="col-md-6 mb-3 requirement-upload" data-requirement-key="<?= e($docKey) ?>">
                    <label class="form-label"><?= e($docLabel) ?> <span class="text-danger">*</span></label>
                    <input type="file" class="form-control" name="requirements[<?= e($docKey) ?>]" accept=".pdf,.jpg,.jpeg,.png" required>
                    <div class="form-text"><?= e($requirementDescriptions[$docKey] ?? '') ?> PDF, JPG, JPEG, or PNG. Max 5MB.</div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex justify-content-between">
            <a href="?step=2" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <button type="submit" class="btn btn-success">
                Submit Requirements for Clerk Review <i class="bi bi-arrow-right ms-1"></i>
            </button>
        </div>
    </form>

<?php endif; ?>

</div>
</div>
</div>
</div>

<script>
const guardianStudentPrefill = <?= json_encode($guardianStudentPrefill, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

function setEnrollmentField(form, name, value) {
    const checkboxNames = new Set([
        'permanent_same_as_current',
        'is_ip_community',
        'is_4ps_beneficiary',
        'learner_with_disability',
        'returning_learner',
        'transferee'
    ]);

    if (checkboxNames.has(name)) {
        const checkbox = form.querySelector(`input[type="checkbox"][name="${name}"]`);
        if (checkbox) {
            checkbox.checked = value === true || value === 1 || value === '1';
        }
        return;
    }

    const field = form.querySelector(`[name="${name}"]:not([type="hidden"])`);
    if (field) {
        field.value = value ?? '';
    }
}

function blankEnrollmentStudentData() {
    return {
        first_name: '',
        middle_name: '',
        last_name: '',
        extension_name: '',
        birthdate: '',
        gender: '',
        lrn: '',
        psa_birth_certificate_no: '',
        place_of_birth: '',
        mother_tongue: '',
        religion: '',
        current_house_street: '',
        current_barangay: '',
        current_city_municipality: '',
        current_province: '',
        current_country: 'Philippines',
        current_zip_code: '',
        permanent_same_as_current: true,
        permanent_house_street: '',
        permanent_barangay: '',
        permanent_city_municipality: '',
        permanent_province: '',
        permanent_country: 'Philippines',
        permanent_zip_code: '',
        father_first_name: '',
        father_middle_name: '',
        father_last_name: '',
        father_contact_number: '',
        mother_first_name: '',
        mother_middle_name: '',
        mother_maiden_last_name: '',
        mother_contact_number: '',
        is_ip_community: false,
        ip_group: '',
        is_4ps_beneficiary: false,
        four_ps_household_id: '',
        learner_with_disability: false,
        disability_type: '',
        returning_learner: false,
        transferee: false,
        last_grade_level_completed: '',
        last_school_year_completed: '',
        last_school_attended: '',
        previous_school_id: ''
    };
}

document.getElementById('existing-student-select')?.addEventListener('change', function () {
    const form = document.getElementById('enrollment-step-student');
    if (!form) return;

    const data = this.value !== '0'
        ? guardianStudentPrefill[this.value] || blankEnrollmentStudentData()
        : blankEnrollmentStudentData();

    Object.entries(data).forEach(([name, value]) => {
        if (name !== 'student_id' && name !== 'grade_level' && name !== 'section_id') {
            setEnrollmentField(form, name, value);
        }
    });
});

function refreshEnrollmentRequirementUploads() {
    const gradeSelect = document.querySelector('select[name="grade_level"]');
    const requirementCards = document.querySelectorAll('.requirement-upload');
    if (!gradeSelect || requirementCards.length === 0) {
        return;
    }

    const selectedGrade = String(gradeSelect.value || '').toLowerCase();
    const skipPrevious = ['preschool', 'pre-school', 'kindergarten', 'kinder'].includes(selectedGrade);

    requirementCards.forEach((card) => {
        const key = card.dataset.requirementKey || '';
        const input = card.querySelector('input[type="file"]');
        if (key === 'previous_school' && skipPrevious) {
            card.classList.add('d-none');
            if (input) {
                input.required = false;
                input.value = '';
            }
            return;
        }

        card.classList.remove('d-none');
        if (input) {
            input.required = true;
        }
    });
}

document.querySelector('select[name="grade_level"]')?.addEventListener('change', refreshEnrollmentRequirementUploads);
refreshEnrollmentRequirementUploads();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
