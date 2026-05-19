<?php
/**
 * Admin Students — combined student + guardian registration form.
 * Creating a student lets you simultaneously create or link a guardian.
 */

require_once __DIR__ . '/../includes/session-check.php';
requireRole(['admin', 'clerk']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo     = getDB();
$search  = trim($_GET['search'] ?? '');
$action  = $_GET['action'] ?? '';
$id      = (int)($_GET['id'] ?? 0);
$errors  = [];
$fieldErrors = [];

$isClerk = ($_SESSION['role'] ?? '') === 'clerk';
$readBool = static fn(string $key): bool => ($_POST[$key] ?? '0') === '1';
$addFieldError = static function (string $field, string $message) use (&$fieldErrors): void {
    if (!isset($fieldErrors[$field])) {
        $fieldErrors[$field] = $message;
    }
};
$hasFormErrors = static function () use (&$errors, &$fieldErrors): bool {
    return !empty($errors) || !empty($fieldErrors);
};
$fieldErrorHtml = static function (string $field) use (&$fieldErrors): string {
    if (empty($fieldErrors[$field])) {
        return '';
    }

    return '<span class="field-error text-danger small d-block mt-1">' . e($fieldErrors[$field]) . '</span>';
};
$fieldInvalidClass = static function (string $field) use (&$fieldErrors): string {
    return empty($fieldErrors[$field]) ? '' : ' is-invalid';
};

// Friendly labels for fields, used by the error summary and DB-error mapping.
$studentFieldLabels = [
    'last_name' => 'Last Name', 'first_name' => 'First Name', 'middle_name' => 'Middle Name',
    'extension_name' => 'Extension', 'birthdate' => 'Birthdate', 'gender' => 'Gender',
    'grade_level' => 'Grade Level', 'section_id' => 'Section', 'lrn' => 'LRN',
    'psa_birth_certificate_no' => 'PSA Birth Certificate No.', 'place_of_birth' => 'Place of Birth',
    'mother_tongue' => 'Mother Tongue', 'religion' => 'Religion',
    'current_house_street' => 'Current House / Street', 'current_barangay' => 'Current Barangay',
    'current_city_municipality' => 'Current City / Municipality', 'current_province' => 'Current Province',
    'current_country' => 'Current Country', 'current_zip_code' => 'Current ZIP Code',
    'permanent_house_street' => 'Permanent House / Street', 'permanent_barangay' => 'Permanent Barangay',
    'permanent_city_municipality' => 'Permanent City / Municipality', 'permanent_province' => 'Permanent Province',
    'permanent_country' => 'Permanent Country', 'permanent_zip_code' => 'Permanent ZIP Code',
    'father_first_name' => 'Father First Name', 'father_middle_name' => 'Father Middle Name',
    'father_last_name' => 'Father Last Name', 'father_contact_number' => 'Father Contact',
    'mother_first_name' => 'Mother First Name', 'mother_middle_name' => 'Mother Middle Name',
    'mother_maiden_last_name' => 'Mother Maiden Last Name', 'mother_contact_number' => 'Mother Contact',
    'ip_group' => 'IP Group', 'four_ps_household_id' => '4Ps Household ID',
    'disability_type' => 'Disability Type', 'last_grade_level_completed' => 'Last Grade Completed',
    'last_school_year_completed' => 'Last School Year', 'last_school_attended' => 'Last School Attended',
    'previous_school_id' => 'School ID',
    'permanent_same_as_current' => 'Permanent Address Same as Current',
    'is_ip_community' => 'Indigenous Peoples',
    'is_4ps_beneficiary' => '4Ps Beneficiary',
    'learner_with_disability' => 'Learner with Disability',
    'returning_learner' => 'Returning Learner',
    'transferee' => 'Transferee',
    'new_g_last_name' => 'Guardian Last Name', 'new_g_first_name' => 'Guardian First Name',
    'new_g_middle_name' => 'Guardian Middle Name', 'new_g_email' => 'Guardian Email',
    'new_g_contact' => 'Guardian Contact Number', 'new_g_relationship' => 'Guardian Relationship',
    'new_g_occupation' => 'Guardian Occupation', 'new_g_civil_status' => 'Guardian Civil Status',
    'new_g_nationality' => 'Guardian Nationality', 'new_g_religion' => 'Guardian Religion',
    'new_g_address' => 'Guardian Home Address', 'new_g_emergency_name' => 'Emergency Contact Name',
    'new_g_emergency_number' => 'Emergency Contact Number', 'existing_guardian_id' => 'Existing Guardian',
];
$fieldLabel = static function (string $field) use ($studentFieldLabels): string {
    return $studentFieldLabels[$field] ?? ucwords(str_replace('_', ' ', $field));
};
$studentBooleanFields = [
    'permanent_same_as_current',
    'is_ip_community',
    'is_4ps_beneficiary',
    'learner_with_disability',
    'returning_learner',
    'transferee',
];
$truthyFormValue = static function (mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim((string)$value)), ['1', 'true', 't', 'yes', 'on'], true);
};
$containsMarkup = static function (?string $value): bool {
    $value = (string)$value;
    return $value !== strip_tags($value)
        || str_contains($value, '<')
        || str_contains($value, '>')
        || preg_match('/&(?:lt|gt);/i', $value) === 1;
};
$plainTextOnlyMessage = static fn(string $label): string =>
    $label . ' cannot contain HTML tags such as <marquee>. Use plain text only.';
$validName = static function (string $value): bool {
    if ($value === '') {
        return true;
    }

    return preg_match("/^[\\p{L}\\p{M} .'-]+$/u", $value) === 1;
};
$validContact = static function (string $value): bool {
    if ($value === '') {
        return true;
    }

    return preg_match('/^[0-9+()\\-\\s]{7,20}$/', $value) === 1;
};

// ── Handle Delete ────────────────────────────────────────
if (!$isClerk && $action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $pdo->prepare("DELETE FROM students WHERE id = :id")->execute([':id' => $id]);
    auditLog('delete_student', 'students', $id);
    setFlash('success', 'Student deleted.');
    redirect(APP_URL . '/admin/admin-students.php');
}

