# Migration naming convention (OPS-12)

OpenRegister migrations MUST be named:

```
Version1Date<YYYYMMDDHHMMSS>.php   ->  class Version1Date<YYYYMMDDHHMMSS>
```

i.e. the literal prefix `Version1Date` followed by a 14-digit UTC timestamp. New
migrations should always follow this scheme.

## Known inconsistency — do NOT "fix" by renaming

Four historical migrations use a different prefix scheme:

- `Version002003000Date20251013000000.php`
- `Version002004000Date20251013000000.php`
- `Version002005000Date20251013000000.php`
- `Version002006000Date20251013000000.php`

Nextcloud orders migrations with `version_compare()` on the class name. Under
`version_compare`, every `Version002...` name sorts **after** every `Version1...`
name (because `"002..." ` is compared as a higher major than `"1"`). On existing
installs this ordering is already baked in, and Nextcloud records the *applied
class name* in `oc_migrations`.

Renaming these four classes on an existing install would make Nextcloud believe
the renamed migrations have never run and attempt to re-apply them — a data
hazard. **These files are therefore intentionally left as-is.** This is a
documentation-only note (per OPS-12 in `CODE-REVIEW-IMPROVEMENT-PLAN.md`): record
the inconsistency, standardise *going forward*, and never rename already-applied
migrations.
