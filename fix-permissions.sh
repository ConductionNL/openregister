#!/bin/bash

# Fix Permissions Script for OpenRegister
# This script fixes file permissions in the OpenRegister app directory
# to allow editing from WSL while maintaining proper web server access

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${SCRIPT_DIR}"

echo "======================================"
echo "Fixing OpenRegister File Permissions"
echo "======================================"
echo ""

# Check if running inside the repository
if [ ! -f "${APP_DIR}/appinfo/info.xml" ]; then
    echo "Error: This script must be run from the openregister app directory"
    exit 1
fi

# Check if we're in a Docker environment
if ! docker ps | grep -q "master-nextcloud-1"; then
    echo "Warning: master-nextcloud-1 container not found"
    echo "Are you running in the correct Docker environment?"
    exit 1
fi

echo "Setting permissions for files..."
docker exec -u 0 master-nextcloud-1 bash -c "
    # Set directories to 775 (rwxrwxr-x)
    find /var/www/html/apps-extra/openregister -type d -exec chmod 775 {} \;
    
    # Set PHP files to 664 (rw-rw-r--)
    find /var/www/html/apps-extra/openregister -type f -name '*.php' -exec chmod 664 {} \;
    
    # Set Vue files to 664 (rw-rw-r--)
    find /var/www/html/apps-extra/openregister -type f -name '*.vue' -exec chmod 664 {} \;
    
    # Set JavaScript files to 664 (rw-rw-r--)
    find /var/www/html/apps-extra/openregister -type f -name '*.js' -exec chmod 664 {} \;
    
    # Set JSON files to 664 (rw-rw-r--)
    find /var/www/html/apps-extra/openregister -type f -name '*.json' -exec chmod 664 {} \;
    
    # Set XML files to 664 (rw-rw-r--)
    find /var/www/html/apps-extra/openregister -type f -name '*.xml' -exec chmod 664 {} \;
    
    # Set Markdown files to 664 (rw-rw-r--)
    find /var/www/html/apps-extra/openregister -type f -name '*.md' -exec chmod 664 {} \;
    
    # Set shell scripts to 775 (rwxrwxr-x)
    find /var/www/html/apps-extra/openregister -type f -name '*.sh' -exec chmod 775 {} \;
    
    # Ensure ownership is correct
    chown -R www-data:www-data /var/www/html/apps-extra/openregister
    
    echo 'Permissions updated successfully'
"

echo ""
echo "======================================"
echo "✓ Permissions Fixed"
echo "======================================"
echo ""
echo "File permissions:"
echo "  - Directories: 775 (rwxrwxr-x)"
echo "  - Source files: 664 (rw-rw-r--)"
echo "  - Scripts: 775 (rwxrwxr-x)"
echo ""
echo "You should now be able to edit files from WSL"
echo ""