// ── Handle Create / Update ───────────────────────────────
if (!$isClerk && in_array($action, ['create', 'edit']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    // Student fields
    $data = [
        'first_name'                    => trim($_POST['first_name'] ?? ''),
        'middle_name'                   => trim($_POST['middle_name'] ?? ''),
        'last_name'                     => trim($_POST['last_name'] ?? ''),
        'extension_name'                => trim($_POST['extension_name'] ?? ''),
        'birthdate'                     => trim($_POST['birthdate'] ?? ''),
        'gender'                        => trim($_POST['gender'] ?? ''),
        'grade_level'                   => trim($_POST['grade_level'] ?? ''),
        'section_id'                    => (int)($_POST['section_id'] ?? 0) ?: null,
        'lrn'                           => trim($_POST['lrn'] ?? ''),
        'psa_birth_certificate_no'      => trim($_POST['psa_birth_certificate_no'] ?? ''),
        'place_of_birth'                => trim($_POST['place_of_birth'] ?? ''),
        'mother_tongue'                 => trim($_POST['mother_tongue'] ?? ''),
        'religion'                      => trim($_POST['religion'] ?? ''),
        'current_house_street'          => trim($_POST['current_house_street'] ?? ''),
        'current_barangay'              => trim($_POST['current_barangay'] ?? ''),
        'current_city_municipality'     => trim($_POST['current_city_municipality'] ?? ''),
        'current_province'              => trim($_POST['current_province'] ?? ''),
        'current_country'               => trim($_POST['current_country'] ?? 'Philippines') ?: 'Philippines',
        'current_zip_code'              => trim($_POST['current_zip_code'] ?? ''),
        'permanent_same_as_current'     => $readBool('permanent_same_as_current'),
        'permanent_house_street'        => trim($_POST['permanent_house_street'] ?? ''),
        'permanent_barangay'            => trim($_POST['permanent_barangay'] ?? ''),
        'permanent_city_municipality'   => trim($_POST['permanent_city_municipality'] ?? ''),
        'permanent_province'            => trim($_POST['permanent_province'] ?? ''),
        'permanent_country'             => trim($_POST['permanent_country'] ?? 'Philippines') ?: 'Philippines',
        'permanent_zip_code'            => trim($_POST['permanent_zip_code'] ?? ''),
        'father_first_name'             => trim($_POST['father_first_name'] ?? ''),
        'father_middle_name'            => trim($_POST['father_middle_name'] ?? ''),
        'father_last_name'              => trim($_POST['father_last_name'] ?? ''),
        'father_contact_number'         => trim($_POST['father_contact_number'] ?? ''),
        'mother_first_name'             => trim($_POST['mother_first_name'] ?? ''),
        'mother_middle_name'            => trim($_POST['mother_middle_name'] ?? ''),
        'mother_maiden_last_name'       => trim($_POST['mother_maiden_last_name'] ?? ''),
        'mother_contact_number'         => trim($_POST['mother_contact_number'] ?? ''),
        'is_ip_community'               => $readBool('is_ip_community'),
        'ip_group'                      => trim($_POST['ip_group'] ?? ''),
        'is_4ps_beneficiary'            => $readBool('is_4ps_beneficiary'),
        'four_ps_household_id'          => trim($_POST['four_ps_household_id'] ?? ''),
        'learner_with_disability'       => $readBool('learner_with_disability'),
        'disability_type'               => trim($_POST['disability_type'] ?? ''),
        'returning_learner'             => $readBool('returning_learner'),
        'transferee'                    => $readBool('transferee'),
        'last_grade_level_completed'    => trim($_POST['last_grade_level_completed'] ?? ''),
        'last_school_year_completed'    => trim($_POST['last_school_year_completed'] ?? ''),
        'last_school_attended'          => trim($_POST['last_school_attended'] ?? ''),
        'previous_school_id'            => trim($_POST['previous_school_id'] ?? ''),
    ];

    // Guardian mode: 'existing' | 'new' | 'none'
    $guardianMode    = trim($_POST['guardian_mode'] ?? 'none');
    $existingGid     = (int)($_POST['existing_guardian_id'] ?? 0) ?: null;
    $newGFirstName   = trim($_POST['new_g_first_name'] ?? '');
    $newGMiddleName  = trim($_POST['new_g_middle_name'] ?? '');
    $newGLastName    = trim($_POST['new_g_last_name'] ?? '');
    $newGEmail       = normalizeEmailAddress($_POST['new_g_email'] ?? '');
    $newGContact     = trim($_POST['new_g_contact'] ?? '');
    $newGAddress     = trim($_POST['new_g_address'] ?? '');
    $newGRel         = trim($_POST['new_g_relationship'] ?? '');
    $newGOccupation  = trim($_POST['new_g_occupation'] ?? '');
    $newGCivilStatus = trim($_POST['new_g_civil_status'] ?? '');
    $newGNationality = trim($_POST['new_g_nationality'] ?? 'Filipino') ?: 'Filipino';
    $newGReligion    = trim($_POST['new_g_religion'] ?? '');
    $newGEmergName   = trim($_POST['new_g_emergency_name'] ?? '');
    $newGEmergNumber = trim($_POST['new_g_emergency_number'] ?? '');

    $validGuardianModes = ['existing', 'new', 'none'];
    if (!in_array($guardianMode, $validGuardianModes, true)) {
        $errors[] = 'Invalid guardian selection.';
        $guardianMode = 'new';
    }

    if (empty($data['last_name'])) $addFieldError('last_name', 'Last name is required.');
    if (empty($data['first_name'])) $addFieldError('first_name', 'First name is required.');
    if (empty($data['birthdate'])) $addFieldError('birthdate', 'Birthdate is required.');
    if (empty($data['gender'])) $addFieldError('gender', 'Gender is required.');
    if (empty($data['grade_level'])) {
        $addFieldError('grade_level', 'Grade level is required.');
    } elseif (!array_key_exists($data['grade_level'], basicEducationGradeLevels())) {
        $addFieldError('grade_level', 'Choose a valid grade level from the list.');
    }

    if ($data['birthdate'] !== '') {
        $birthdate = DateTime::createFromFormat('Y-m-d', $data['birthdate']);
        if (!$birthdate || $birthdate->format('Y-m-d') !== $data['birthdate']) {
            $addFieldError('birthdate', 'Enter a valid birthdate.');
        }
    }

    if ($data['gender'] !== '' && !in_array($data['gender'], ['male', 'female', 'other'], true)) {
        $addFieldError('gender', 'Choose a valid gender from the list.');
    }

    if ($data['section_id'] !== null) {
        $sectionCheck = $pdo->prepare("SELECT 1 FROM sections WHERE id = :id LIMIT 1");
        $sectionCheck->execute([':id' => $data['section_id']]);
        if (!$sectionCheck->fetchColumn()) {
            $addFieldError('section_id', 'Choose a valid section from the list.');
        }
    }

    if ($guardianMode === 'new') {
        if (empty($newGLastName))  $addFieldError('new_g_last_name', 'Guardian last name is required.');
        if (empty($newGFirstName)) $addFieldError('new_g_first_name', 'Guardian first name is required.');
        if (empty($newGEmail) || !filter_var($newGEmail, FILTER_VALIDATE_EMAIL))
            $addFieldError('new_g_email', 'Enter a valid guardian email address.');
        if (empty($newGContact)) $addFieldError('new_g_contact', 'Guardian contact number is required.');
        if (empty($newGRel)) $addFieldError('new_g_relationship', 'Guardian relationship is required.');
        if ($newGRel !== '' && !in_array($newGRel, ['Parent','Guardian','Sibling','Grandparent','Aunt/Uncle','Other'], true)) {
            $addFieldError('new_g_relationship', 'Choose a valid guardian relationship from the list.');
        }
        if ($newGCivilStatus !== '' && !in_array($newGCivilStatus, ['single','married','widowed','separated','others'], true)) {
            $addFieldError('new_g_civil_status', 'Choose a valid civil status from the list.');
        }
    } elseif ($guardianMode === 'existing' && !$existingGid) {
        $addFieldError('existing_guardian_id', 'Please select an existing guardian.');
    } elseif ($guardianMode === 'existing') {
        $guardianCheck = $pdo->prepare("SELECT 1 FROM guardians WHERE id = :id LIMIT 1");
        $guardianCheck->execute([':id' => $existingGid]);
        if (!$guardianCheck->fetchColumn()) {
            $addFieldError('existing_guardian_id', 'Selected guardian was not found. Please choose another guardian.');
        }
    }

    if ($data['lrn'] !== '' && !preg_match('/^\d{12}$/', $data['lrn'])) {
        $addFieldError('lrn', 'LRN must be exactly 12 digits.');
    }

    if ($data['permanent_same_as_current']) {
        $data['permanent_house_street'] = $data['current_house_street'];
        $data['permanent_barangay'] = $data['current_barangay'];
        $data['permanent_city_municipality'] = $data['current_city_municipality'];
        $data['permanent_province'] = $data['current_province'];
        $data['permanent_country'] = $data['current_country'];
        $data['permanent_zip_code'] = $data['current_zip_code'];
    }

    $plainTextStudentFields = [
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'extension_name' => 'Extension',
        'psa_birth_certificate_no' => 'PSA birth certificate number',
        'place_of_birth' => 'Place of birth',
        'mother_tongue' => 'Mother tongue',
        'religion' => 'Religion',
        'current_house_street' => 'Current house/street',
        'current_barangay' => 'Current barangay',
        'current_city_municipality' => 'Current city/municipality',
        'current_province' => 'Current province',
        'current_country' => 'Current country',
        'current_zip_code' => 'Current ZIP code',
        'permanent_house_street' => 'Permanent house/street',
        'permanent_barangay' => 'Permanent barangay',
        'permanent_city_municipality' => 'Permanent city/municipality',
        'permanent_province' => 'Permanent province',
        'permanent_country' => 'Permanent country',
        'permanent_zip_code' => 'Permanent ZIP code',
        'father_first_name' => 'Father first name',
        'father_middle_name' => 'Father middle name',
        'father_last_name' => 'Father last name',
        'father_contact_number' => 'Father contact number',
        'mother_first_name' => 'Mother first name',
        'mother_middle_name' => 'Mother middle name',
        'mother_maiden_last_name' => 'Mother maiden last name',
        'mother_contact_number' => 'Mother contact number',
        'ip_group' => 'IP group',
        'four_ps_household_id' => '4Ps household ID',
        'disability_type' => 'Disability type',
        'last_grade_level_completed' => 'Last grade completed',
        'last_school_year_completed' => 'Last school year',
        'last_school_attended' => 'Last school attended',
        'previous_school_id' => 'School ID',
    ];
    foreach ($plainTextStudentFields as $field => $label) {
        if (($data[$field] ?? '') !== '' && $containsMarkup($data[$field])) {
            $addFieldError($field, $plainTextOnlyMessage($label));
        }
    }

    $nameFields = [
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'father_first_name' => 'Father first name',
        'father_middle_name' => 'Father middle name',
        'father_last_name' => 'Father last name',
        'mother_first_name' => 'Mother first name',
        'mother_middle_name' => 'Mother middle name',
        'mother_maiden_last_name' => 'Mother maiden last name',
    ];
    foreach ($nameFields as $field => $label) {
        if (($data[$field] ?? '') !== '' && !$containsMarkup($data[$field]) && !$validName($data[$field])) {
            $addFieldError($field, $label . ' should contain letters, spaces, hyphens, apostrophes, or periods only.');
        }
    }

    if ($data['extension_name'] !== '') {
        $allowedExtensions = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v', 'vi'];
        if (!in_array(strtolower($data['extension_name']), $allowedExtensions, true)) {
            $addFieldError('extension_name', 'Use a valid extension such as Jr., Sr., II, III, IV, V, or leave it blank.');
        }
    }

    foreach ([
        'father_contact_number' => 'Father contact number',
        'mother_contact_number' => 'Mother contact number',
    ] as $field => $label) {
        if (($data[$field] ?? '') !== '' && !$containsMarkup($data[$field]) && !$validContact($data[$field])) {
            $addFieldError($field, $label . ' should be 7 to 20 characters and use only numbers, spaces, +, -, or parentheses.');
        }
    }

    if ($guardianMode === 'new') {
        foreach ([
            'new_g_first_name' => [$newGFirstName, 'Guardian first name'],
            'new_g_middle_name' => [$newGMiddleName, 'Guardian middle name'],
            'new_g_last_name' => [$newGLastName, 'Guardian last name'],
            'new_g_contact' => [$newGContact, 'Guardian contact number'],
            'new_g_address' => [$newGAddress, 'Guardian home address'],
            'new_g_relationship' => [$newGRel, 'Guardian relationship'],
            'new_g_occupation' => [$newGOccupation, 'Guardian occupation'],
            'new_g_civil_status' => [$newGCivilStatus, 'Guardian civil status'],
            'new_g_nationality' => [$newGNationality, 'Guardian nationality'],
            'new_g_religion' => [$newGReligion, 'Guardian religion'],
            'new_g_emergency_name' => [$newGEmergName, 'Emergency contact name'],
            'new_g_emergency_number' => [$newGEmergNumber, 'Emergency contact number'],
        ] as $field => [$value, $label]) {
            if ($value !== '' && $containsMarkup($value)) {
                $addFieldError($field, $plainTextOnlyMessage($label));
            }
        }

        foreach ([
            'new_g_first_name' => [$newGFirstName, 'Guardian first name'],
            'new_g_middle_name' => [$newGMiddleName, 'Guardian middle name'],
            'new_g_last_name' => [$newGLastName, 'Guardian last name'],
            'new_g_emergency_name' => [$newGEmergName, 'Emergency contact name'],
        ] as $field => [$value, $label]) {
            if ($value !== '' && !$containsMarkup($value) && !$validName($value)) {
                $addFieldError($field, $label . ' should contain letters, spaces, hyphens, apostrophes, or periods only.');
            }
        }

        foreach ([
            'new_g_contact' => [$newGContact, 'Guardian contact number'],
            'new_g_emergency_number' => [$newGEmergNumber, 'Emergency contact number'],
        ] as $field => [$value, $label]) {
            if ($value !== '' && !$containsMarkup($value) && !$validContact($value)) {
                $addFieldError($field, $label . ' should be 7 to 20 characters and use only numbers, spaces, +, -, or parentheses.');
            }
        }
    }

    $studentDbValue = static function (string $key, mixed $value) use ($studentBooleanFields, $truthyFormValue): mixed {
        // PDO array-binds PHP false as an empty string for pgsql, which breaks
        // PostgreSQL boolean columns. Coerce known checkbox fields explicitly.
        if (in_array($key, $studentBooleanFields, true)) {
            return $truthyFormValue($value) ? 'true' : 'false';
        }
        if (is_int($value) || $value === null) {
            return $value;
        }
        $value = trim((string)$value);
        if (in_array($key, ['first_name', 'middle_name', 'last_name'], true)) {
            return $value;
        }
        if (in_array($key, ['current_country', 'permanent_country'], true)) {
            return $value !== '' ? $value : 'Philippines';
        }
        return $value === '' ? null : $value;
    };

    if (!$hasFormErrors() && $guardianMode === 'new') {
        try {
            $existingUser = findUserByEmail($pdo, $newGEmail);
            $guardianForEmail = $existingUser ? findGuardianProfileByUserId($pdo, (int)$existingUser['id']) : null;
            $duplicateGuardian = findDuplicateGuardianProfile(
                $pdo,
                $newGFirstName,
                $newGLastName,
                $newGContact,
                $newGEmail,
                $guardianForEmail ? (int)$guardianForEmail['id'] : 0
            );

            if ($duplicateGuardian) {
                $addFieldError('new_g_email', 'A guardian profile with the same name and contact/email already exists: '
                    . format_name($duplicateGuardian['first_name'] ?? '', $duplicateGuardian['last_name'] ?? '')
                    . ' (' . ($duplicateGuardian['email'] ?? 'no email') . '). Please use Link Existing Guardian.');
            }
        } catch (Exception $e) {
            error_log('Guardian duplicate check error: ' . $e->getMessage());
            $errors[] = 'Unable to check for duplicate guardians. Please try again.';
        }
    }

    if (!$hasFormErrors()) {
        try {
            $duplicate = findDuplicateStudent(
                $pdo,
                $data['first_name'],
                $data['last_name'],
                $data['lrn'],
                $action === 'edit' ? $id : 0
            );

            if ($duplicate) {
                $duplicateName = format_name($duplicate['first_name'] ?? '', $duplicate['last_name'] ?? '');
                if (($duplicate['duplicate_type'] ?? '') === 'lrn') {
                    $addFieldError('lrn', 'This LRN is already assigned to ' . $duplicateName . '.');
                } else {
                    $addFieldError('last_name', 'A student with the same full name already exists: ' . $duplicateName . '.');
                }
            }
        } catch (Exception $e) {
            error_log('Student duplicate check error: ' . $e->getMessage());
            $errors[] = 'Unable to check for duplicate students. Please try again.';
        }
    }

    if (!$hasFormErrors()) {
        try {
            $pdo->beginTransaction();

            // Resolve guardian_id
            $resolvedGid = null;
            if ($guardianMode === 'existing' && $existingGid) {
                $gStmt = $pdo->prepare("SELECT id, user_id FROM guardians WHERE id = :gid LIMIT 1");
                $gStmt->execute([':gid' => $existingGid]);
                $existingGuardian = $gStmt->fetch();
                if ($existingGuardian) {
                    $resolvedGid = (int)$existingGuardian['id'];
                    ensureUserRole($pdo, (int)$existingGuardian['user_id'], 'guardian');
                } else {
                    throw new RuntimeException('Selected guardian was not found.');
                }
            } elseif ($guardianMode === 'new') {
                // Reuse existing user account if email already exists
                $existingUser = findUserByEmail($pdo, $newGEmail);

                if ($existingUser) {
                    $uid = (int)$existingUser['id'];
                    ensureUserRole($pdo, $uid, 'guardian');
                    // Check if they already have a guardian profile
                    $existingG = findGuardianProfileByUserId($pdo, $uid);
                    if ($existingG) {
                        $resolvedGid = (int)$existingG['id'];
                    } else {
                        $gIns = $pdo->prepare("
                            INSERT INTO guardians
                                (user_id, first_name, middle_name, last_name, contact_number, address, relationship_to_student,
                                 occupation, civil_status, nationality, religion, emergency_contact_name, emergency_contact_number,
                                 data_privacy_consent, data_privacy_consented_at)
                            VALUES
                                (:uid, :fn, :mn, :ln, :c, :addr, :r, :occ, :cs, :nat, :religion, :en, :ec, TRUE, NOW())
                            RETURNING id
                        ");
                        $gIns->execute([
                            ':uid' => $uid,
                            ':fn' => $newGFirstName,
                            ':mn' => $newGMiddleName,
                            ':ln' => $newGLastName,
                            ':c' => nullIfBlank($newGContact),
                            ':addr' => nullIfBlank($newGAddress),
                            ':r' => nullIfBlank($newGRel),
                            ':occ' => nullIfBlank($newGOccupation),
                            ':cs' => nullIfBlank($newGCivilStatus),
                            ':nat' => $newGNationality,
                            ':religion' => nullIfBlank($newGReligion),
                            ':en' => nullIfBlank($newGEmergName),
                            ':ec' => nullIfBlank($newGEmergNumber),
                        ]);
                        $resolvedGid = (int)$gIns->fetchColumn();
                    }
                } else {
                    // Create new user + guardian
                    $hash = password_hash('Guardian@1234', PASSWORD_BCRYPT);
                    $uIns = $pdo->prepare("INSERT INTO users (email, password_hash, role, is_active, created_at) VALUES (:e, :h, 'guardian', 1, NOW()) RETURNING id");
                    $uIns->execute([':e' => $newGEmail, ':h' => $hash]);
                    $uid = (int)$uIns->fetchColumn();

                    // Seed user_roles
                    ensureUserRole($pdo, $uid, 'guardian');

                    $gIns = $pdo->prepare("
                        INSERT INTO guardians
                            (user_id, first_name, middle_name, last_name, contact_number, address, relationship_to_student,
                             occupation, civil_status, nationality, religion, emergency_contact_name, emergency_contact_number,
                             data_privacy_consent, data_privacy_consented_at)
                        VALUES
                            (:uid, :fn, :mn, :ln, :c, :addr, :r, :occ, :cs, :nat, :religion, :en, :ec, TRUE, NOW())
                        RETURNING id
                    ");
                    $gIns->execute([
                        ':uid' => $uid,
                        ':fn' => $newGFirstName,
                        ':mn' => $newGMiddleName,
                        ':ln' => $newGLastName,
                        ':c' => nullIfBlank($newGContact),
                        ':addr' => nullIfBlank($newGAddress),
                        ':r' => nullIfBlank($newGRel),
                        ':occ' => nullIfBlank($newGOccupation),
                        ':cs' => nullIfBlank($newGCivilStatus),
                        ':nat' => $newGNationality,
                        ':religion' => nullIfBlank($newGReligion),
                        ':en' => nullIfBlank($newGEmergName),
                        ':ec' => nullIfBlank($newGEmergNumber),
                    ]);
                    $resolvedGid = (int)$gIns->fetchColumn();

                    auditLog('create_guardian', 'guardians', $resolvedGid);
                }
            }

            if ($action === 'create') {
                $insertData = ['guardian_id' => $resolvedGid] + $data;
                $columns = array_keys($insertData);
                $placeholders = array_map(static fn(string $col): string => ':' . $col, $columns);
                $stmt = $pdo->prepare("
                    INSERT INTO students (" . implode(', ', $columns) . ")
                    VALUES (" . implode(', ', $placeholders) . ")
                    RETURNING id
                ");
                $params = [];
                foreach ($insertData as $key => $value) {
                    $params[':' . $key] = $key === 'guardian_id' ? $value : $studentDbValue($key, $value);
                }
                $stmt->execute($params);
                $newStudentId = (int)$stmt->fetchColumn();
                auditLog('create_student', 'students', $newStudentId);

                $msg = 'Student created.';
                if ($guardianMode === 'new') {
                    $msg .= ' Guardian account created with default password: Guardian@1234';
                }
                $pdo->commit();
                setFlash('success', $msg);
            } else {
                // edit: update student, optionally re-link guardian
                $setParts = [];
                $params = [':id' => $id, ':guardian_id' => $resolvedGid];
                foreach ($data as $key => $value) {
                    $param = ':' . $key;
                    $setParts[] = "{$key} = {$param}";
                    $params[$param] = $studentDbValue($key, $value);
                }
                $setParts[] = 'guardian_id = COALESCE(:guardian_id, guardian_id)';
                $stmt = $pdo->prepare("
                    UPDATE students
                    SET " . implode(', ', $setParts) . "
                    WHERE id = :id
                ");
                $stmt->execute($params);
                auditLog('update_student', 'students', $id);
                $pdo->commit();
                setFlash('success', 'Student updated.');
            }

            redirect(APP_URL . '/admin/admin-students.php');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $dbMessage = $e->getMessage();
            error_log('Student save error: ' . $dbMessage);

            if (stripos($dbMessage, 'uq_students_lrn') !== false || stripos($dbMessage, 'chk_students_lrn') !== false) {
                $addFieldError('lrn', 'LRN must be unique and exactly 12 digits.');
            } elseif (stripos($dbMessage, 'uq_students_identity_name_birthdate') !== false) {
                $addFieldError('last_name', 'A student with the same name and birthdate already exists.');
                $addFieldError('birthdate', 'Check the birthdate for the possible duplicate student.');
            } elseif (stripos($dbMessage, 'users_email') !== false || stripos($dbMessage, 'email_lower') !== false) {
                $addFieldError('new_g_email', 'This email is already used by another account.');
            } elseif (stripos($dbMessage, 'uq_guardians_user_id') !== false) {
                $addFieldError('new_g_email', 'This email already has a guardian profile. Use Link Existing Guardian.');
            } elseif (stripos($dbMessage, 'Selected guardian was not found') !== false) {
                $addFieldError('existing_guardian_id', 'Selected guardian was not found. Please choose another guardian.');
            } else {
                $sqlState = ($e instanceof PDOException) ? (string)$e->getCode() : '';
                $col = null;
                if (preg_match('/column "([^"]+)"/i', $dbMessage, $m)) {
                    $col = $m[1];
                } elseif (preg_match('/Key \(([^)]+)\)=/i', $dbMessage, $m)) {
                    $col = trim(explode(',', $m[1])[0]);
                }
                $field = null;
                if ($col !== null) {
                    if (isset($studentFieldLabels[$col])) {
                        $field = $col;
                    } elseif ($col === 'email') {
                        $field = 'new_g_email';
                    }
                }
                $label = $field !== null ? $fieldLabel($field) : null;

                if ($sqlState === '23502') {
                    $msg = $label
                        ? $label . ' is required and cannot be left blank.'
                        : 'A required value was missing. Please review the form and try again.';
                } elseif ($sqlState === '23505') {
                    $msg = $label
                        ? 'Another record already uses this ' . $label . '. Enter a different value.'
                        : 'A record with the same details already exists.';
                } elseif ($sqlState === '23503') {
                    $msg = $label
                        ? 'The selected ' . $label . ' no longer exists. Please choose another.'
                        : 'A selected related record no longer exists. Please reselect and try again.';
                } elseif ($sqlState === '22001') {
                    $max = preg_match('/character varying\((\d+)\)/i', $dbMessage, $mm) ? (int)$mm[1] : 0;
                    $msg = ($label ?: 'One of the fields')
                        . ' is too long' . ($max ? ' (maximum ' . $max . ' characters)' : '') . '. Please shorten it.';
                } elseif ($sqlState === '22P02') {
                    $type = preg_match('/invalid input syntax for type (\w+)/i', $dbMessage, $mt) ? $mt[1] : '';
                    if ($type === 'boolean') {
                        $msg = 'One of the checkbox fields has an invalid value. Please refresh the page and try again.';
                    } else {
                        $msg = ($label ? $label . ' has' : 'One of the fields has')
                            . ' an invalid value' . ($type ? ' (expected a valid ' . $type . ')' : '') . '. Please correct it.';
                    }
                } else {
                    $msg = 'The record could not be saved'
                        . ($sqlState !== '' ? ' (database error ' . $sqlState . ')' : '')
                        . '. Please review your entries; if it keeps happening, contact the administrator.';
                }

                if ($field !== null) {
                    $addFieldError($field, $msg);
                } else {
                    $errors[] = $msg;
                }
            }
        }
    }
}

// ── Fetch student for edit ───────────────────────────────
$editStudent  = null;
$editGuardian = null;
if ($action === 'edit' && $id) {
    $stmt = $pdo->prepare("
        SELECT s.*,
               g.id AS g_id,
               g.first_name AS g_first_name,
               g.middle_name AS g_middle_name,
               g.last_name AS g_last_name,
               g.contact_number AS g_contact,
               g.address AS g_address,
               g.relationship_to_student AS g_relationship,
               g.occupation AS g_occupation,
               g.civil_status AS g_civil_status,
               g.nationality AS g_nationality,
               g.religion AS g_religion,
               g.emergency_contact_name AS g_emergency_name,
               g.emergency_contact_number AS g_emergency_number,
               u.email AS g_email
        FROM students s
        LEFT JOIN guardians g ON s.guardian_id = g.id
        LEFT JOIN users u ON g.user_id = u.id
        WHERE s.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $editStudent = $stmt->fetch();
}

if (
    !$isClerk
    && in_array($action, ['create', 'edit'], true)
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && $hasFormErrors()
) {
    $editStudent = array_merge($editStudent ?: [], $data ?? []);
    $editStudent['_guardian_mode'] = $guardianMode ?? 'new';
    $editStudent['_existing_guardian_id'] = $existingGid ?? 0;
    $editStudent['g_first_name'] = $newGFirstName ?? '';
    $editStudent['g_middle_name'] = $newGMiddleName ?? '';
    $editStudent['g_last_name'] = $newGLastName ?? '';
    $editStudent['g_email'] = $newGEmail ?? '';
    $editStudent['g_contact'] = $newGContact ?? '';
    $editStudent['g_address'] = $newGAddress ?? '';
    $editStudent['g_relationship'] = $newGRel ?? '';
    $editStudent['g_occupation'] = $newGOccupation ?? '';
    $editStudent['g_civil_status'] = $newGCivilStatus ?? '';
    $editStudent['g_nationality'] = $newGNationality ?? 'Filipino';
    $editStudent['g_religion'] = $newGReligion ?? '';
    $editStudent['g_emergency_name'] = $newGEmergName ?? '';
    $editStudent['g_emergency_number'] = $newGEmergNumber ?? '';
    $editStudent['g_id'] = ($guardianMode ?? '') === 'existing' ? ($existingGid ?? 0) : ($editStudent['g_id'] ?? null);
}

// ── List with search & pagination ────────────────────────
$where  = '';
$params = [];
if ($search !== '') {
    $where = "WHERE s.last_name ILIKE :search OR s.first_name ILIKE :search2 OR s.middle_name ILIKE :search4 OR s.lrn ILIKE :search3 OR s.psa_birth_certificate_no ILIKE :search5";
    $params[':search']  = "{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':search3'] = "%{$search}%";
    $params[':search4'] = "%{$search}%";
    $params[':search5'] = "%{$search}%";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s {$where}");
$countStmt->execute($params);
[$offset, $limit, $page, $totalPages] = paginate((int)$countStmt->fetchColumn());

$stmt = $pdo->prepare("
    SELECT s.*,
           CASE
               WHEN g.first_name = '' THEN g.last_name
               ELSE g.last_name || ', ' || g.first_name || CASE WHEN COALESCE(g.middle_name, '') <> '' THEN ' ' || g.middle_name ELSE '' END
           END AS guardian_name,
           g.contact_number AS guardian_contact,
           sec.name AS section_name
    FROM students s
    LEFT JOIN guardians g   ON s.guardian_id = g.id
    LEFT JOIN sections  sec ON s.section_id  = sec.id
    {$where}
    ORDER BY s.last_name ASC, s.first_name ASC
    LIMIT {$limit} OFFSET {$offset}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// Dropdowns
$sections  = $pdo->query("SELECT id, name, grade_level FROM sections ORDER BY grade_level, name")->fetchAll();
$guardians = $pdo->query("
    SELECT g.id, g.first_name, g.middle_name, g.last_name, g.contact_number, g.relationship_to_student, u.email
    FROM guardians g
    JOIN users u ON g.user_id = u.id
    ORDER BY g.last_name, g.first_name
")->fetchAll();

$pageTitle = 'Manage Students';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-md-6">
        <h4 class="fw-bold"><i class="bi bi-people me-2"></i>Students</h4>
    </div>
    <div class="col-md-6 text-md-end">
        <?php if (!$isClerk): ?>
        <a href="?action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Add Student</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors) || !empty($fieldErrors)): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Please fix the following:</div>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
            <?php foreach ($fieldErrors as $f => $msg): ?>
                <li><a href="#" class="error-jump alert-link fw-semibold" data-focus-field="<?= e($f) ?>"><?= e($fieldLabel($f)) ?></a>: <?= e($msg) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$isClerk && in_array($action, ['create', 'edit'])): ?>
<div class="card mb-4">
    <div class="card-header bg-white fw-bold">
        <i class="bi bi-person-plus me-2"></i><?= e($action === 'create' ? 'Add New Student' : 'Edit Student') ?>
    </div>
    <div class="card-body">
        <form method="POST" id="student-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

            <!-- Sectioned into tabs so the long form is easier to navigate -->
            <ul class="nav nav-tabs student-form-tabs mb-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-info" data-bs-toggle="tab" data-bs-target="#pane-info" type="button" role="tab" aria-controls="pane-info" aria-selected="true">Student Info</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-address" data-bs-toggle="tab" data-bs-target="#pane-address" type="button" role="tab" aria-controls="pane-address" aria-selected="false">Address</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-parents" data-bs-toggle="tab" data-bs-target="#pane-parents" type="button" role="tab" aria-controls="pane-parents" aria-selected="false">Parents</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-background" data-bs-toggle="tab" data-bs-target="#pane-background" type="button" role="tab" aria-controls="pane-background" aria-selected="false">Background</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-guardian" data-bs-toggle="tab" data-bs-target="#pane-guardian" type="button" role="tab" aria-controls="pane-guardian" aria-selected="false">Guardian</button>
                </li>
            </ul>

            <div class="tab-content">
            <!-- ── Student Information ─────────────────── -->
            <div class="tab-pane fade show active" id="pane-info" role="tabpanel" aria-labelledby="tab-info">
            <h6 class="fw-semibold text-primary mb-3">Student Information</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('last_name') ?>" name="last_name"
                           value="<?= e($editStudent['last_name'] ?? '') ?>" required>
                    <?= $fieldErrorHtml('last_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('first_name') ?>" name="first_name"
                           value="<?= e($editStudent['first_name'] ?? '') ?>" required>
                    <?= $fieldErrorHtml('first_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('middle_name') ?>" name="middle_name"
                           value="<?= e($editStudent['middle_name'] ?? '') ?>">
                    <?= $fieldErrorHtml('middle_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Extension</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('extension_name') ?>" name="extension_name"
                           value="<?= e($editStudent['extension_name'] ?? '') ?>" placeholder="Jr., III">
                    <?= $fieldErrorHtml('extension_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Birthdate <span class="text-danger">*</span></label>
                    <input type="date" class="form-control<?= $fieldInvalidClass('birthdate') ?>" name="birthdate"
                           value="<?= e($editStudent['birthdate'] ?? '') ?>" required>
                    <?= $fieldErrorHtml('birthdate') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                    <select class="form-select<?= $fieldInvalidClass('gender') ?>" name="gender" required>
                        <option value="">Select...</option>
                        <?php foreach (['male','female','other'] as $g): ?>
                            <option value="<?= e($g) ?>" <?= e(($editStudent['gender'] ?? '') === $g ? 'selected' : '') ?>><?= e(ucfirst($g)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $fieldErrorHtml('gender') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Grade Level <span class="text-danger">*</span></label>
                    <select class="form-select<?= $fieldInvalidClass('grade_level') ?>" name="grade_level" id="studentGradeLevel" required>
                        <option value="">Select...</option>
                        <?php foreach (basicEducationGradeLevels() as $gl => $label): ?>
                            <option value="<?= e($gl) ?>" <?= e(($editStudent['grade_level'] ?? '') == $gl ? 'selected' : '') ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $fieldErrorHtml('grade_level') ?>
                </div>
                <div class="col-md-5 mb-3">
                    <label class="form-label">Section</label>
                    <select class="form-select<?= $fieldInvalidClass('section_id') ?>" name="section_id" id="studentSectionSelect">
                        <option value="0">None / To be assigned</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?= (int)$sec['id'] ?>" data-grade="<?= e((string)$sec['grade_level']) ?>" <?= e(($editStudent['section_id'] ?? 0) == $sec['id'] ? 'selected' : '') ?>><?= e($sec['name']) ?> (<?= e(formatGradeLevel((string)$sec['grade_level'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Only sections for the selected grade level are shown.</div>
                    <?= $fieldErrorHtml('section_id') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">LRN</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('lrn') ?>" name="lrn"
                           value="<?= e($editStudent['lrn'] ?? '') ?>"
                           maxlength="12" placeholder="12-digit LRN">
                    <?= $fieldErrorHtml('lrn') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">PSA Birth Certificate No.</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('psa_birth_certificate_no') ?>" name="psa_birth_certificate_no"
                           value="<?= e($editStudent['psa_birth_certificate_no'] ?? '') ?>">
                    <?= $fieldErrorHtml('psa_birth_certificate_no') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Place of Birth</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('place_of_birth') ?>" name="place_of_birth"
                           value="<?= e($editStudent['place_of_birth'] ?? '') ?>">
                    <?= $fieldErrorHtml('place_of_birth') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Mother Tongue</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('mother_tongue') ?>" name="mother_tongue"
                           value="<?= e($editStudent['mother_tongue'] ?? '') ?>">
                    <?= $fieldErrorHtml('mother_tongue') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Religion</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('religion') ?>" name="religion"
                           value="<?= e($editStudent['religion'] ?? '') ?>">
                    <?= $fieldErrorHtml('religion') ?>
                </div>
            </div>
            </div><!-- /pane-info -->

            <div class="tab-pane fade" id="pane-address" role="tabpanel" aria-labelledby="tab-address">
            <h6 class="fw-semibold text-primary mb-3">Student Address</h6>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Current House / Street</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('current_house_street') ?>" name="current_house_street"
                           value="<?= e($editStudent['current_house_street'] ?? '') ?>">
                    <?= $fieldErrorHtml('current_house_street') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Current Barangay</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('current_barangay') ?>" name="current_barangay"
                           value="<?= e($editStudent['current_barangay'] ?? '') ?>">
                    <?= $fieldErrorHtml('current_barangay') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">City / Municipality</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('current_city_municipality') ?>" name="current_city_municipality"
                           value="<?= e($editStudent['current_city_municipality'] ?? '') ?>">
                    <?= $fieldErrorHtml('current_city_municipality') ?>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">ZIP Code</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('current_zip_code') ?>" name="current_zip_code"
                           value="<?= e($editStudent['current_zip_code'] ?? '') ?>">
                    <?= $fieldErrorHtml('current_zip_code') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Province</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('current_province') ?>" name="current_province"
                           value="<?= e($editStudent['current_province'] ?? '') ?>">
                    <?= $fieldErrorHtml('current_province') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Country</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('current_country') ?>" name="current_country"
                           value="<?= e($editStudent['current_country'] ?? 'Philippines') ?>">
                    <?= $fieldErrorHtml('current_country') ?>
                </div>
                <div class="col-md-4 mb-3 d-flex align-items-end">
                    <div class="form-check">
                        <input type="hidden" name="permanent_same_as_current" value="0">
                        <input class="form-check-input" type="checkbox" name="permanent_same_as_current" value="1" id="same-address"
                               <?= !isset($editStudent['permanent_same_as_current']) || !empty($editStudent['permanent_same_as_current']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="same-address">Permanent address is same</label>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Permanent House / Street</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('permanent_house_street') ?>" name="permanent_house_street"
                           value="<?= e($editStudent['permanent_house_street'] ?? '') ?>">
                    <?= $fieldErrorHtml('permanent_house_street') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Permanent Barangay</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('permanent_barangay') ?>" name="permanent_barangay"
                           value="<?= e($editStudent['permanent_barangay'] ?? '') ?>">
                    <?= $fieldErrorHtml('permanent_barangay') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">City / Municipality</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('permanent_city_municipality') ?>" name="permanent_city_municipality"
                           value="<?= e($editStudent['permanent_city_municipality'] ?? '') ?>">
                    <?= $fieldErrorHtml('permanent_city_municipality') ?>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">ZIP Code</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('permanent_zip_code') ?>" name="permanent_zip_code"
                           value="<?= e($editStudent['permanent_zip_code'] ?? '') ?>">
                    <?= $fieldErrorHtml('permanent_zip_code') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Province</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('permanent_province') ?>" name="permanent_province"
                           value="<?= e($editStudent['permanent_province'] ?? '') ?>">
                    <?= $fieldErrorHtml('permanent_province') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Country</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('permanent_country') ?>" name="permanent_country"
                           value="<?= e($editStudent['permanent_country'] ?? 'Philippines') ?>">
                    <?= $fieldErrorHtml('permanent_country') ?>
                </div>
            </div>
            </div><!-- /pane-address -->

            <div class="tab-pane fade" id="pane-parents" role="tabpanel" aria-labelledby="tab-parents">
            <h6 class="fw-semibold text-primary mb-3">Parents</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Father Last Name</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('father_last_name') ?>" name="father_last_name"
                           value="<?= e($editStudent['father_last_name'] ?? '') ?>">
                    <?= $fieldErrorHtml('father_last_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Father First Name</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('father_first_name') ?>" name="father_first_name"
                           value="<?= e($editStudent['father_first_name'] ?? '') ?>">
                    <?= $fieldErrorHtml('father_first_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Father Middle Name</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('father_middle_name') ?>" name="father_middle_name"
                           value="<?= e($editStudent['father_middle_name'] ?? '') ?>">
                    <?= $fieldErrorHtml('father_middle_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Father Contact</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('father_contact_number') ?>" name="father_contact_number"
                           value="<?= e($editStudent['father_contact_number'] ?? '') ?>">
                    <?= $fieldErrorHtml('father_contact_number') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Mother Maiden Last Name</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('mother_maiden_last_name') ?>" name="mother_maiden_last_name"
                           value="<?= e($editStudent['mother_maiden_last_name'] ?? '') ?>">
                    <?= $fieldErrorHtml('mother_maiden_last_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Mother First Name</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('mother_first_name') ?>" name="mother_first_name"
                           value="<?= e($editStudent['mother_first_name'] ?? '') ?>">
                    <?= $fieldErrorHtml('mother_first_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Mother Middle Name</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('mother_middle_name') ?>" name="mother_middle_name"
                           value="<?= e($editStudent['mother_middle_name'] ?? '') ?>">
                    <?= $fieldErrorHtml('mother_middle_name') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Mother Contact</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('mother_contact_number') ?>" name="mother_contact_number"
                           value="<?= e($editStudent['mother_contact_number'] ?? '') ?>">
                    <?= $fieldErrorHtml('mother_contact_number') ?>
                </div>
            </div>
            </div><!-- /pane-parents -->

            <div class="tab-pane fade" id="pane-background" role="tabpanel" aria-labelledby="tab-background">
            <h6 class="fw-semibold text-primary mb-3">Learner Background</h6>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <input type="hidden" name="is_ip_community" value="0">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="is_ip_community" value="1" id="is-ip"
                               <?= !empty($editStudent['is_ip_community']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is-ip">Indigenous Peoples</label>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">IP Group</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('ip_group') ?>" name="ip_group"
                           value="<?= e($editStudent['ip_group'] ?? '') ?>">
                    <?= $fieldErrorHtml('ip_group') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <input type="hidden" name="is_4ps_beneficiary" value="0">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="is_4ps_beneficiary" value="1" id="is-4ps"
                               <?= !empty($editStudent['is_4ps_beneficiary']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is-4ps">4Ps Beneficiary</label>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">4Ps Household ID</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('four_ps_household_id') ?>" name="four_ps_household_id"
                           value="<?= e($editStudent['four_ps_household_id'] ?? '') ?>">
                    <?= $fieldErrorHtml('four_ps_household_id') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <input type="hidden" name="learner_with_disability" value="0">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="learner_with_disability" value="1" id="has-disability"
                               <?= !empty($editStudent['learner_with_disability']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="has-disability">Learner with Disability</label>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Disability Type</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('disability_type') ?>" name="disability_type"
                           value="<?= e($editStudent['disability_type'] ?? '') ?>">
                    <?= $fieldErrorHtml('disability_type') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <input type="hidden" name="returning_learner" value="0">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="returning_learner" value="1" id="returning-learner"
                               <?= !empty($editStudent['returning_learner']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="returning-learner">Returning Learner</label>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <input type="hidden" name="transferee" value="0">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" name="transferee" value="1" id="transferee"
                               <?= !empty($editStudent['transferee']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="transferee">Transferee</label>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last Grade Completed</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('last_grade_level_completed') ?>" name="last_grade_level_completed"
                           value="<?= e($editStudent['last_grade_level_completed'] ?? '') ?>">
                    <?= $fieldErrorHtml('last_grade_level_completed') ?>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Last School Year</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('last_school_year_completed') ?>" name="last_school_year_completed"
                           value="<?= e($editStudent['last_school_year_completed'] ?? '') ?>">
                    <?= $fieldErrorHtml('last_school_year_completed') ?>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Last School Attended</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('last_school_attended') ?>" name="last_school_attended"
                           value="<?= e($editStudent['last_school_attended'] ?? '') ?>">
                    <?= $fieldErrorHtml('last_school_attended') ?>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">School ID</label>
                    <input type="text" class="form-control<?= $fieldInvalidClass('previous_school_id') ?>" name="previous_school_id"
                           value="<?= e($editStudent['previous_school_id'] ?? '') ?>">
                    <?= $fieldErrorHtml('previous_school_id') ?>
                </div>
            </div>
            </div><!-- /pane-background -->

            <div class="tab-pane fade" id="pane-guardian" role="tabpanel" aria-labelledby="tab-guardian">
            <!-- ── Guardian / Parent ──────────────────── -->
            <h6 class="fw-semibold text-primary mb-3"><i class="bi bi-person-heart me-1"></i>Guardian / Parent</h6>

            <?php
            $hasLinkedGuardian = !empty($editStudent['g_id']);
            $defaultMode = $editStudent['_guardian_mode'] ?? ($hasLinkedGuardian ? 'existing' : 'new');
            if (!in_array($defaultMode, ['existing', 'new', 'none'], true)) {
                $defaultMode = 'new';
            }
            $selectedGuardianId = (int)($editStudent['_existing_guardian_id'] ?? ($editStudent['g_id'] ?? 0));
            ?>

            <!-- Mode toggle -->
            <div class="d-flex gap-2 mb-3" id="guardian-mode-btns">
                <button type="button" class="btn btn-sm mode-btn <?= $defaultMode === 'existing' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                        data-mode="existing" onclick="setGuardianMode('existing')">
                    <i class="bi bi-person-check me-1"></i>Link Existing Guardian
                </button>
                <button type="button" class="btn btn-sm mode-btn <?= $defaultMode === 'new' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                        data-mode="new" onclick="setGuardianMode('new')">
                    <i class="bi bi-person-plus me-1"></i>Create New Guardian
                </button>
                <button type="button" class="btn btn-sm mode-btn <?= $defaultMode === 'none' ? 'btn-primary' : 'btn-outline-secondary' ?>"
                        data-mode="none" onclick="setGuardianMode('none')">
                    <i class="bi bi-dash-circle me-1"></i>No Guardian
                </button>
            </div>
            <input type="hidden" name="guardian_mode" id="guardian_mode_input" value="<?= e($defaultMode) ?>">

            <!-- Panel: Link Existing -->
            <div id="panel-existing" class="guardian-panel <?= $defaultMode !== 'existing' ? 'd-none' : '' ?>">
                <div class="mb-3">
                    <label class="form-label">Search &amp; Select Guardian <span class="text-danger">*</span></label>
                    <input type="text" class="form-control mb-2" id="existing-guardian-search"
                           placeholder="Type name or email to filter..."
                           autocomplete="off">
                    <div id="existing-guardian-list" class="border rounded" style="max-height:220px;overflow-y:auto;">
                        <?php foreach ($guardians as $g): ?>
                        <label class="guardian-option d-flex align-items-center gap-3 px-3 py-2 border-bottom"
                               for="gopt_<?= (int)$g['id'] ?>"
                               data-name="<?= e(strtolower($g['last_name'] . ' ' . $g['first_name'] . ' ' . ($g['middle_name'] ?? ''))) ?>"
                               data-email="<?= e(strtolower($g['email'])) ?>"
                               style="cursor:pointer;">
                             <input class="form-check-input mt-0" type="radio" name="existing_guardian_id"
                                    id="gopt_<?= (int)$g['id'] ?>" value="<?= (int)$g['id'] ?>"
                                    <?= $selectedGuardianId == $g['id'] ? 'checked' : '' ?>
                                    data-required-when="existing"
                                    style="width:18px;height:18px;">
                            <div>
                                <div class="fw-semibold"><?= e(trim(format_name($g['first_name'], $g['last_name']) . ' ' . ($g['middle_name'] ?? ''))) ?></div>
                                <small class="text-muted"><?= e($g['email']) ?><?= $g['contact_number'] ? ' · ' . e($g['contact_number']) : '' ?></small>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($guardians)): ?>
                            <div class="text-muted small text-center py-3">No guardians in the system yet.</div>
                        <?php endif; ?>
                    </div>
                    <?= $fieldErrorHtml('existing_guardian_id') ?>
                </div>
            </div>

            <!-- Panel: Create New -->
            <div id="panel-new" class="guardian-panel <?= $defaultMode !== 'new' ? 'd-none' : '' ?>">
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Guardian Last Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_last_name') ?>" name="new_g_last_name"
                               value="<?= e($editStudent['g_last_name'] ?? '') ?>"
                               data-required-when="new">
                        <?= $fieldErrorHtml('new_g_last_name') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Guardian First Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_first_name') ?>" name="new_g_first_name"
                               value="<?= e($editStudent['g_first_name'] ?? '') ?>"
                               data-required-when="new">
                        <?= $fieldErrorHtml('new_g_first_name') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Guardian Middle Name</label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_middle_name') ?>" name="new_g_middle_name"
                               value="<?= e($editStudent['g_middle_name'] ?? '') ?>">
                        <?= $fieldErrorHtml('new_g_middle_name') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control<?= $fieldInvalidClass('new_g_email') ?>" name="new_g_email"
                               value="<?= e($editStudent['g_email'] ?? '') ?>"
                               placeholder="Used for portal login"
                               data-required-when="new">
                        <div class="form-text">Default password will be <strong>Guardian@1234</strong></div>
                        <?= $fieldErrorHtml('new_g_email') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Contact Number <span class="text-danger">*</span></label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_contact') ?>" name="new_g_contact"
                               value="<?= e($editStudent['g_contact'] ?? '') ?>"
                               data-required-when="new">
                        <?= $fieldErrorHtml('new_g_contact') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Relationship <span class="text-danger">*</span></label>
                        <select class="form-select<?= $fieldInvalidClass('new_g_relationship') ?>" name="new_g_relationship" data-required-when="new">
                            <option value="">Select...</option>
                            <?php foreach (['Parent','Guardian','Sibling','Grandparent','Aunt/Uncle','Other'] as $r): ?>
                                <option value="<?= e($r) ?>" <?= e(($editStudent['g_relationship'] ?? '') === $r ? 'selected' : '') ?>><?= e($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $fieldErrorHtml('new_g_relationship') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Occupation</label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_occupation') ?>" name="new_g_occupation"
                               value="<?= e($editStudent['g_occupation'] ?? '') ?>">
                        <?= $fieldErrorHtml('new_g_occupation') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Civil Status</label>
                        <select class="form-select<?= $fieldInvalidClass('new_g_civil_status') ?>" name="new_g_civil_status">
                            <option value="">Select...</option>
                            <?php foreach (['single','married','widowed','separated','others'] as $cs): ?>
                                <option value="<?= e($cs) ?>" <?= e(($editStudent['g_civil_status'] ?? '') === $cs ? 'selected' : '') ?>><?= e(ucfirst($cs)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= $fieldErrorHtml('new_g_civil_status') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Nationality</label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_nationality') ?>" name="new_g_nationality"
                               value="<?= e($editStudent['g_nationality'] ?? 'Filipino') ?>">
                        <?= $fieldErrorHtml('new_g_nationality') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Religion</label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_religion') ?>" name="new_g_religion"
                               value="<?= e($editStudent['g_religion'] ?? '') ?>">
                        <?= $fieldErrorHtml('new_g_religion') ?>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Home Address</label>
                        <textarea class="form-control<?= $fieldInvalidClass('new_g_address') ?>" name="new_g_address" rows="2"><?= e($editStudent['g_address'] ?? '') ?></textarea>
                        <?= $fieldErrorHtml('new_g_address') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Emergency Contact Name</label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_emergency_name') ?>" name="new_g_emergency_name"
                               value="<?= e($editStudent['g_emergency_name'] ?? '') ?>">
                        <?= $fieldErrorHtml('new_g_emergency_name') ?>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label">Emergency Contact Number</label>
                        <input type="text" class="form-control<?= $fieldInvalidClass('new_g_emergency_number') ?>" name="new_g_emergency_number"
                               value="<?= e($editStudent['g_emergency_number'] ?? '') ?>">
                        <?= $fieldErrorHtml('new_g_emergency_number') ?>
                    </div>
                </div>
            </div>

            <!-- Panel: No Guardian -->
            <div id="panel-none" class="guardian-panel <?= $defaultMode !== 'none' ? 'd-none' : '' ?>">
                <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>No guardian will be linked to this student. You can assign one later by editing the student record.</p>
            </div>
            </div><!-- /pane-guardian -->
            </div><!-- /tab-content -->

            <div class="student-form-actions">
                <a href="<?= APP_URL ?>/admin/admin-students.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Student</button>
            </div>
        </form>
    </div>
</div>

<style>
.field-error { line-height: 1.25; }
.guardian-option { transition: background 0.12s; }
.guardian-option:hover { background: #f0f4ff; }
.guardian-option:has(input:checked) { background: #e8f0fe; }
.guardian-option:last-child { border-bottom: none !important; }
.student-form-tabs .nav-link { color: var(--text-secondary, #475569); font-weight: 500; }
.student-form-tabs .nav-link.active { color: var(--primary, #4F46E5); font-weight: 600; }
.student-form-actions {
    position: sticky;
    bottom: 0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 18px;
    padding: 14px 0;
    background: var(--card-bg, #fff);
    border-top: 1px solid var(--border-color, #E2E8F0);
    z-index: 5;
}
</style>
<script>
function setGuardianMode(mode) {
    document.getElementById('guardian_mode_input').value = mode;
    document.querySelectorAll('.guardian-panel').forEach(p => p.classList.add('d-none'));
    document.getElementById('panel-' + mode).classList.remove('d-none');
    document.querySelectorAll('.mode-btn').forEach(btn => {
        const active = btn.dataset.mode === mode;
        btn.classList.toggle('btn-primary', active);
        btn.classList.toggle('btn-outline-secondary', !active);
    });
    syncGuardianRequiredFields(mode);
}

function syncGuardianRequiredFields(mode) {
    document.querySelectorAll('[data-required-when]').forEach(field => {
        field.required = field.dataset.requiredWhen === mode;
    });
}

syncGuardianRequiredFields(document.getElementById('guardian_mode_input')?.value || 'new');

// Show only the sections that match the selected grade level
(function () {
    const gradeSel = document.getElementById('studentGradeLevel');
    const sectionSel = document.getElementById('studentSectionSelect');
    if (!gradeSel || !sectionSel) return;

    function filterSections() {
        const grade = gradeSel.value;
        Array.from(sectionSel.options).forEach(opt => {
            if (opt.value === '0') return; // always keep "None / To be assigned"
            const match = grade !== '' && opt.dataset.grade === grade;
            opt.hidden = !match;
            opt.disabled = !match;
            if (opt.selected && !match) opt.selected = false;
        });
        const current = sectionSel.options[sectionSel.selectedIndex];
        if (!current || current.hidden) sectionSel.value = '0';
    }

    gradeSel.addEventListener('change', filterSections);
    filterSections();
})();

// Filter existing guardian list
document.getElementById('existing-guardian-search')?.addEventListener('input', function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('.guardian-option').forEach(opt => {
        const match = !q || opt.dataset.name.includes(q) || opt.dataset.email.includes(q);
        opt.style.display = match ? 'flex' : 'none';
    });
});

// Keep tabbed form usable: surface validation errors on the right tab.
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('student-form');
    if (!form) return;

    function activateTabFor(el) {
        const pane = el.closest('.tab-pane');
        if (!pane) return;
        const trigger = document.querySelector('[data-bs-target="#' + pane.id + '"]');
        if (trigger && window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(trigger).show();
        }
    }

    // A required field on a hidden tab would otherwise trigger the browser's
    // "invalid control is not focusable" error. Switch to its tab first.
    let handled = false;
    form.addEventListener('invalid', function (e) {
        if (handled) return;
        handled = true;
        activateTabFor(e.target);
        setTimeout(function () { handled = false; }, 0);
    }, true);

    // After a server-side validation round-trip, jump to the first error.
    const firstError = form.querySelector('.is-invalid');
    if (firstError) activateTabFor(firstError);

    // Clicking an item in the error summary jumps to that field's tab,
    // scrolls to it, and focuses it.
    document.querySelectorAll('.error-jump').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const name = link.getAttribute('data-focus-field');
            const target = form.querySelector('[name="' + name + '"]');
            if (!target) return;
            activateTabFor(target);
            setTimeout(function () {
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.focus({ preventScroll: true });
            }, 60);
        });
    });
});
</script>
<?php endif; ?>

<!-- Search bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-center" id="student-search">
            <div class="col-md-8">
                <input type="text" class="form-control form-control-sm" name="search"
                       placeholder="Search by name, LRN, or PSA number..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary" title="Search students" aria-label="Search students"><i class="bi bi-search"></i></button>
                <?php if ($search): ?>
                    <a href="<?= APP_URL ?>/admin/admin-students.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="table-container">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="students-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student Name</th>
                    <th>Guardian</th>
                    <th>Grade</th>
                    <th>Section</th>
                    <th>LRN</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <?= emptyStateRow(7, 'No students match the current view.', 'Add a student with the "Add Student" button, or clear the search box above to see everyone.', 'bi-mortarboard') ?>
                <?php else: ?>
                    <?php foreach ($students as $i => $s): ?>
                    <tr>
                        <td><?= e((string)($offset + $i + 1)) ?></td>
                        <td class="fw-bold"><?= e(trim(format_name($s['first_name'], $s['last_name']) . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['extension_name'] ?? ''))) ?></td>
                        <td>
                            <?php if ($s['guardian_name']): ?>
                                <span><?= e($s['guardian_name']) ?></span>
                                <?php if ($s['guardian_contact']): ?>
                                    <br><small class="text-muted"><?= e($s['guardian_contact']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $s['grade_level'] ? e(formatGradeLevel((string)$s['grade_level'])) : '<span class="text-muted">N/A</span>' ?></td>
                        <td><?= e($s['section_name'] ?? '—') ?></td>
                        <td><small><?= e($s['lrn'] ?? '—') ?></small></td>
                        <td>
                            <?php if (!$isClerk): ?>
                            <a href="?action=edit&id=<?= (int)$s['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="Edit student" aria-label="Edit student"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="?action=delete&id=<?= (int)$s['id'] ?>"
                                  class="d-inline" data-confirm="Delete this student? This permanently removes their record and cannot be undone." data-confirm-variant="danger">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete student" aria-label="Delete student"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php else: ?>
                                <span class="text-muted small">View only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= paginationLinks($page, $totalPages, '?search=' . urlencode($search)) ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
