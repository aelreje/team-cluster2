# API Folder Organization

API handlers are grouped by ownership to keep related logic together:

- `admin/` – admin-only endpoints
- `coach/` – coach-focused endpoints
- `employee/` – employee-focused endpoints
- `shared/` – endpoints shared across roles

Top-level `backend/api/*.php` wrappers were removed so files only exist once inside these subfolders.
