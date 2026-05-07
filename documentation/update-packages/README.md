# System Updater — Update Packages

This project includes a secure, admin‑only System Updater that applies code updates from a ZIP package you upload via the Admin panel.

Use this document to build valid update packages, understand the manifest format, and test the updater locally with a demo package.


## Where to access the Updater
- Admin > System Updates (route: /admin/settings/system-updates)
- Permissions: AdminPermissionEnum::SETTING_SYSTEM_EDIT() (Super Admin bypass supported)


## ZIP Package Structure (required)
Your update ZIP must have update.json at the ZIP root.

ZIP root
├─ update.json                  ← required manifest
├─ app/…                        ← optional files/folders to add/replace
├─ config/…                     ← optional
├─ routes/…                     ← optional
├─ resources/…                  ← optional
├─ public/…                     ← optional
├─ database/
│  └─ migrations/…             ← optional migrations to copy & run
└─ packages/…                   ← optional

Only the following target roots are allowed by the updater for safety:
- app
- routes
- config
- resources
- public
- database
- packages

Any attempt to write outside these whitelisted paths aborts the update.


## Manifest format (update.json)
A JSON file describing the version, optional notes, and a list of actions to perform.

Example:
{
  "version": "1.0.1",
  "min_app_version": "1.0.0",
  "notes": "Fixes & improvements",
  "run_migrations": true,
  "run_seeders": false,
  "actions": [
    {"type": "add", "source": "resources/new-views", "target": "resources/views/new-views"},
    {"type": "replace", "source": "app/Services/NewService.php", "target": "app/Services/NewService.php"},
    {"type": "delete", "target": "public/old-file.js"}
  ],
  "commands": [
    "config:cache",
    "view:cache"
  ]
}

Fields:
- version (string, required): Update package version (stored in history).
- min_app_version (string, optional): Informational minimum app version.
- notes (string, optional): Free‑form notes stored with the history entry.
- run_migrations (bool, optional, default true): When true and the package contains database/migrations, they’ll be copied and migrated.
- run_seeders (bool, optional, default false): When true and the package contains database/seeders, they’ll be copied and db:seed will run.
- actions (array): List of file operations in order.
  - type: add | replace | delete
  - source: path within the ZIP (required for add/replace)
  - target: project path to write/delete (required for all)
- commands (array, optional): Artisan commands to run after file changes & migrations, each as a string. Note: --force is added only to migrate/db:seed; other commands run as-is.


## Execution flow
1) ZIP is stored under storage/app/updates.
2) Extracted to a temporary dir storage/app/updates/tmp/{id}.
3) update.json is parsed.
4) All actions are validated and backup is created for replaced/deleted targets under storage/app/updates/backups/{id}.
5) Actions are applied (add/replace/delete) safely under whitelisted paths only.
6) If the ZIP contains database/migrations, they are copied into database/migrations and php artisan migrate --force is executed.
7) Optional Artisan commands from manifest are executed with --force.
8) Caches are cleared (optimize:clear).
9) Update history is recorded in system_updates table.
10) On error, file changes are rolled back from the backup.


## Building a demo update (ready for testing)
This repository includes a demo update package you can zip and upload:
- Source: documentation/update-packages/demo-update
- Build script: scripts/build-demo-update.php
- Output ZIP: storage/app/updates/demo-update-1.0.1.zip

To build:
- php scripts/build-demo-update.php

Or manually zip the contents of documentation/update-packages/demo-update such that update.json sits at the ZIP root.


## Applying the demo update
1) Ensure migrations are up:
   - php artisan migrate --force
2) Build the demo ZIP (see above) or zip manually.
3) In Admin > System Updates, upload the generated ZIP.
4) The updater will:
   - Add a sample Blade view resources/views/new-views/demo.blade.php
   - Copy and run a benign demo migration
   - Clear caches
   - Record the update in history


## Safety & recommendations
- The updater verifies paths and restricts writes to specific directories to prevent path traversal and tampering.
- It creates backups for deleted/replaced targets and restores them on failure.
- Keep your own VCS and backups. This updater is a convenience for client deployments.
- You can extend update.json to add checksums per file for extra integrity verification in the future.
