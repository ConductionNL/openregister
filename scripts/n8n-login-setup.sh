#!/bin/bash
# n8n Setup Script
# This script helps you get started with n8n

echo "🚀 n8n Setup Guide"
echo "=========================================="
echo ""

# Check if n8n is running
echo "📡 Checking if n8n is accessible..."
if curl -f -s http://localhost:5678/healthz > /dev/null 2>&1; then
    echo "✅ n8n is running at http://localhost:5678"
else
    echo "❌ n8n is not responding. Starting n8n..."
    cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
    docker-compose --profile n8n up -d
    echo "⏳ Waiting for n8n to be ready..."
    sleep 10
fi

echo ""
echo "🌐 Opening n8n in your browser..."
echo ""
echo "═══════════════════════════════════════════════════════"
echo "  LOGIN INFORMATION"
echo "═══════════════════════════════════════════════════════"
echo ""
echo "  URL:      http://localhost:5678"
echo "  Email:    YOUR_EMAIL@example.com"
echo "  Password: YOUR_PASSWORD"
echo ""
echo "═══════════════════════════════════════════════════════"
echo ""

# Try to open in browser (if available)
if command -v xdg-open &> /dev/null; then
    xdg-open "http://localhost:5678" 2>/dev/null &
    echo "✅ Browser opened automatically"
elif command -v wslview &> /dev/null; then
    wslview "http://localhost:5678" 2>/dev/null &
    echo "✅ Browser opened automatically (WSL)"
else
    echo "ℹ️  Please open http://localhost:5678 manually in your browser"
fi

echo ""
echo "📋 NEXT STEPS:"
echo ""
echo "1. Log in to n8n with the credentials above"
echo ""
echo "2. Import the Enhanced Workflow:"
echo "   • Click 'Workflows' → 'Add workflow'"
echo "   • Click the ⋮ menu → 'Import from file'"
echo "   • Navigate to:"
echo "     /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/n8n-templates/"
echo "   • Select: enhanced-phpqa-auto-fixer-with-loop-and-testing.json"
echo "   • Click 'Import'"
echo ""
echo "3. Configure the workflow (optional):"
echo "   • Click the 'Configuration' node"
echo "   • Adjust settings if needed (defaults are good)"
echo ""
echo "4. Execute the workflow:"
echo "   • Click 'Execute Workflow' button (top right)"
echo "   • Watch it run!"
echo ""
echo "═══════════════════════════════════════════════════════"
echo ""
echo "📚 Documentation available at:"
echo "  • ENHANCED_WORKFLOW_GUIDE.md"
echo "  • N8N_LOGIN_AND_START_GUIDE.md"
echo ""
echo "🎉 Ready to fix your PHPCS errors automatically!"
echo ""



