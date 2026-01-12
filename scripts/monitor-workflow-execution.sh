#!/bin/bash
# Real-time workflow execution monitor
# Run this after clicking "Execute Workflow" in n8n

WORKFLOW_ID="kdksUhMagacHR479"

echo "════════════════════════════════════════════════"
echo "  🔍 N8N WORKFLOW EXECUTION MONITOR"
echo "════════════════════════════════════════════════"
echo ""
echo "Workflow: Enhanced PHPQA Auto-Fixer"
echo "ID: $WORKFLOW_ID"
echo ""
echo "⏳ Waiting for execution to start..."
echo "   (Click 'Execute Workflow' in n8n now!)"
echo ""

# Wait for execution to start (max 30 seconds)
for i in {1..30}; do
    EXEC_ID=$(docker exec openregister-n8n sqlite3 /home/node/.n8n/database.sqlite \
        "SELECT id FROM execution_entity WHERE workflowId='$WORKFLOW_ID' ORDER BY startedAt DESC LIMIT 1;" 2>/dev/null | tr -d '\r\n')
    
    if [ ! -z "$EXEC_ID" ]; then
        echo "✅ Execution started! ID: $EXEC_ID"
        echo ""
        break
    fi
    sleep 1
done

if [ -z "$EXEC_ID" ]; then
    echo "❌ No execution detected after 30 seconds"
    echo "   Please click 'Execute Workflow' in n8n and run this script again"
    exit 1
fi

# Monitor execution status
echo "📊 Monitoring execution status..."
echo "────────────────────────────────────────────────"
echo ""

for i in {1..60}; do
    # Get execution status
    EXEC_DATA=$(docker exec openregister-n8n sqlite3 /home/node/.n8n/database.sqlite \
        "SELECT status, finished, stoppedAt FROM execution_entity WHERE id='$EXEC_ID';" 2>/dev/null)
    
    STATUS=$(echo "$EXEC_DATA" | cut -d'|' -f1)
    FINISHED=$(echo "$EXEC_DATA" | cut -d'|' -f2)
    
    echo "[$i/60] Status: $STATUS | Finished: $FINISHED"
    
    # Check if execution completed
    if [ "$FINISHED" = "1" ] || [ "$FINISHED" = "true" ]; then
        echo ""
        echo "🎉 Execution completed!"
        echo ""
        
        # Get final status
        if [ "$STATUS" = "success" ]; then
            echo "✅ STATUS: SUCCESS"
        else
            echo "❌ STATUS: $STATUS"
        fi
        
        echo ""
        echo "📋 Execution Summary:"
        docker exec openregister-n8n sqlite3 /home/node/.n8n/database.sqlite \
            "SELECT 'Start Time: ' || startedAt, 'Stop Time: ' || stoppedAt, 'Status: ' || status, 'Mode: ' || mode \
             FROM execution_entity WHERE id='$EXEC_ID';" 2>/dev/null
        
        echo ""
        echo "🔍 View details in n8n:"
        echo "   http://localhost:5678/execution/$EXEC_ID"
        
        exit 0
    fi
    
    # Show last log entries
    if [ $((i % 5)) -eq 0 ]; then
        echo ""
        echo "📄 Recent logs:"
        docker logs --tail=5 openregister-n8n 2>&1 | grep -v "migration" | tail -3
        echo ""
    fi
    
    sleep 2
done

echo ""
echo "⏱️ Monitoring timeout (2 minutes)"
echo "   Execution may still be running"
echo "   Check n8n UI for current status"
echo ""



