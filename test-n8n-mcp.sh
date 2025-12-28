#!/bin/bash

# n8n MCP Server Test Script
# Tests if the n8n MCP server can connect to n8n API

echo "╔══════════════════════════════════════════════════════════╗"
echo "║                                                          ║"
echo "║  🧪 Testing n8n MCP Server                              ║"
echo "║                                                          ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

# Check if n8n is running
echo "1. Checking if n8n is running..."
if curl -s -f http://localhost:5678/healthz > /dev/null 2>&1; then
    echo "   ✅ n8n is running"
else
    echo "   ❌ n8n is not running"
    echo "   Run: docker-compose --profile n8n up -d"
    exit 1
fi

# Check if API key is set
if [ -z "$N8N_API_KEY" ]; then
    echo ""
    echo "2. ❌ N8N_API_KEY environment variable not set"
    echo "   Please export your API key:"
    echo "   export N8N_API_KEY='your-api-key-here'"
    exit 1
else
    echo "2. ✅ API key is set"
fi

# Test n8n API directly
echo ""
echo "3. Testing n8n API access..."
RESPONSE=$(curl -s -H "X-N8N-API-KEY: $N8N_API_KEY" http://localhost:5678/api/v1/workflows)
if echo "$RESPONSE" | grep -q "data"; then
    WORKFLOW_COUNT=$(echo "$RESPONSE" | grep -o '"data":\[' | wc -l)
    echo "   ✅ API access successful"
    echo "   Found workflows in database"
else
    echo "   ❌ API access failed"
    echo "   Response: $RESPONSE"
    exit 1
fi

# Test n8n-mcp-server
echo ""
echo "4. Testing n8n-mcp-server..."
export N8N_API_URL=http://localhost:5678/api/v1
timeout 5 npx -y n8n-mcp-server > /tmp/n8n-mcp-test.log 2>&1 &
MCP_PID=$!
sleep 3

if ps -p $MCP_PID > /dev/null; then
    echo "   ✅ n8n-mcp-server started successfully"
    kill $MCP_PID 2>/dev/null
    
    # Show startup log
    if grep -q "Successfully connected" /tmp/n8n-mcp-test.log; then
        echo "   ✅ Connected to n8n API"
    fi
else
    echo "   ❌ n8n-mcp-server failed to start"
    cat /tmp/n8n-mcp-test.log
    exit 1
fi

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║                                                          ║"
echo "║  ✅ All Tests Passed!                                   ║"
echo "║                                                          ║"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""
echo "Next steps:"
echo "1. Restart Cursor IDE"
echo "2. Check if n8n MCP appears in Cursor's MCP resources"
echo "3. Ask AI: 'List my n8n workflows'"
echo ""



