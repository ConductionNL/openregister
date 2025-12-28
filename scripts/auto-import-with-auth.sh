#!/bin/bash
# Auto-import Enhanced PHPQA Workflow via n8n API with Authentication
# This script logs in to n8n and imports the workflow automatically

set -e

echo "🚀 Auto-Importing Workflow to n8n (with Auth)"
echo "=============================================="
echo ""

# Configuration
N8N_URL="http://localhost:5678"
N8N_EMAIL="ruben@conduction.nl"
N8N_PASSWORD="4257"
WORKFLOW_FILE="/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/n8n-templates/enhanced-phpqa-auto-fixer-with-loop-and-testing.json"

# Check if n8n is running
echo "📡 Checking n8n status..."
if ! curl -f -s "${N8N_URL}/healthz" > /dev/null 2>&1; then
    echo "❌ n8n is not running. Starting n8n..."
    cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
    docker-compose --profile n8n up -d
    echo "⏳ Waiting for n8n to be ready..."
    sleep 15
fi
echo "✅ n8n is running"
echo ""

# Check if workflow file exists
if [ ! -f "${WORKFLOW_FILE}" ]; then
    echo "❌ Workflow file not found: ${WORKFLOW_FILE}"
    exit 1
fi

# Step 1: Login to get cookie/session
echo "🔐 Logging in to n8n..."
LOGIN_RESPONSE=$(curl -s -c /tmp/n8n-cookies.txt -X POST \
    "${N8N_URL}/rest/login" \
    -H "Content-Type: application/json" \
    -d "{\"email\":\"${N8N_EMAIL}\",\"password\":\"${N8N_PASSWORD}\"}" 2>&1)

# Check if login was successful
if echo "$LOGIN_RESPONSE" | grep -q "error\|Error\|401\|403"; then
    echo "❌ Login failed"
    echo "Response: ${LOGIN_RESPONSE}"
    echo ""
    echo "📋 Please login manually and import:"
    echo "1. Open: ${N8N_URL}"
    echo "2. Login: ${N8N_EMAIL} / ${N8N_PASSWORD}"
    echo "3. Import workflow from: ${WORKFLOW_FILE}"
    exit 1
fi

echo "✅ Logged in successfully"
echo ""

# Step 2: Read and prepare workflow JSON
echo "📄 Reading workflow file..."
WORKFLOW_JSON=$(cat "${WORKFLOW_FILE}")

# Step 3: Import workflow using authenticated session
echo "📥 Importing workflow..."
IMPORT_RESPONSE=$(curl -s -b /tmp/n8n-cookies.txt -X POST \
    "${N8N_URL}/rest/workflows" \
    -H "Content-Type: application/json" \
    -d "${WORKFLOW_JSON}" 2>&1)

# Check if import was successful
if echo "$IMPORT_RESPONSE" | grep -q '"id"'; then
    echo "✅ Workflow imported successfully!"
    echo ""
    
    # Extract workflow ID
    WORKFLOW_ID=$(echo "$IMPORT_RESPONSE" | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4 || echo "$IMPORT_RESPONSE" | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)
    
    if [ ! -z "$WORKFLOW_ID" ]; then
        echo "📋 Workflow Details:"
        echo "   ID: ${WORKFLOW_ID}"
        echo "   URL: ${N8N_URL}/workflow/${WORKFLOW_ID}"
        echo ""
        
        # Try to activate the workflow
        echo "🔄 Activating workflow..."
        ACTIVATE_RESPONSE=$(curl -s -b /tmp/n8n-cookies.txt -X PATCH \
            "${N8N_URL}/rest/workflows/${WORKFLOW_ID}" \
            -H "Content-Type: application/json" \
            -d '{"active":false}' 2>&1)
        
        echo ""
        echo "🌐 Opening workflow in browser..."
        
        # Open in browser
        if command -v wslview &> /dev/null; then
            wslview "${N8N_URL}/workflow/${WORKFLOW_ID}" 2>/dev/null &
            echo "✅ Browser opened automatically"
        elif command -v xdg-open &> /dev/null; then
            xdg-open "${N8N_URL}/workflow/${WORKFLOW_ID}" 2>/dev/null &
            echo "✅ Browser opened automatically"
        else
            echo "ℹ️  Please open: ${N8N_URL}/workflow/${WORKFLOW_ID}"
        fi
        
        echo ""
        echo "═══════════════════════════════════════════════"
        echo "  🎉 SETUP COMPLETE!"
        echo "═══════════════════════════════════════════════"
        echo ""
        echo "The workflow is now loaded in n8n!"
        echo ""
        echo "NEXT STEPS:"
        echo "1. The browser should open automatically"
        echo "2. You'll see the workflow canvas"
        echo "3. Click 'Execute Workflow' button (top right)"
        echo "4. Watch it fix your PHPCS errors!"
        echo ""
        echo "WHAT WILL HAPPEN:"
        echo "• Run composer phpqa (find quality issues)"
        echo "• Send errors to AI (Ollama CodeLlama)"
        echo "• Apply fixes automatically"
        echo "• Run Newman tests (verify nothing broke)"
        echo "• Commit changes if tests pass"
        echo "• Loop until quality improves (max 5 iterations)"
        echo ""
        echo "ESTIMATED TIME: 15-30 minutes"
        echo ""
        echo "═══════════════════════════════════════════════"
        echo ""
    else
        echo "⚠️  Could not extract workflow ID"
        echo "Response: ${IMPORT_RESPONSE}"
    fi
else
    echo "❌ Import failed"
    echo "Response: ${IMPORT_RESPONSE}"
    echo ""
    echo "📋 Fallback: Manual import"
    echo "1. Open: ${N8N_URL}"
    echo "2. Already logged in? Great!"
    echo "3. Workflows → Add workflow → Import from file"
    echo "4. Select: ${WORKFLOW_FILE}"
fi

# Cleanup
rm -f /tmp/n8n-cookies.txt

echo ""



