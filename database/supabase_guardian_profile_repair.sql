-- ============================================================
-- AGAPE AIIS Guardian Profile Repair
--
-- Run this once on existing Supabase/local databases after the
-- profile field expansion. It adds guardian name extension support
-- and creates missing guardian profile rows for guardian user accounts.
-- ============================================================

ALTER TABLE guardians
    ADD COLUMN IF NOT EXISTS extension_name VARCHAR(30) DEFAULT NULL;

INSERT INTO guardians
    (user_id, first_name, middle_name, last_name, extension_name, contact_number, address,
     relationship_to_student, occupation, civil_status, nationality, religion,
     emergency_contact_name, emergency_contact_number, data_privacy_consent, data_privacy_consented_at)
SELECT
    u.id,
    COALESCE(NULLIF(btrim(up.first_name), ''), ''),
    COALESCE(NULLIF(btrim(up.middle_name), ''), ''),
    COALESCE(NULLIF(btrim(up.last_name), ''), initcap(replace(replace(replace(split_part(u.email, '@', 1), '.', ' '), '_', ' '), '-', ' ')), 'Guardian'),
    NULLIF(btrim(up.extension_name), ''),
    NULLIF(regexp_replace(COALESCE(up.contact_number, ''), '\D', '', 'g'), ''),
    NULLIF(btrim(up.address), ''),
    NULL,
    NULL,
    NULL,
    'Filipino',
    NULL,
    NULLIF(btrim(up.emergency_contact_name), ''),
    NULLIF(regexp_replace(COALESCE(up.emergency_contact_number, ''), '\D', '', 'g'), ''),
    FALSE,
    NULL
FROM users u
LEFT JOIN user_profiles up ON up.user_id = u.id
WHERE (
        u.role = 'guardian'
        OR EXISTS (
            SELECT 1
            FROM user_roles ur
            WHERE ur.user_id = u.id
              AND ur.role = 'guardian'
        )
    )
  AND NOT EXISTS (
        SELECT 1
        FROM guardians g
        WHERE g.user_id = u.id
    );

SELECT 'Guardian profile repair completed.' AS result;
