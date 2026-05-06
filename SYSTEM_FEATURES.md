# Academy Information System
## System Features Document

Last updated: 2026-04-11

This document summarizes the current functionality of the system based on the present codebase.

## 1. Completed Features

### 1.1 Authentication and Access Control
- Role selection portal for `Administrator`, `Teacher`, and `Guardian`
- Role-specific login entry points that route to a unified login flow
- Email and password login with role validation
- Account lockout after repeated failed login attempts
- Account activation check (`active` and `inactive`)
- Secure session handling and role-based page protection
- Logout with audit logging
- Google OAuth callback now disabled (email/password only login path)

### 1.2 Admin Features
- Admin dashboard with KPI cards and operational summaries
- Student management (create, edit, delete, search, pagination)
- Guardian management (create, edit, delete, search, pagination)
- Teacher management (create, edit, delete, search, pagination)
- User account management (create, role change, activate or deactivate, reset password, search, pagination)
- Subject management (create, edit, delete)
- Section management (create, edit, delete, adviser assignment, capacity)
- Schedule management (create, edit, delete class schedules)
- Registrar enrollment chain (guardian requirement uploads, Enrollment Clerk review, payment assessment, cashier payment, and final submission to teachers)
- Grades management (view student grades, admin override of grade components and final grade)
- Calendar management (create, edit, delete events, visual monthly calendar, event list table)
- Attendance management with face recognition (register face profiles, save descriptors and images, scan and mark attendance, recognition logs, today's attendance)
- Financial ledger and payments (record manual payments, view payment records by status, export CSV)

### 1.3 Teacher Features
- Teacher dashboard with subject and section workload summary
- View assigned classes
- Encode and update grades for assigned classes
- Auto-computation of final grades
- Weekly class schedule view

### 1.4 Guardian Features
- Guardian dashboard with linked students and payment snapshot
- Multi-step enrollment intake with PSA, medical records, previous school records, and parent/guardian data uploads
- Student grades viewing with filters (student, school year, term)
- GWA computation based on available grades
- Schedule page with weekly class schedule and school calendar tabs
- Payment history with sorting and running total
- Printable report card
- Printable payment receipt
- Printable certificate of enrollment
- Profile view page
- Profile edit page with optional password update

### 1.5 Shared Platform Features
- Role-aware sidebar and navigation
- Flash notifications
- Pagination helpers used across list pages
- Centralized audit log entries for major actions
- CSRF token validation for forms and attendance AJAX requests
- Output escaping helper for safe HTML rendering
- PDO-based database access with prepared statements
- Application error logging to `logs/error.log`

## 2. Planned and Recommended Features

### 2.1 Security and Hardening
- Remove unused legacy OAuth constants and stale setup notes
- Enforce HTTPS-only secure cookies for production
- Add stronger password policy enforcement and password expiry options
- Add account unlock workflow and optional admin unlock action
- Add rate limiting for sensitive admin actions
- Add optional two-factor authentication for admin accounts

### 2.2 Attendance Improvements
- Add manual attendance override UI with reason logging
- Add attendance correction request workflow
- Add attendance analytics and downloadable reports
- Add recognition threshold settings in admin UI
- Add duplicate-face detection warnings during profile registration

### 2.3 Academic Workflow Enhancements
- Add grade publishing status (draft and published)
- Add grade locking windows by term
- Add printable report cards and transcript export
- Add automated subject assignment and teaching load balancing

### 2.4 Enrollment and Finance Enhancements
- Add enrollment fee configuration (not hardcoded)
- Add installment tracking and balance computation
- Add official receipt generation
- Add payment reminders and overdue alerts
- Add finance dashboard trends by month and term

### 2.5 User Experience and Operations
- Add in-app notification center
- Add announcement board per role
- Add richer dashboard charts
- Add backup and restore utility scripts
- Add automated test coverage for critical flows

## 3. Notes
- Guardian self-signup is no longer part of the active login flow.
- Guardian accounts are intended to be created by administrators.
- Some setup documentation may still contain older OAuth or signup references and should be updated for consistency.
