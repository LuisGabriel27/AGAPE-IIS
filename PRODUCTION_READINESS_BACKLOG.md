# AGAPE AIIS Production Readiness Backlog

Generated: 2026-05-17

This backlog is based on the technical audit of the `w/supabase` branch. It focuses on the changes needed to move AGAPE AIIS from an academic/demo prototype toward a safer, maintainable, production-ready system.

Priority guide:

- P0: launch blocker or serious security/data risk
- P1: required for reliable MVP operation
- P2: important hardening or maintainability work
- P3: polish, usability, and defense support

## P0 - Critical Security And Data Protection

### P0-01 Fix guardian grade IDOR

- Status: Implemented in code on 2026-05-17; needs UAT with two guardian accounts before closing.
- Area: Guardian portal, authorization
- Problem: `guardian/grades.php` accepts `student_id` from the URL and queries grades without confirming that the selected student belongs to the logged-in guardian.
- Change:
  - Validate the selected student against the guardian's allowed student IDs before any grade query.
  - If unauthorized, return 403 or redirect to the first owned student.
  - Reuse the safer ownership pattern already used by `guardian/report-card.php`.
- Acceptance criteria:
  - Guardian A cannot access Guardian B's student's grades by changing `student_id`.
  - Direct URL tampering is rejected server-side.
  - A regression test or manual test case is documented.

### P0-02 Fix teacher hidden student ID tampering

- Status: Implemented in code on 2026-05-17; needs UAT with a tampered grade/attendance POST before closing.
- Area: Teacher grades, teacher attendance
- Problem: Teacher grade and attendance forms trust hidden `student_ids` from the browser.
- Change:
  - On every POST, verify each submitted student belongs to the selected section.
  - Verify the teacher is assigned to that section/subject for the active term.
  - Reject any unexpected student IDs.
- Acceptance criteria:
  - A teacher cannot update grades or attendance for students outside their assigned section.
  - Tampered hidden inputs are ignored or rejected with an audit log entry.

### P0-03 Protect enrollment documents

- Status: Implemented in code on 2026-05-17 using a protected PHP document route and Apache direct-access deny rule; needs UAT with admin/clerk/guardian and anonymous direct URL checks.
- Area: File uploads, privacy, storage
- Problem: Uploaded student documents are stored under the webroot and may be reachable by direct path.
- Change:
  - Block direct access to enrollment document upload paths.
  - Serve files only through a PHP download/view route that checks role and ownership.
  - Remove direct public links to stored document paths.
- Acceptance criteria:
  - Anonymous users cannot access uploaded documents by URL.
  - Guardians can access only their own documents.
  - Admin/clerk access is logged.

### P0-04 Secure biometric face data

- Status: Deferred by client/team decision on 2026-05-17. Biometrics/face attendance is not part of the client’s intended operating scope, so this is no longer a launch blocker. Revisit only if the feature will be used in production.
- Area: AI attendance, privacy, security
- Problem: Face descriptors are sent to the browser and stored as plaintext JSON. Face photos are stored locally without strong privacy controls.
- Change:
  - Define explicit consent and retention policy for biometric data.
  - Restrict who can load face descriptors.
  - Avoid exposing all descriptors unless required for the current attendance scope.
  - Store face photos outside public webroot or in private storage.
  - Add audit logging for registration and deletion of face profiles.
- Acceptance criteria:
  - Face descriptors/photos are not publicly accessible.
  - Only authorized staff can register or use face attendance.
  - A documented process exists to remove a student's biometric profile.

### P0-05 Remove default password workflows

- Status: Accepted by school process on 2026-05-17; not a current launch blocker. Keep as a documented risk and consider adding forced first-login password change later.
- Area: Authentication, account creation
- Problem: Admin, teacher, and guardian accounts rely on predictable or displayed default passwords.
- Change:
  - Replace default passwords with one-time setup tokens or forced password reset.
  - Mark new accounts as `must_change_password`.
  - Do not display generated passwords in long-lived UI messages.
- Acceptance criteria:
  - New users must set their own password before accessing the portal.
  - Existing default-password accounts are rotated.
  - Login blocks accounts flagged for required password setup until completed.

### P0-06 Create a canonical Supabase migration path

