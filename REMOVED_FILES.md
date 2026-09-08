Removed / deprecated files

The following files were intentionally cleared (their executable code removed) as part of the repository cleanup. They remain in the tree as inert placeholders to avoid breaking historical references; if you prefer, delete them from Git history in a separate commit.

- app/Http/Middleware/AdminBasicAuth.php — removed; use EnsureUserIsAdmin middleware and Spatie roles instead.
- app/Models/Role.php — removed; use Spatie\Permission\Models\Role instead.
- scripts/mark_migration.php — removed; this helper was used in migration recovery and is no longer required.

To permanently remove these files from the repository, delete them and commit the change. Ensure no active references remain before doing so.
