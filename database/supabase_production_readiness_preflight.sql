-- ============================================================
-- AGAPE AIIS Supabase Production Readiness Preflight
--
-- Run this FIRST in Supabase SQL Editor.
-- It does not change data. It reports records that must be cleaned
-- before running supabase_production_readiness_hardening.sql.
-- ============================================================

-- Summary. Every issue_count should be 0 before the hardening migration.
SELECT 'duplicate_student_lrn' AS issue, COUNT(*) AS issue_count
FROM (
    SELECT btrim(lrn)
    FROM students
    WHERE lrn IS NOT NULL AND btrim(lrn) <> ''
    GROUP BY btrim(lrn)
    HAVING COUNT(*) > 1
) x
UNION ALL
SELECT 'invalid_student_lrn_format', COUNT(*)
FROM students
WHERE lrn IS NOT NULL
  AND btrim(lrn) <> ''
  AND btrim(lrn) !~ '^[0-9]{12}$'
UNION ALL
SELECT 'duplicate_student_name_birthdate', COUNT(*)
FROM (
    SELECT lower(btrim(first_name)), lower(btrim(last_name)), birthdate
    FROM students
    WHERE btrim(coalesce(first_name, '')) <> ''
      AND btrim(coalesce(last_name, '')) <> ''
      AND birthdate IS NOT NULL
    GROUP BY lower(btrim(first_name)), lower(btrim(last_name)), birthdate
    HAVING COUNT(*) > 1
) x
UNION ALL
SELECT 'duplicate_enrollment_student_year_term', COUNT(*)
FROM (
    SELECT student_id, school_year, term
    FROM enrollments
    GROUP BY student_id, school_year, term
    HAVING COUNT(*) > 1
) x
UNION ALL
SELECT 'duplicate_grade_student_subject_year_term', COUNT(*)
FROM (
    SELECT student_id, subject_id, school_year, term
    FROM grades
    GROUP BY student_id, subject_id, school_year, term
    HAVING COUNT(*) > 1
) x
UNION ALL
SELECT 'duplicate_guardian_profile_user', COUNT(*)
FROM (
    SELECT user_id
    FROM guardians
    WHERE user_id IS NOT NULL
    GROUP BY user_id
    HAVING COUNT(*) > 1
) x
UNION ALL
SELECT 'duplicate_teacher_profile_user', COUNT(*)
FROM (
    SELECT user_id
    FROM teachers
    WHERE user_id IS NOT NULL
    GROUP BY user_id
    HAVING COUNT(*) > 1
) x
UNION ALL
SELECT 'invalid_grade_range', COUNT(*)
FROM grades
WHERE (quarter1 IS NOT NULL AND (quarter1 < 0 OR quarter1 > 100))
   OR (quarter2 IS NOT NULL AND (quarter2 < 0 OR quarter2 > 100))
   OR (quarter3 IS NOT NULL AND (quarter3 < 0 OR quarter3 > 100))
   OR (quarter4 IS NOT NULL AND (quarter4 < 0 OR quarter4 > 100))
   OR (final_grade IS NOT NULL AND (final_grade < 0 OR final_grade > 100))
UNION ALL
SELECT 'invalid_payment_amount', COUNT(*)
FROM payments
WHERE amount < 0
UNION ALL
SELECT 'invalid_schedule_time_range', COUNT(*)
FROM schedules
WHERE time_start >= time_end
UNION ALL
SELECT 'teacher_schedule_overlap', COUNT(*)
FROM schedules a
JOIN schedules b
  ON a.id < b.id
 AND a.teacher_id = b.teacher_id
 AND a.school_year = b.school_year
 AND a.term = b.term
 AND a.day_of_week = b.day_of_week
 AND a.time_start < b.time_end
 AND a.time_end > b.time_start
UNION ALL
SELECT 'section_schedule_overlap', COUNT(*)
FROM schedules a
JOIN schedules b
  ON a.id < b.id
 AND a.section_id = b.section_id
 AND a.school_year = b.school_year
 AND a.term = b.term
 AND a.day_of_week = b.day_of_week
 AND a.time_start < b.time_end
 AND a.time_end > b.time_start
