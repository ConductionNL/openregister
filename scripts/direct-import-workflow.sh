#!/bin/bash
# Direct workflow import into n8n using Docker exec
# This bypasses the API and imports directly into the n8n database

set -e

echo "🚀 Direct n8n Workflow Import (via Docker)"
echo "==========================================="
echo ""

WORKFLOW_FILE="/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/n8n-templates/enhanced-phpqa-auto-fixer-with-loop-and-testing.json"
N8N_CONTAINER="openregister-n8n"
N8N_URL="http://localhost:5678"

# Check if workflow file exists
if [ ! -f "${WORKFLOW_FILE}" ]; then
    echo "❌ Workflow file not found: ${WORKFLOW_FILE}"
    exit 1
fi

# Check if n8n container is running
echo "📡 Checking n8n container..."
if ! docker ps | grep -q "${N8N_CONTAINER}"; then
    echo "❌ n8n container not running. Starting..."
    cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
    docker-compose --profile n8n up -d
    echo "⏳ Waiting for n8n to be ready..."
    sleep 15
fi
echo "✅ n8n container is running"
echo ""

# Copy workflow file into container
echo "📥 Copying workflow into n8n container..."
docker cp "${WORKFLOW_FILE}" "${N8N_CONTAINER}:/tmp/workflow.json"
echo "✅ Workflow copied"
echo ""

# Import using n8n CLI
echo "🔄 Importing workflow using n8n CLI..."
IMPORT_OUTPUT=$(docker exec -i "${N8N_CONTAINER}" n8n import:workflow --input=/tmp/workflow.json 2>&1 || true)

echo "${IMPORT_OUTPUT}"
echo ""

if echo "${IMPORT_OUTPUT}" | grep -qi "success\|imported\|created"; then
    echo "✅ Workflow imported successfully!"
    echo ""
    
    # Try to extract workflow ID from output
    WORKFLOW_ID=$(echo "${IMPORT_OUTPUT}" | grep -oP 'id[:\s]+\K[0-9]+' | head -1 || echo "")
    
    if [ ! -z "${WORKFLOW_ID}" ]; then
        echo "📋 Workflow ID: ${WORKFLOW_ID}"
        echo "🌐 URL: ${N8N_URL}/workflow/${WORKFLOW_ID}"
        echo ""
    fi
    
    # Open n8n in browser
    echo "🌐 Opening n8n in browser..."
    if command -v wslview &> /dev/null; then
        wslview "${N8N_URL}" 2>/dev/null &
        echo "✅ Browser opened"
    elif command -v xdg-open &> /dev/null; then
        xdg-open "${N8N_URL}" 2>/dev/null &
        echo "✅ Browser opened"
    fi
    
    echo ""
    echo "═══════════════════════════════════════════════"
    echo "  🎉 WORKFLOW IMPORTED!"
    echo "═══════════════════════════════════════════════"
    echo ""
    echo "NEXT STEPS:"
    echo "1. Open: ${N8N_URL}"
    echo "2. Login: YOUR_EMAIL@example.com / YOUR_PASSWORD"
    echo "3. Click 'Workflows' in the sidebar"
    echo "4. Find: 'Enhanced PHPQA Auto-Fixer with Loop and Testing'"
    echo "5. Click to open it"
    echo "6. Click 'Execute Workflow' button"
    echo ""
    echo "The workflow will automatically:"
    echo "• Fix PHPCS errors using AI"
    echo "• Run tests after each fix"
    echo "• Commit working changes"
    echo "• Loop until quality improves"
    echo ""
    echo "═══════════════════════════════════════════════"
    
elif echo "${IMPORT_OUTPUT}" | grep -qi "already exists"; then
    echo "⚠️  Workflow already exists in n8n"
    echo ""
    echo "The workflow was previously imported."
    echo ""
    echo "OPTIONS:"
    echo "1. Use the existing workflow in n8n"
    echo "2. Delete it first and re-import:"
    echo "   • Open ${N8N_URL}"
    echo "   • Go to Workflows"
    echo "   • Delete 'Enhanced PHPQA Auto-Fixer'"
    echo "   • Run this script again"
    echo ""
    
    # Open n8n anyway
    if command -v wslview &> /dev/null; then
        wslview "${N8N_URL}" 2>/dev/null &
        echo "✅ Browser opened - you can use the existing workflow"
    fi
    
else
    echo "⚠️  Import command completed but status unclear"
    echo ""
    echo "Please check n8n manually:"
    echo "1. Open: ${N8N_URL}"
    echo "2. Login: YOUR_EMAIL@example.com / YOUR_PASSWORD"
    echo "3. Check if workflow appears in Workflows list"
    echo ""
    
    # Open n8n
    if command -v wslview &> /dev/null; then
        wslview "${N8N_URL}" 2>/dev/null &
    fi
fi

# Cleanup
docker exec "${N8N_CONTAINER}" rm -f /tmp/workflow.json 2>/dev/null || true

echo ""

