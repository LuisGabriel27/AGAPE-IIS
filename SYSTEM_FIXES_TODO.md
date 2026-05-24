# AGAPE-IIS Fixes and To-Do List

This list captures the current issues found while checking the system, with the most urgent fixes first.

## Priority Fixes

1. Enrollment clerk login styling
   - Status: Fixed in code.
   - The clerk login page was missing a clerk-specific left-panel gradient and icon color.
   - The login page now cache-busts `assets/css/style.css` using the file modified time, so browsers should load the updated design.
   - Important: strict role login is still enforced. An account must have the `clerk` role to enter the clerk portal.

2. Clerk account access
   - Create a real clerk user, or assign the `clerk` role to the intended account through the admin user management flow.
   - Avoid using the admin account as a clerk unless the admin is intentionally given a secondary clerk role.

3. Guardian schedule data mismatch
   - The active school year is currently `2024-2025`, but available schedules are stored under `2025-2026` and `1st Semester`.
   - Either update the active school year setting or add schedules for the active year.

4. Student section assignment
   - Some students do not have assigned sections.
   - Schedules cannot show correctly for students without a section, because schedule lookup depends on the student's section.

5. Guardian multi-child support
   - Guardian pages should consistently support multiple children.
   - The schedule page needs a student dropdown/filter so guardians can switch between children with different schedules.
   - Other guardian pages should be checked for assumptions like "first student only".

6. Database error visibility
   - Login currently shows a generic database error when a query fails.
   - Add safer admin/developer logging so the real cause is visible without exposing sensitive details to users.

## Security and Stability

7. Enrollment document protection
   - Uploaded enrollment documents should not be publicly accessible by direct URL.
   - Serve documents through a PHP route that checks the logged-in user's role and permission.

8. Role switching request method
   - Role switching should use POST with CSRF protection instead of GET links.
   - This reduces accidental or malicious role-switch requests.

9. Database configuration cleanup
   - Remove hardcoded database port assumptions where possible.
   - Use the configured `DB_PORT` consistently, especially for Supabase pooler vs direct database connections.

10. Migration cleanup
    - Separate old MySQL migrations from the current Supabase/PostgreSQL migration path.
    - Keep one canonical upgrade file for the active database backend.

## UX Improvements

11. Empty states
    - Add clearer empty states for missing grades, missing schedules, missing sections, and missing enrollment records.
    - Each empty state should explain what data is missing and who can fix it.

12. Guardian dashboard
    - Review all dashboard cards for multi-child behavior.
    - Show per-child data or let the guardian choose a child before displaying student-specific information.

13. Enrollment clerk workflow
    - Confirm clerk dashboard, student review, document review, and approval/rejection flows after a real clerk account is available.

14. Test checklist
    - Test login for admin, clerk, teacher, and guardian accounts.
    - Test guardian schedule filtering with at least two children in different sections.
    - Test document upload and secure document viewing.
    - Test enrollment approval/rejection as a clerk.
