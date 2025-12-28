#!/bin/bash

# n8n Workflow Auto-Import Script
# This script ensures the AI-powered PHPQA workflow is imported and activated on startup

set -e

WORKFLOW_FILE="/tmp/ai-powered-phpqa-fixer-complete.json"
DB_PATH="/root/.n8n/database.sqlite"
PROJECT_ID="nZ70rwLC4cbgAwCw"

echo "🔍 Checking if workflow exists in n8n..."

# Check if workflow already exists
WORKFLOW_COUNT=$(sqlite3 "$DB_PATH" "SELECT COUNT(*) FROM workflow_entity WHERE name = 'AI-Powered PHPQA Auto-Fixer (Complete)';" 2>/dev/null || echo "0")

if [ "$WORKFLOW_COUNT" -eq "0" ]; then
    echo "📥 Workflow not found. Importing..."
    
    # Import workflow
    n8n import:workflow --input="$WORKFLOW_FILE"
    
    # Get the workflow ID
    WORKFLOW_ID=$(sqlite3 "$DB_PATH" "SELECT id FROM workflow_entity WHERE name = 'AI-Powered PHPQA Auto-Fixer (Complete)' LIMIT 1;")
    
    echo "✅ Workflow imported with ID: $WORKFLOW_ID"
    
    # Activate and assign to project
    sqlite3 "$DB_PATH" "
        UPDATE workflow_entity SET active = 1, parentFolderId = '$PROJECT_ID' WHERE id = '$WORKFLOW_ID';
        INSERT OR IGNORE INTO shared_workflow (workflowId, projectId, role) VALUES ('$WORKFLOW_ID', '$PROJECT_ID', 'workflow:owner');
    "
    
    echo "✅ Workflow activated and assigned to project"
else
    echo "✅ Workflow already exists ($WORKFLOW_COUNT found)"
    
    # Ensure it's activated
    sqlite3 "$DB_PATH" "
        UPDATE workflow_entity 
        SET active = 1, parentFolderId = '$PROJECT_ID' 
        WHERE name = 'AI-Powered PHPQA Auto-Fixer (Complete)';
    "
    
    echo "✅ Workflow ensured active"
fi

echo "🎉 n8n workflow setup complete!"