- Status: Completed on 2026-05-17. Canonical Supabase order is documented in `database/README.md`; `SETUP.md` now points the active branch to that path. Old MySQL files are retained as legacy reference only.
- Area: Database, deployment
- Problem: Schema files are fragmented across base, latest update, reset, and v8/v9 upgrade scripts.
- Change:
  - Create one canonical ordered migration set for the active PostgreSQL/Supabase schema.
  - Include all current tables, enums, indexes, constraints, and seed data.
  - Update reset/setup docs to reference only the canonical path.
- Acceptance criteria:
  - A fresh database can be created from zero and the app runs without manual patching.
  - Upgrade scripts are ordered and repeatable.
  - Obsolete MySQL or stale SQL files are clearly archived or removed.

## P1 - Core MVP Reliability

### P1-01 Add database integrity constraints

- Status: Completed in Supabase on 2026-05-17 through `database/supabase_production_readiness_hardening.sql`; keep `database/supabase_production_readiness_preflight.sql` for future verification. Needs app-level UAT to confirm user-facing validation messages are friendly.
- Additional note: Account/profile duplicate hardening was added in code on 2026-05-17. `database/supabase_email_case_hardening.sql` was generated for case-insensitive email uniqueness; current Supabase data checked clean for case-only email duplicates.
- Area: Database schema
- Change:
  - Add unique constraint for student LRN when present.
  - Prevent duplicate enrollments for the same student, school year, and term.
  - Prevent duplicate grade rows for student, subject, school year, and term.
  - Enforce one guardian profile per user and one teacher profile per user.
  - Add schedule conflict constraints or server-side validation for teacher, section, room, day, and time overlaps.
- Acceptance criteria:
  - Duplicate critical records are rejected at the database or transaction level.
  - Existing duplicate data is cleaned or migrated safely.

### P1-02 Complete role model consistency

- Status: Implemented in code on 2026-05-17. `user_roles` is now used as the login source of truth with `users.role` kept as the primary/default compatibility role. Existing Supabase data checked clean for missing primary roles and users without role rows.
- Area: Users, roles, admin tools
- Problem: Some flows use `users.role`, some use `user_roles`, and teacher creation may not consistently seed `user_roles`.
- Change:
  - Make `user_roles` the source of truth.
  - Keep `users.role` only as a legacy/default display value or remove it after migration.
  - Update all account creation/editing flows to sync roles consistently.
- Acceptance criteria:
  - Multi-role users can log in and switch roles reliably.
  - Teacher, guardian, clerk, and admin creation all populate the same role model.

### P1-03 Harden login and sessions

- Area: Authentication
- Status: Implemented in code on 2026-05-17. Needs browser UAT.
- Change:
    - Use secure cookies in HTTPS environments.
    - Add generic login errors to reduce account enumeration.
    - Add IP/user-agent throttling for repeated failed attempts, including unknown emails.
    - Convert logout and role switching to POST with CSRF protection.
- Session behavior:
    - Local XAMPP/HTTP keeps non-secure cookies so development login still works.
    - HTTPS or forwarded HTTPS automatically enables Secure session cookies.
- Acceptance criteria:
    - Brute-force attempts are throttled.
    - Role switch and logout cannot be triggered by simple GET links.
    - Session behavior is documented for local and production environments.

### P1-04 Implement private document review rules

- Area: Enrollment workflow
- Status: Implemented in code on 2026-05-17. Needs browser UAT with one accepted, pending, missing, and replacement-needed enrollment.
- Data cleanup note: Current Supabase data has `enrollment_id=1` (`paid_for_registrar`) with all four required documents missing; review or reset this test row before final demo.
- Problem: Assessment can proceed based on document count instead of all documents being accepted.
- Change:
    - Require all mandatory documents to have accepted review status before assessment.
    - Block payment assessment until document review is complete.
    - Show clear per-document status to guardian and clerk.
- Acceptance criteria:
  - Enrollment cannot move to assessed/payment state while any required document is missing, pending, or rejected.

### P1-05 Clarify and implement payment lifecycle

- Area: Payments, enrollment
- Status: Implemented in code on 2026-05-18. Current design keeps admin/treasurer verification inside the existing admin payments page; no separate cashier module was added. Read-only Supabase consistency checks returned clean for skipped payment-stage records and payment-stage rows without sent assessments. Needs browser UAT for assessment -> guardian payment reference -> admin verification -> registrar submission, including failed-payment retry.
- Change:
  - Define payment statuses and allowed transitions.
  - Track payment assessment, guardian submission, admin/treasurer verification, and registrar enrollment separately.
  - Preserve payment history instead of overwriting the latest row when appropriate.
  - Add receipt/proof upload if required by the school process. Current MVP stores method, reference number, notes, and verification status only.
