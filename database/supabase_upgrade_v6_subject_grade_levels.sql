-- UPGRADE 6: Grade-level subjects for Preschool through Grade 6
-- Keeps department/units only for legacy compatibility.

ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS grade_level VARCHAR(20) NOT NULL DEFAULT '',
    ALTER COLUMN units SET DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_subject_grade_level ON subjects (grade_level);

WITH seed_subjects (code, name, grade_level) AS (
    VALUES
    ('PS-LANG', 'Language Readiness', 'Preschool'),
    ('PS-NUM', 'Numeracy Readiness', 'Preschool'),
    ('PS-VAL', 'Values and Social Development', 'Preschool'),
    ('PS-MOTOR', 'Motor Skills and Creative Arts', 'Preschool'),

    ('K-LANG', 'Language, Literacy and Communication', 'Kindergarten'),
    ('K-MATH', 'Mathematics', 'Kindergarten'),
    ('K-ENV', 'Physical and Natural Environment', 'Kindergarten'),
    ('K-MAK', 'Makabansa', 'Kindergarten'),
    ('K-GMRC', 'Good Manners and Right Conduct', 'Kindergarten'),

    ('G1-MTB', 'Mother Tongue', '1'),
    ('G1-FIL', 'Filipino', '1'),
    ('G1-ENG', 'English', '1'),
    ('G1-MATH', 'Mathematics', '1'),
    ('G1-AP', 'Araling Panlipunan', '1'),
    ('G1-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '1'),
    ('G1-MUSIC', 'Music', '1'),
    ('G1-ARTS', 'Arts', '1'),
    ('G1-PE', 'Physical Education', '1'),
    ('G1-HEALTH', 'Health', '1'),

    ('G2-MTB', 'Mother Tongue', '2'),
    ('G2-FIL', 'Filipino', '2'),
    ('G2-ENG', 'English', '2'),
    ('G2-MATH', 'Mathematics', '2'),
    ('G2-AP', 'Araling Panlipunan', '2'),
    ('G2-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '2'),
    ('G2-MUSIC', 'Music', '2'),
    ('G2-ARTS', 'Arts', '2'),
    ('G2-PE', 'Physical Education', '2'),
    ('G2-HEALTH', 'Health', '2'),

    ('G3-MTB', 'Mother Tongue', '3'),
    ('G3-FIL', 'Filipino', '3'),
    ('G3-ENG', 'English', '3'),
    ('G3-MATH', 'Mathematics', '3'),
    ('G3-SCI', 'Science', '3'),
    ('G3-AP', 'Araling Panlipunan', '3'),
    ('G3-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '3'),
    ('G3-MUSIC', 'Music', '3'),
    ('G3-ARTS', 'Arts', '3'),
    ('G3-PE', 'Physical Education', '3'),
    ('G3-HEALTH', 'Health', '3'),

    ('G4-FIL', 'Filipino', '4'),
    ('G4-ENG', 'English', '4'),
    ('G4-MATH', 'Mathematics', '4'),
    ('G4-SCI', 'Science', '4'),
    ('G4-AP', 'Araling Panlipunan', '4'),
    ('G4-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '4'),
    ('G4-MUSIC', 'Music', '4'),
    ('G4-ARTS', 'Arts', '4'),
    ('G4-PE', 'Physical Education', '4'),
    ('G4-HEALTH', 'Health', '4'),
    ('G4-EPP', 'Edukasyong Pantahanan at Pangkabuhayan', '4'),

    ('G5-FIL', 'Filipino', '5'),
    ('G5-ENG', 'English', '5'),
    ('G5-MATH', 'Mathematics', '5'),
    ('G5-SCI', 'Science', '5'),
    ('G5-AP', 'Araling Panlipunan', '5'),
    ('G5-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '5'),
    ('G5-MUSIC', 'Music', '5'),
    ('G5-ARTS', 'Arts', '5'),
    ('G5-PE', 'Physical Education', '5'),
    ('G5-HEALTH', 'Health', '5'),
    ('G5-EPP', 'Edukasyong Pantahanan at Pangkabuhayan', '5'),

    ('G6-FIL', 'Filipino', '6'),
    ('G6-ENG', 'English', '6'),
    ('G6-MATH', 'Mathematics', '6'),
    ('G6-SCI', 'Science', '6'),
    ('G6-AP', 'Araling Panlipunan', '6'),
    ('G6-GMRCESP', 'GMRC / Edukasyon sa Pagpapakatao', '6'),
    ('G6-MUSIC', 'Music', '6'),
    ('G6-ARTS', 'Arts', '6'),
    ('G6-PE', 'Physical Education', '6'),
    ('G6-HEALTH', 'Health', '6'),
    ('G6-EPP', 'Edukasyong Pantahanan at Pangkabuhayan', '6')
)
INSERT INTO subjects (code, name, grade_level, units, department)
SELECT code, name, grade_level, 0, NULL
FROM seed_subjects
ON CONFLICT (code) DO UPDATE SET
    name = EXCLUDED.name,
    grade_level = EXCLUDED.grade_level,
    units = 0,
    department = NULL;
