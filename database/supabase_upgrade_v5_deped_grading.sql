-- UPGRADE 5: DepEd K-12 Periodic Grading
-- Adds four grading period columns for basic education report cards.
-- Existing midterm/finals values are copied into quarter1/quarter2 when present.

ALTER TABLE subjects
    ALTER COLUMN units SET DEFAULT 0;

ALTER TABLE grades
    ADD COLUMN IF NOT EXISTS quarter1 DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quarter2 DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quarter3 DECIMAL(5,2) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quarter4 DECIMAL(5,2) DEFAULT NULL;

UPDATE grades
SET quarter1 = COALESCE(quarter1, midterm),
    quarter2 = COALESCE(quarter2, finals)
WHERE quarter1 IS NULL
   OR quarter2 IS NULL;

UPDATE grades
SET final_grade = CASE
    WHEN quarter1 IS NOT NULL
     AND quarter2 IS NOT NULL
     AND quarter3 IS NOT NULL
     AND quarter4 IS NOT NULL
        THEN ROUND((quarter1 + quarter2 + quarter3 + quarter4) / 4, 2)
    ELSE NULL
END;
