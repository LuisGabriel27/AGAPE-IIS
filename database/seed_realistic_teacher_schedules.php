<?php
/**
 * Seed a realistic elementary weekly schedule for AGAPE AIIS.
 *
 * This uses the existing sections, advisers, teachers, and subjects. It does
 * not make advisers teach every subject. Core and specialist teachers rotate
 * through each section's room, while Preschool and Kindergarten stay with
 * their early-childhood advisers.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getDB();
$schoolYear = $argv[1] ?? currentSchoolYear();
$term = $argv[2] ?? '1st Semester';

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
$periods = [
    ['08:00', '08:45'],
    ['08:45', '09:30'],
    ['09:45', '10:30'],
    ['10:30', '11:15'],
    ['12:30', '13:15'],
    ['13:15', '14:00'],
    ['14:15', '15:00'],
];

$teachers = [];
foreach ($pdo->query('SELECT id, first_name, last_name FROM teachers ORDER BY id') as $row) {
    $teachers[(int)$row['id']] = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
}

$sectionsByGrade = [];
foreach ($pdo->query('SELECT id, name, grade_level, adviser_id FROM sections ORDER BY grade_level, name') as $row) {
    $sectionsByGrade[(string)$row['grade_level']] = $row;
}

$subjectsByCode = [];
foreach ($pdo->query('SELECT id, code, name, grade_level FROM subjects ORDER BY code') as $row) {
    $subjectsByCode[(string)$row['code']] = $row;
}

$teacherByRole = [
    'elementary_english_primary' => 4,  // Allysseus Durban
    'elementary_english_upper'   => 1,  // April Lhen Paniza
    'elementary_math_primary'    => 5,  // Paul Vincent Lunaspe
    'elementary_math_upper'      => 3,  // Shiela Mae Nonescan
    'fil_ap_primary'             => 6,  // Rommyl Sirilan
    'fil_ap_upper'               => 2,  // Jule Leanne Tabares
    'mapeh'                      => 10, // Justine Millaro
    'preschool'                  => 9,  // Jim Lloyd Mondragon
    'kindergarten'               => 7,  // Luke Gabriel Arrieta
];

$sessions = [];

$teacherName = static function (int $teacherId) use ($teachers): string {
    return $teachers[$teacherId] ?? ('Teacher #' . $teacherId);
};

$sectionForGrade = static function (string $grade) use ($sectionsByGrade): array {
    if (empty($sectionsByGrade[$grade])) {
        throw new RuntimeException('No section found for grade level ' . $grade . '.');
    }
    return $sectionsByGrade[$grade];
};

$subjectByCode = static function (string $code) use ($subjectsByCode): array {
    if (empty($subjectsByCode[$code])) {
        throw new RuntimeException('No subject found with code ' . $code . '.');
    }
    return $subjectsByCode[$code];
};

$addSessions = static function (
    string $grade,
    string $subjectCode,
    int $teacherId,
    int $count
) use (&$sessions, $sectionForGrade, $subjectByCode, $teacherName): void {
    $section = $sectionForGrade($grade);
    $subject = $subjectByCode($subjectCode);
    $earlyChildhood = in_array(strtolower($grade), ['preschool', 'kindergarten'], true);

    for ($i = 0; $i < $count; $i++) {
        $sessions[] = [
            'section_id' => (int)$section['id'],
            'section_name' => (string)$section['name'],
            'grade_level' => $grade,
            'subject_id' => (int)$subject['id'],
            'subject_code' => (string)$subject['code'],
            'subject_name' => (string)$subject['name'],
            'teacher_id' => $teacherId,
            'teacher_name' => $teacherName($teacherId),
            'room' => (string)$section['name'] . ' Room',
            'early_childhood' => $earlyChildhood,
        ];
    }
};

// Preschool and Kindergarten: early-childhood advisers handle integrated areas.
$addSessions('Preschool', 'PS-LANG', $teacherByRole['preschool'], 5);
$addSessions('Preschool', 'PS-NUM', $teacherByRole['preschool'], 5);
$addSessions('Preschool', 'PS-MOTOR', $teacherByRole['preschool'], 4);
$addSessions('Preschool', 'PS-VAL', $teacherByRole['preschool'], 3);

$addSessions('Kindergarten', 'K-LANG', $teacherByRole['kindergarten'], 5);
$addSessions('Kindergarten', 'K-MATH', $teacherByRole['kindergarten'], 5);
$addSessions('Kindergarten', 'K-ENV', $teacherByRole['kindergarten'], 4);
$addSessions('Kindergarten', 'K-MAK', $teacherByRole['kindergarten'], 3);
$addSessions('Kindergarten', 'K-GMRC', $teacherByRole['kindergarten'], 3);

// Grade 1-2: primary core teachers plus rotating MAPEH.
foreach (['1', '2'] as $grade) {
    $addSessions($grade, "G{$grade}-MATH", $teacherByRole['elementary_math_primary'], 5);
    $addSessions($grade, "G{$grade}-ENG", $teacherByRole['elementary_english_primary'], 5);
    $addSessions($grade, "G{$grade}-FIL", $teacherByRole['fil_ap_primary'], 4);
    $addSessions($grade, "G{$grade}-MTB", $teacherByRole['fil_ap_primary'], 3);
    $addSessions($grade, "G{$grade}-AP", $teacherByRole['fil_ap_primary'], 3);
    $addSessions($grade, "G{$grade}-GMRCESP", (int)$sectionsByGrade[$grade]['adviser_id'], 3);
    $addSessions($grade, "G{$grade}-MUSIC", $teacherByRole['mapeh'], 1);
    $addSessions($grade, "G{$grade}-ARTS", $teacherByRole['mapeh'], 1);
    $addSessions($grade, "G{$grade}-PE", $teacherByRole['mapeh'], 2);
    $addSessions($grade, "G{$grade}-HEALTH", $teacherByRole['mapeh'], 1);
}

// Grade 3 bridges primary and upper elementary.
$addSessions('3', 'G3-MATH', $teacherByRole['elementary_math_primary'], 5);
$addSessions('3', 'G3-ENG', $teacherByRole['elementary_english_primary'], 5);
$addSessions('3', 'G3-FIL', $teacherByRole['fil_ap_upper'], 4);
$addSessions('3', 'G3-SCI', $teacherByRole['elementary_math_primary'], 4);
$addSessions('3', 'G3-MTB', $teacherByRole['fil_ap_primary'], 3);
$addSessions('3', 'G3-AP', $teacherByRole['fil_ap_upper'], 3);
$addSessions('3', 'G3-GMRCESP', (int)$sectionsByGrade['3']['adviser_id'], 2);
$addSessions('3', 'G3-MUSIC', $teacherByRole['mapeh'], 1);
$addSessions('3', 'G3-ARTS', $teacherByRole['mapeh'], 1);
$addSessions('3', 'G3-PE', $teacherByRole['mapeh'], 1);
$addSessions('3', 'G3-HEALTH', $teacherByRole['mapeh'], 1);

// Grades 4-6: upper-elementary subject rotation.
foreach (['4', '5', '6'] as $grade) {
    $addSessions($grade, "G{$grade}-MATH", $teacherByRole['elementary_math_upper'], 5);
    $addSessions($grade, "G{$grade}-ENG", $teacherByRole['elementary_english_upper'], 5);
    $addSessions($grade, "G{$grade}-FIL", $teacherByRole['fil_ap_upper'], 4);
    $scienceTeacher = $grade === '4' ? $teacherByRole['elementary_math_primary'] : ($grade === '5' ? $teacherByRole['elementary_english_upper'] : $teacherByRole['elementary_math_upper']);
    $addSessions($grade, "G{$grade}-SCI", $scienceTeacher, 4);
    $addSessions($grade, "G{$grade}-AP", $teacherByRole['fil_ap_upper'], 3);
    $addSessions($grade, "G{$grade}-EPP", $teacherByRole['elementary_math_upper'], 3);
    $addSessions($grade, "G{$grade}-GMRCESP", (int)$sectionsByGrade[$grade]['adviser_id'], 2);
    $addSessions($grade, "G{$grade}-MUSIC", $teacherByRole['mapeh'], 1);
    $addSessions($grade, "G{$grade}-ARTS", $teacherByRole['mapeh'], 1);
    $addSessions($grade, "G{$grade}-PE", $teacherByRole['mapeh'], 1);
    $addSessions($grade, "G{$grade}-HEALTH", $teacherByRole['mapeh'], 1);
}

$teacherLoad = [];
foreach ($sessions as $session) {
    $teacherLoad[$session['teacher_id']] = ($teacherLoad[$session['teacher_id']] ?? 0) + 1;
}

usort($sessions, static function (array $a, array $b) use ($teacherLoad): int {
    return [$teacherLoad[$b['teacher_id']], $a['section_id'], $a['subject_code']]
        <=> [$teacherLoad[$a['teacher_id']], $b['section_id'], $b['subject_code']];
});

$assigned = [];
$teacherBusy = [];
$sectionBusy = [];
$roomBusy = [];
$sectionSubjectDayCount = [];
$sectionDayLoad = [];
$teacherDayLoad = [];

foreach ($sessions as $session) {
    $best = null;
    $bestScore = PHP_INT_MAX;

    foreach ($days as $day) {
        foreach ($periods as $slotIndex => $period) {
            if ($session['early_childhood'] && $slotIndex > 3) {
                continue;
            }

            $slotKey = $day . '|' . $period[0] . '|' . $period[1];
            $teacherKey = $session['teacher_id'] . '|' . $slotKey;
            $sectionKey = $session['section_id'] . '|' . $slotKey;
            $roomKey = strtolower($session['room']) . '|' . $slotKey;

            if (!empty($teacherBusy[$teacherKey]) || !empty($sectionBusy[$sectionKey]) || !empty($roomBusy[$roomKey])) {
                continue;
            }

            $subjectDayKey = $session['section_id'] . '|' . $session['subject_id'] . '|' . $day;
            $sectionDayKey = $session['section_id'] . '|' . $day;
            $teacherDayKey = $session['teacher_id'] . '|' . $day;
            $score = (($sectionSubjectDayCount[$subjectDayKey] ?? 0) * 100)
                + (($sectionDayLoad[$sectionDayKey] ?? 0) * 5)
                + ($teacherDayLoad[$teacherDayKey] ?? 0)
                + $slotIndex;

            if ($score < $bestScore) {
                $bestScore = $score;
                $best = [$day, $period[0], $period[1], $slotKey];
            }
        }
    }

    if ($best === null) {
        throw new RuntimeException('Could not place ' . $session['subject_code'] . ' for ' . $session['section_name'] . ' with ' . $session['teacher_name'] . '.');
    }

    [$day, $start, $end, $slotKey] = $best;
    $session['day_of_week'] = $day;
    $session['time_start'] = $start;
    $session['time_end'] = $end;
    $assigned[] = $session;

    $teacherBusy[$session['teacher_id'] . '|' . $slotKey] = true;
    $sectionBusy[$session['section_id'] . '|' . $slotKey] = true;
    $roomBusy[strtolower($session['room']) . '|' . $slotKey] = true;
    $sectionSubjectDayCount[$session['section_id'] . '|' . $session['subject_id'] . '|' . $day] =
        ($sectionSubjectDayCount[$session['section_id'] . '|' . $session['subject_id'] . '|' . $day] ?? 0) + 1;
    $sectionDayLoad[$session['section_id'] . '|' . $day] = ($sectionDayLoad[$session['section_id'] . '|' . $day] ?? 0) + 1;
    $teacherDayLoad[$session['teacher_id'] . '|' . $day] = ($teacherDayLoad[$session['teacher_id'] . '|' . $day] ?? 0) + 1;
}

$inserted = 0;
$skipped = 0;
$insertStmt = $pdo->prepare("
    INSERT INTO schedules (subject_id, section_id, teacher_id, room, day_of_week, time_start, time_end, school_year, term)
    VALUES (:subject_id, :section_id, :teacher_id, :room, :day_of_week, :time_start, :time_end, :school_year, :term)
");
$existsStmt = $pdo->prepare("
    SELECT id
    FROM schedules
    WHERE subject_id = :subject_id
      AND section_id = :section_id
      AND teacher_id = :teacher_id
      AND day_of_week = :day_of_week
      AND time_start = :time_start
      AND time_end = :time_end
      AND school_year = :school_year
      AND term = :term
    LIMIT 1
");

$pdo->beginTransaction();
try {
    foreach ($assigned as $row) {
        $params = [
            ':subject_id' => $row['subject_id'],
            ':section_id' => $row['section_id'],
            ':teacher_id' => $row['teacher_id'],
            ':day_of_week' => $row['day_of_week'],
            ':time_start' => $row['time_start'],
            ':time_end' => $row['time_end'],
            ':school_year' => $schoolYear,
            ':term' => $term,
        ];

        $existsStmt->execute($params);
        if ($existsStmt->fetchColumn()) {
            $skipped++;
            continue;
        }

        $insertStmt->execute($params + [':room' => $row['room']]);
        $inserted++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo "Seeded schedules for {$schoolYear} {$term}." . PHP_EOL;
echo "Inserted: {$inserted}; skipped existing: {$skipped}; planned: " . count($assigned) . PHP_EOL;

$summary = [];
foreach ($assigned as $row) {
    $summary[$row['teacher_name']] = ($summary[$row['teacher_name']] ?? 0) + 1;
}
ksort($summary);
foreach ($summary as $name => $count) {
    echo str_pad($name, 28) . " {$count} classes/week" . PHP_EOL;
}
