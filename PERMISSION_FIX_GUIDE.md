# File Permission Fix - OpenRegister in WSL/Docker

## Problem

When developing OpenRegister in WSL with Docker, you may encounter permission errors like:

```
Failed to save 'ConfigurationSettingsHandler.php': Unable to write file 
(NoPermissions (FileSystemError): Error: EACCES: permission denied)
```

## Root Cause

This occurs because:
1. Files inside the Docker container are owned by `www-data:www-data` (uid 33, gid 33)
2. Files created by the web server have `644` permissions (`rw-r--r--`)
3. While your WSL user is in the `www-data` group, the files lack group write permissions
4. VSCode running in WSL cannot write to files without group write permissions

## Solution

### Quick Fix (Single File)

From the container as root:
```bash
docker exec -u 0 master-nextcloud-1 chmod 664 /var/www/html/apps-extra/openregister/path/to/file.php
```

### Complete Fix (All Files)

We've created a script to fix all permissions: `fix-permissions.sh`

Run it anytime you encounter permission issues:

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
./fix-permissions.sh
```

This script:
- Sets directories to `775` (rwxrwxr-x)
- Sets source files to `664` (rw-rw-r--)
- Sets scripts to `775` (rwxrwxr-x)
- Ensures ownership is `www-data:www-data`

## Permission Structure Explained

### 775 (rwxrwxr-x) for Directories
- Owner (www-data): read, write, execute
- Group (www-data): read, write, execute
- Others: read, execute

### 664 (rw-rw-r--) for Source Files
- Owner (www-data): read, write
- Group (www-data): read, write ← **This allows you to edit**
- Others: read

### Why This Works
- Your WSL user (`rubenlinde`) is in the `www-data` group
- Group write permission allows you to edit files
- The web server can still read and execute files
- Security is maintained (others can only read)

## Preventing Future Issues

### Option 1: Run fix-permissions.sh Regularly

Add to your workflow:
```bash
# After pulling code
git pull
./fix-permissions.sh

# After composer install/update
composer install
./fix-permissions.sh
```

### Option 2: Docker Compose Configuration

Add to your `docker-compose.yml`:
```yaml
services:
  nextcloud:
    user: "33:33"  # www-data
    volumes:
      - ./workspace/server/apps-extra:/var/www/html/apps-extra:rw
```

### Option 3: Use ACLs (Advanced)

If your filesystem supports ACLs:
```bash
setfacl -R -m u:rubenlinde:rwX /path/to/openregister
setfacl -R -m d:u:rubenlinde:rwX /path/to/openregister
```

## Verification

Check if a file is writable:
```bash
test -w /path/to/file && echo "✓ Writable" || echo "✗ Not writable"
```

Check file permissions:
```bash
ls -la /path/to/file
```

## Common Commands

### Check Current User and Groups
```bash
whoami
id
groups
```

### Check File Ownership and Permissions
```bash
ls -la /path/to/file
stat /path/to/file
```

### Fix Single File from WSL (requires sudo)
```bash
sudo chmod 664 /path/to/file
```

### Fix from Docker Container
```bash
docker exec -u 0 master-nextcloud-1 chmod 664 /var/www/html/apps-extra/openregister/path/to/file
```

## Security Considerations

### Why Not 666 or 777?

- `666` (rw-rw-rw-): Anyone can write - security risk
- `777` (rwxrwxrwx): Anyone can execute - major security risk
- `664` (rw-rw-r--): Only owner and group can write - secure

### File vs Directory Permissions

**Files:**
- Read (r/4): View contents
- Write (w/2): Modify contents
- Execute (x/1): Run as program/script

**Directories:**
- Read (r/4): List contents
- Write (w/2): Create/delete files
- Execute (x/1): Enter directory (required!)

## Troubleshooting

### Still Can't Write After Running fix-permissions.sh?

1. **Check you're in www-data group:**
   ```bash
   groups | grep www-data
   ```

2. **Reload group membership:**
   ```bash
   newgrp www-data
   ```
   Or log out and back in to WSL

3. **Verify file permissions:**
   ```bash
   ls -la /path/to/problematic/file
   ```
   Should show `rw-rw-r--` or `664`

4. **Check filesystem mount options:**
   ```bash
   mount | grep workspace
   ```
   Ensure not mounted as `ro` (read-only)

### Permission Denied Even as Root?

This might indicate:
- SELinux restrictions (check with `getenforce`)
- AppArmor restrictions (check `/var/log/syslog`)
- Immutable file flag (check with `lsattr`)

## Best Practices

1. **Always use fix-permissions.sh after:**
   - Installing/updating dependencies
   - Pulling code from git
   - Creating new files via Docker commands
   - Experiencing permission errors

2. **Add to .gitignore:**
   ```
   # Don't track permission scripts
   fix-permissions.sh
   ```

3. **Document in README:**
   Add permission setup instructions to your project README

4. **Automate in CI/CD:**
   Include permission fixes in deployment scripts

## References

- [Linux File Permissions](https://www.linux.com/training-tutorials/understanding-linux-file-permissions/)
- [Docker File Permissions](https://docs.docker.com/storage/bind-mounts/#configure-bind-propagation)
- [WSL File Permissions](https://learn.microsoft.com/en-us/windows/wsl/file-permissions)