- Acceptance criteria:
  - Payment status cannot skip required steps.
  - Payment verification actions are audited.
  - Guardian can see the current required action and payment result.

### P1-05A Expand production-ready profile and enrollment fields

- Area: Registrar records, guardians, teachers, admin users
- Status: Implemented in code on 2026-05-18. Requires running `database/supabase_profile_fields_expansion.sql` in Supabase before browser UAT.
- Change:
  - Add DepEd-aligned learner fields: middle/extension name, PSA number, birthplace, mother tongue, religion, detailed addresses, parent names/contact, IP/4Ps/disability flags, transferee/returning learner data, previous school, and SHS track/strand.
  - Expand guardian records with middle name, address, occupation, civil status, nationality, religion, emergency contact, and data privacy consent timestamp.
  - Expand teacher records with employee number, demographic fields, address, department/position, employment status, hire date, PRC license, specialization, and emergency contact.
  - Add staff profile fields for admin/clerk/user accounts through `user_profiles`.
  - Add friendly app-level duplicate checks for teacher employee number/PRC license and staff employee number, backed by database unique indexes.
- Acceptance criteria:
  - Admin can create/edit richer student, guardian, and teacher records without direct database editing.
  - Guardian enrollment captures the same production learner data before document upload.
  - Case-insensitive duplicate employee/PRC identifiers are rejected.

### P1-06 Fix active school year and schedule consistency

- Area: Settings, schedules, guardian portal
- Status: Implemented in code on 2026-05-21. Active academic period now includes `active_school_year` and `active_term`; admin can update both from School Year Management. Dashboards, schedules, teacher grades/attendance/students, guardian schedules/grades/report cards, and enrollment queue now default/filter by the active period. Needs browser UAT for admin/clerk/teacher/guardian views after sync.
- Problem: Active school year and stored schedule data can mismatch.
- Change:
  - Enforce active school year and term consistently across dashboards, schedules, grades, attendance, and enrollment.
  - Add admin warning when active school year has no schedules.
  - Add guardian multi-child schedule filtering.
- Acceptance criteria:
  - Guardian schedule displays correct data per selected child.
  - Admin can clearly see when setup data is incomplete.

### P1-07 Add reliable error logging

- Area: Observability
- Status: Implemented in code on 2026-05-21. Added structured app logging with request ID, route, user ID, role, exception class, file, and line. Replaced raw `error_log()` usage in live app pages with `logException()` / `appLog()` and safe user-facing reference messages. Needs deployment log retention/backup policy before production.
- Change:
  - Log server errors to a protected log file or service.
  - Show safe generic errors to users.
  - Include request route, user ID when available, and exception class.
- Acceptance criteria:
  - Developers can diagnose database and server failures without exposing sensitive details to users.

## P2 - Supabase And Architecture Hardening

### P2-01 Decide the real Supabase strategy

- Area: Architecture
- Change:
  - Choose one path:
    - PostgreSQL-only Supabase hosting with PHP-owned auth and authorization, or
    - Full Supabase platform usage with Auth, RLS, Storage, and policies.
  - Update documentation and defense materials to match the actual strategy.
- Acceptance criteria:
  - The project no longer overclaims Supabase services it does not use.

### P2-02 Add RLS or compensating controls

- Area: Supabase security
- Change:
  - If using Supabase Auth/REST, implement RLS policies for all user-owned tables.
  - If staying PHP-only, ensure PostgREST is not exposed to clients and document why RLS is not part of runtime access.
- Acceptance criteria:
  - Direct client access cannot bypass application authorization.

### P2-03 Centralize authorization helpers

- Area: Backend architecture
- Change:
  - Add helper functions for student ownership, teacher section access, clerk/admin enrollment access, and payment access.
  - Replace scattered inline checks with shared helpers.
- Acceptance criteria:
  - Sensitive pages use shared object-level authorization checks.
  - New pages have a clear pattern to follow.

### P2-04 Create service modules for major workflows

- Area: Maintainability
- Change:
  - Extract enrollment, payment, grades, attendance, document upload, and audit logic from large page files into include/service files.
  - Keep page files focused on request handling and rendering.
- Acceptance criteria:
  - Largest files are reduced in complexity.
  - Business logic can be tested without rendering full pages.

### P2-05 Add automated tests

