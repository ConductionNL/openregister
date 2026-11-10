# Seed objects follow the shipped content

## Why
On :8080 only 56 of dossiq's 246 status types have a `phase`. dossiq ships `phase` on every status type in its register and in register.d, but the register loads at 0.20.73 through 0.20.75 never wrote it. Most seed objects ship without a `@self.version`, so the import compares "1.0.0" with the version it last shipped (also "1.0.0"), counts the object as unchanged and skips it. Content that changed without a version bump never arrives. For schemas this was fixed long ago ("content is authoritative"), but not for seed objects.

## What changes
- For each seed, the import remembers the hash of the content it last shipped, next to the version (`imported_seed_hashes_<app>`).
- When the version did not move but the shipped content differs from the remembered hash, the seed is written as a version bump would be.
- When the shipped content is unchanged, the stored object stays as it is, including any edits.
- A seed stored before the import kept a hash only gains the fields it lacks (null or absent). Its other values, edited or not, are kept.
- The definition hash behind the app-level fast-skip now includes a generation of the import's rules. An instance whose stored hash predates this change therefore imports each app once more, and the stale seeds heal without a forced load.
