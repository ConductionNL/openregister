# One install or removal of an example set runs at a time

## Why
On :8080 two installs of `zuiddrecht` ran at the same time, 16 seconds apart (requests `1yPF…` and `2UGK…`, 27 Oct 15:55). An install reads the record once and writes it whole after every step, so each run wrote its own copy and the later writer dropped what the other had recorded. One run's 182 objects stayed on the instance without a record entry. Among them was the Woo case "Kosten nieuw zwembad" (0eb3d6b0, 2026-4871): every later run tried to write it again and dossiq refused it as a duplicate.

## What changes
- An install and a removal take an exclusive lock on their set through Nextcloud's locking provider, and let go of it when they end, also when they fail.
- A run that finds the set held writes nothing and says so: an install answers with a refusal, a removal with a failed line. The daily refresh goes through the same install, so it waits its turn too.
- Other sets are not held up.

## Not in this change
- The 182 objects the lost run left are not cleaned up here. Deleting them is an administrator's decision.
- A run that was killed holds the set until its lock expires (the locking provider's TTL).
