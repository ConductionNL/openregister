<!-- Example output — clean-env skill for OpenRegister development environment -->

# Expected Output: clean-env

## Successful run

```
$ bash .claude/scripts/clean-env.sh

[clean-env] Stopping containers...
Stopping nextcloud ... done
Stopping nextcloud_db ... done
✓ Containers stopped

[clean-env] Removing containers and volumes...
Removing nextcloud ... done
Removing nextcloud_db ... done
Removing volume nextcloud_nextcloud_data ... done
Removing volume nextcloud_db_data ... done
✓ Containers and volumes removed

[clean-env] Starting fresh containers...
Creating network "openregister_default" with the default driver
Creating volume "nextcloud_db_data"    ... done
Creating volume "nextcloud_nextcloud_data" ... done
Creating nextcloud_db ... done
Creating nextcloud    ... done
✓ Containers started

[clean-env] Waiting for Nextcloud to become ready...
..........
✓ Nextcloud is ready at http://nextcloud.local

[clean-env] Installing apps...
openregister installed and enabled.
opencatalogi installed and enabled.
softwarecatalog installed and enabled.
nldesign installed and enabled.
mydash installed and enabled.
✓ All apps installed

[clean-env] Done! Environment is clean and ready.
```

## Post-script verification

```
✅ Nextcloud accessible at http://nextcloud.local
✅ Logged in with admin/admin
✅ Apps enabled and active:
   - openregister     ✓
   - opencatalogi     ✓
   - softwarecatalog  ✓
   - nldesign         ✓
   - mydash           ✓
```

## If an app fails to enable

```bash
# Re-enable manually:
docker exec nextcloud php occ app:enable openregister
docker exec nextcloud php occ app:enable opencatalogi
```
