#!/bin/bash
set -e

echo "======================================"
echo "OpenRegister App Store Auto-Setup Starting..."
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

# Check if OpenRegister is already installed
if su -s /bin/bash www-data -c "php /var/www/html/occ app:list" 2>/dev/null | grep -q "openregister"; then
    echo "✓ OpenRegister is already installed"
else
    echo "Downloading OpenRegister from App Store..."
    # Install from app store using occ
    if su -s /bin/bash www-data -c "php /var/www/html/occ app:install openregister" 2>/dev/null; then
        echo "✓ OpenRegister downloaded and installed from App Store!"
    else
        echo "⚠ Failed to install OpenRegister from App Store"
        echo "  This might be because:"
        echo "  - The app is not yet available in the app store"
        echo "  - Network connectivity issues"
        echo "  - App store is temporarily unavailable"
        echo ""
        echo "  You can manually install it later with:"
        echo "  docker exec -u 33 nextcloud php /var/www/html/occ app:install openregister"
        exit 0
    fi
fi

# Enable OpenRegister app if not already enabled
echo "Enabling OpenRegister app..."
if su -s /bin/bash www-data -c "php /var/www/html/occ app:enable openregister" 2>/dev/null; then
    echo "✓ OpenRegister app enabled successfully!"
else
    # Check if already enabled
    if su -s /bin/bash www-data -c "php /var/www/html/occ app:list --enabled" 2>/dev/null | grep -q "openregister"; then
        echo "✓ OpenRegister app is already enabled"
    else
        echo "⚠ Failed to enable OpenRegister app"
    fi
fi

# Check app status
echo ""
echo "OpenRegister Status:"
su -s /bin/bash www-data -c "php /var/www/html/occ app:list | grep openregister" || echo "App not found in list"

# Show version info
echo ""
echo "OpenRegister Version:"
su -s /bin/bash www-data -c "php /var/www/html/occ app:info openregister" 2>/dev/null | grep "Version:" || echo "Version info not available"

echo ""
echo "======================================"
echo "OpenRegister Auto-Setup Complete!"
echo "======================================"
echo ""
echo "Production Mode:"
echo "- App installed from Nextcloud App Store"
echo "- Ready for testing and evaluation"
echo ""
echo "Next Steps:"
echo "1. Access Nextcloud at http://localhost:8080"
echo "2. Login with admin/admin"
echo "3. Setup Solr: docker exec -u 33 nextcloud php /var/www/html/occ openregister:solr:manage setup"
echo "4. Download Ollama models:"
echo "   docker exec openregister-ollama ollama pull nomic-embed-text"
echo "   docker exec openregister-ollama ollama pull llama3.1"
echo ""
