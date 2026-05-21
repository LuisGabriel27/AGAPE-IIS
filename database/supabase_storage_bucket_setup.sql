-- ============================================================
-- AGAPE AIIS Supabase Storage Bucket Setup
--
-- Run this in the Supabase SQL Editor before enabling:
-- ENROLLMENT_DOCUMENT_STORAGE_DRIVER=supabase
--
-- The PHP app still enforces document authorization through
-- enrollment-document.php. The bucket is private so raw public URLs
-- cannot expose enrollment requirements.
-- ============================================================

INSERT INTO storage.buckets (
    id,
    name,
    public,
    file_size_limit,
    allowed_mime_types
)
VALUES (
    'enrollment-documents',
    'enrollment-documents',
    false,
    5242880,
    ARRAY['application/pdf', 'image/jpeg', 'image/png']::text[]
)
ON CONFLICT (id) DO UPDATE
SET
    public = EXCLUDED.public,
    file_size_limit = EXCLUDED.file_size_limit,
    allowed_mime_types = EXCLUDED.allowed_mime_types;

SELECT 'AGAPE AIIS enrollment document storage bucket is ready.' AS result;