- Area: QA
- Change:
  - Add tests for login, role checks, guardian ownership, teacher grade authorization, enrollment submission, document review, payment transitions, and attendance marking.
  - Add a manual UAT checklist for capstone defense.
- Acceptance criteria:
  - Critical security and workflow regressions can be caught before demo/deployment.

### P2-06 Improve upload validation

- Area: File handling
- Change:
  - Validate file magic bytes and extensions.
  - Normalize filenames.
  - Set per-document size limits.
  - Add malware scanning or at least an extension/MIME allowlist with logging.
- Acceptance criteria:
  - Invalid files are rejected consistently.
  - Upload behavior is shared across enrollment document flows.

### P2-07 Add audit coverage

- Area: Compliance, traceability
- Change:
  - Audit account creation, role changes, document view/download, document approval/rejection, payment verification, grade publishing, and face profile changes.
- Acceptance criteria:
  - Sensitive actions have a searchable audit trail.

## P3 - User Experience And Defense Readiness

### P3-01 Improve empty states

- Status: Implemented in code on 2026-05-17. Shared `emptyStateHtml()` / `emptyStateRow()` helpers added in `includes/helpers.php` (styled via `.empty-state-hint` in `assets/css/style.css`) and applied to grades (guardian, admin), schedules (guardian, teacher, admin), sections (admin), payments (guardian, admin), enrollment records (admin enrollments, clerk dashboard), and attendance (teacher, admin). Needs browser UAT to confirm wording reads well to non-technical staff/guardians.
- Area: UI/UX
- Change:
  - Add clear empty states for missing grades, schedules, sections, payments, enrollment records, and attendance.
  - Include who can fix the missing data, without exposing technical details.
- Acceptance criteria:
  - Users see helpful messages instead of blank tables or confusing zeros.
- Implementation notes:
  - Each empty state now shows an icon, a plain-language primary message, and a hint naming who resolves the missing data (e.g. teacher publishes grades, registrar assigns sections, clerk/cashier issue payments) with no SQL/technical detail.
  - JS-driven rows (e.g. `#noAttendanceRow` on the AI attendance board) kept their element id/colspan so existing scripts still work.

### P3-02 Clean up dead or legacy pages

- Area: Codebase hygiene
- Change:
  - Remove, archive, or document redirect-only/legacy pages.
  - Remove disabled OAuth UI references if OAuth will not be used.
- Acceptance criteria:
  - Navigation and code structure match the features actually supported.

### P3-03 Fix encoding and hardcoded values

- Area: Maintainability
- Change:
  - Clean mojibake/encoding artifacts.
  - Move principal names, school year labels, default fees, and local URLs into settings/config.
- Acceptance criteria:
  - Text renders cleanly.
  - School-specific values can be changed without editing page code.

### P3-04 Prepare technical defense documentation

- Area: Thesis/capstone defense
- Change:
  - Document actual architecture, database ERD, authentication flow, enrollment flow, payment flow, and face attendance flow.
  - Be explicit about what is implemented, partially implemented, and future work.
- Acceptance criteria:
  - Panel questions about Supabase, AI, security, and data privacy can be answered honestly with diagrams and evidence.

### P3-05 Add deployment checklist

- Area: DevOps
- Change:
  - Document required PHP extensions, Apache/XAMPP setup, environment config, database migration order, storage permissions, backup plan, and production HTTPS requirements.
- Acceptance criteria:
  - A new machine can deploy the system using the checklist without guessing.

## Suggested Release Milestones

### Milestone 1 - Security Patch Release

- Complete P0-01 through P0-03.
- Document and review the accepted P0-04/P0-05 scope decisions before defense/deployment.
- Confirm Supabase connection works.
- Confirm no public access to private documents or biometric photos.

### Milestone 2 - Stable MVP Release

- Complete P1 items.
- Run end-to-end tests for admin, clerk, teacher, and guardian workflows.
- Freeze database schema for defense/demo.

### Milestone 3 - Maintainability Release

- Complete P2-01 through P2-07.
- Add automated tests and refactor the largest workflow files.

### Milestone 4 - Defense And Polish Release

- Complete P3 items.
- Prepare diagrams, test accounts, demo data, and fallback screenshots.

## Minimum Definition Of Ready

The system should not be called production-ready until:

- Critical authorization bugs are fixed.
- Sensitive files and biometric data are private.
- Database migrations are reproducible.
- Default passwords are removed.
- Core workflows have tests or documented verification steps.
- Deployment configuration is documented and repeatable.
- Supabase usage is accurately represented.
