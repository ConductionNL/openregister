#!/bin/bash
set -e

echo "======================================"
echo "OpenRegister Developer Auto-Setup Starting..."
echo "======================================"

# Function to wait for Nextcloud to be ready
wait_for_nextcloud() {
    echo "Waiting for Nextcloud to initialize..."
    until su -s /bin/bash www-data -c "php /var/www/html/occ status" 2>/dev/null | grep -q "installed: true"; do
        echo "Nextcloud not ready yet, waiting..."
        sleep 5
    done
    echo "✓ Nextcloud is ready!"
}

# Wait for Nextcloud to be initialized
wait_for_nextcloud

# Navigate to OpenRegister directory
cd /var/www/html/custom_apps/openregister

# Install Composer dependencies if needed
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    su -s /bin/bash www-data -c "cd /var/www/html/custom_apps/openregister && composer install --no-dev --no-interaction --prefer-dist" || {
        echo "⚠ Composer install failed, but continuing..."
    }
else
    echo "✓ Composer dependencies already installed"
fi

# Install NPM dependencies and build if needed
if [ ! -d "node_modules" ]; then
    echo "Installing NPM dependencies..."
    su -s /bin/bash www-data -c "cd /var/www/html/custom_apps/openregister && npm install --prefer-offline --no-audit" || {
        echo "⚠ NPM install failed, but continuing..."
    }
else
    echo "✓ NPM dependencies already installed"
fi

# Build frontend if needed
if [ ! -d "js" ] || [ -z "$(ls -A js 2>/dev/null)" ]; then
    echo "Building frontend assets..."
    su -s /bin/bash www-data -c "cd /var/www/html/custom_apps/openregister && npm run build" || {
        echo "⚠ NPM build failed, but continuing..."
    }
else
    echo "✓ Frontend assets already built"
fi

# Enable OpenRegister app
echo "Enabling OpenRegister app..."
if su -s /bin/bash www-data -c "php /var/www/html/occ app:enable openregister" 2>/dev/null; then
    echo "✓ OpenRegister app enabled successfully!"
else
    # Check if already enabled
    if su -s /bin/bash www-data -c "php /var/www/html/occ app:list" 2>/dev/null | grep -q "openregister"; then
        echo "✓ OpenRegister app is already enabled"
    else
        echo "⚠ Failed to enable OpenRegister app"
    fi
fi

# Check app status
echo ""
echo "OpenRegister Status:"
su -s /bin/bash www-data -c "php /var/www/html/occ app:list | grep openregister" || echo "App not found in list"

echo ""
echo "======================================"
echo "OpenRegister Developer Auto-Setup Complete!"
echo "======================================"
echo ""
echo "Developer Mode:"
echo "- Local code is mounted from host"
echo "- Changes to files will be reflected immediately"
echo "- Run 'npm run watch' on host for automatic rebuilds"
echo ""
echo "Next Steps:"
echo "1. Access Nextcloud at http://localhost:8080"
echo "2. Login with admin/admin"
echo "3. Setup Solr: docker exec -u 33 nextcloud-dev php /var/www/html/occ openregister:solr:manage setup"
echo "4. Download Ollama models:"
echo "   docker exec openregister-ollama ollama pull nomic-embed-text"
echo "   docker exec openregister-ollama ollama pull llama3.1"
echo ""