UNION ALL
SELECT 'room_schedule_overlap', COUNT(*)
FROM schedules a
JOIN schedules b
  ON a.id < b.id
 AND lower(btrim(coalesce(a.room, ''))) <> ''
 AND lower(btrim(a.room)) = lower(btrim(b.room))
 AND a.school_year = b.school_year
 AND a.term = b.term
 AND a.day_of_week = b.day_of_week
 AND a.time_start < b.time_end
 AND a.time_end > b.time_start;

-- Detail reports. These return rows only when cleanup is needed.

SELECT 'duplicate_student_lrn' AS issue,
       btrim(lrn) AS lrn,
       COUNT(*) AS duplicate_count,
       array_agg(id ORDER BY id) AS student_ids
FROM students
WHERE lrn IS NOT NULL AND btrim(lrn) <> ''
GROUP BY btrim(lrn)
HAVING COUNT(*) > 1;

SELECT 'invalid_student_lrn_format' AS issue,
       id,
       first_name,
       last_name,
       lrn
FROM students
WHERE lrn IS NOT NULL
  AND btrim(lrn) <> ''
  AND btrim(lrn) !~ '^[0-9]{12}$'
ORDER BY id;

SELECT 'duplicate_student_name_birthdate' AS issue,
       lower(btrim(first_name)) AS normalized_first_name,
       lower(btrim(last_name)) AS normalized_last_name,
       birthdate,
       COUNT(*) AS duplicate_count,
       array_agg(id ORDER BY id) AS student_ids
FROM students
WHERE btrim(coalesce(first_name, '')) <> ''
  AND btrim(coalesce(last_name, '')) <> ''
  AND birthdate IS NOT NULL
GROUP BY lower(btrim(first_name)), lower(btrim(last_name)), birthdate
HAVING COUNT(*) > 1;

SELECT 'duplicate_enrollment_student_year_term' AS issue,
       student_id,
       school_year,
       term,
       COUNT(*) AS duplicate_count,
       array_agg(id ORDER BY id) AS enrollment_ids
FROM enrollments
GROUP BY student_id, school_year, term
HAVING COUNT(*) > 1;

SELECT 'duplicate_grade_student_subject_year_term' AS issue,
       student_id,
       subject_id,
       school_year,
       term,
       COUNT(*) AS duplicate_count,
       array_agg(id ORDER BY id) AS grade_ids
FROM grades
GROUP BY student_id, subject_id, school_year, term
HAVING COUNT(*) > 1;

SELECT 'teacher_schedule_overlap' AS issue,
       a.id AS schedule_id_a,
       b.id AS schedule_id_b,
       a.teacher_id,
       a.school_year,
       a.term,
       a.day_of_week
FROM schedules a
JOIN schedules b
  ON a.id < b.id
 AND a.teacher_id = b.teacher_id
 AND a.school_year = b.school_year
 AND a.term = b.term
 AND a.day_of_week = b.day_of_week
 AND a.time_start < b.time_end
 AND a.time_end > b.time_start
ORDER BY a.school_year, a.term, a.day_of_week, a.teacher_id, a.id;

SELECT 'section_schedule_overlap' AS issue,
       a.id AS schedule_id_a,
       b.id AS schedule_id_b,
       a.section_id,
       a.school_year,
       a.term,
       a.day_of_week
FROM schedules a
JOIN schedules b
  ON a.id < b.id
 AND a.section_id = b.section_id
 AND a.school_year = b.school_year
 AND a.term = b.term
 AND a.day_of_week = b.day_of_week
 AND a.time_start < b.time_end
 AND a.time_end > b.time_start
ORDER BY a.school_year, a.term, a.day_of_week, a.section_id, a.id;

SELECT 'room_schedule_overlap' AS issue,
       a.id AS schedule_id_a,
       b.id AS schedule_id_b,
       a.room,
       a.school_year,
       a.term,
       a.day_of_week
FROM schedules a
JOIN schedules b
  ON a.id < b.id
 AND lower(btrim(coalesce(a.room, ''))) <> ''
 AND lower(btrim(a.room)) = lower(btrim(b.room))
 AND a.school_year = b.school_year
 AND a.term = b.term
 AND a.day_of_week = b.day_of_week
 AND a.time_start < b.time_end
 AND a.time_end > b.time_start
ORDER BY a.school_year, a.term, a.day_of_week, a.room, a.id;
