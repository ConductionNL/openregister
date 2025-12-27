#!/bin/bash
# Auto-import Enhanced PHPQA Workflow via n8n API
# This script automatically loads the workflow into n8n

set -e

echo "🚀 Auto-Importing Workflow to n8n via API"
echo "=========================================="
echo ""

# Configuration
N8N_URL="http://localhost:5678"
N8N_API_URL="${N8N_URL}/api/v1"
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

# Check again
if curl -f -s "${N8N_URL}/healthz" > /dev/null 2>&1; then
    echo "✅ n8n is running"
else
    echo "❌ n8n failed to start"
    exit 1
fi

echo ""
echo "📥 Importing workflow from:"
echo "   ${WORKFLOW_FILE}"
echo ""

# Check if workflow file exists
if [ ! -f "${WORKFLOW_FILE}" ]; then
    echo "❌ Workflow file not found!"
    exit 1
fi

# Read the workflow JSON
WORKFLOW_JSON=$(cat "${WORKFLOW_FILE}")

# Import workflow via API
# Note: n8n API might require authentication, trying without first
echo "🔄 Sending workflow to n8n API..."

RESPONSE=$(curl -s -w "\n%{http_code}" -X POST \
    "${N8N_API_URL}/workflows" \
    -H "Content-Type: application/json" \
    -d "${WORKFLOW_JSON}" 2>&1 || echo "401")

HTTP_CODE=$(echo "$RESPONSE" | tail -n 1)
BODY=$(echo "$RESPONSE" | head -n -1)

echo "Response code: ${HTTP_CODE}"

if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "201" ]; then
    echo "✅ Workflow imported successfully!"
    echo ""
    WORKFLOW_ID=$(echo "$BODY" | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
    echo "Workflow ID: ${WORKFLOW_ID}"
    echo ""
    echo "🌐 Open in browser:"
    echo "   ${N8N_URL}/workflow/${WORKFLOW_ID}"
    echo ""
    
    # Try to open in browser
    if command -v wslview &> /dev/null; then
        wslview "${N8N_URL}/workflow/${WORKFLOW_ID}" 2>/dev/null &
        echo "✅ Browser opened automatically"
    elif command -v xdg-open &> /dev/null; then
        xdg-open "${N8N_URL}/workflow/${WORKFLOW_ID}" 2>/dev/null &
        echo "✅ Browser opened automatically"
    fi
    
    echo ""
    echo "🎯 READY TO USE!"
    echo "   Click 'Execute Workflow' to start fixing PHPCS errors"
    echo ""
    
elif [ "$HTTP_CODE" = "401" ] || [ "$HTTP_CODE" = "403" ]; then
    echo "⚠️  Authentication required"
    echo ""
    echo "n8n requires login before using the API."
    echo "Please use the manual import method:"
    echo ""
    echo "1. Open: ${N8N_URL}"
    echo "2. Login: ruben@conduction.nl / 4257"
    echo "3. Workflows → Add workflow → Import from file"
    echo "4. Select: ${WORKFLOW_FILE}"
    echo ""
    echo "Or try the alternative API import script..."
    
else
    echo "⚠️  API import failed (HTTP ${HTTP_CODE})"
    echo ""
    echo "Response: ${BODY}"
    echo ""
    echo "📋 Manual import instructions:"
    echo ""
    echo "1. Open: ${N8N_URL}"
    echo "2. Login: ruben@conduction.nl / 4257"
    echo "3. Workflows → Add workflow → Import from file"
    echo "4. Select: ${WORKFLOW_FILE}"
    echo ""
fi

