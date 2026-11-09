# Single-site config publication

1. Lock the source and published tables in the upstream order; compare the edited site's version under lock.
2. Build one schema-4 Site from source, initializing only genuinely absent white-label policy and email-template data. Verify existing immutable references.
3. Commit source, publication, site snapshot, domain ownership and audits together. Never write the historical global snapshot table.
4. Use the tenant's first eligible site, reject mismatched site IDs and do not partially publish tenant-wide changes.
5. Exercise first publication, no-op, stale writes, cross-site isolation, integrity failures and rollback with isolated fixtures. Do not publish packages or change live data.
