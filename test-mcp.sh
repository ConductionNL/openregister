#!/bin/bash
# Test script for OpenRegister MCP Discovery + n8n MCP connectivity
# Usage: bash test-mcp.sh

set -uo pipefail

NC_URL="http://localhost:8080"
N8N_URL="http://localhost:5679"
N8N_API_KEY="eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJjZjAxY2NiZC1iNWYzLTQ0ZmItODM3ZS1kMDVmZjRmYTE2MzMiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwianRpIjoiNWU0YmM3MWEtOWU0Zi00ZjA3LTk2NTctNGQ1MTI1ZTJhMmNhIiwiaWF0IjoxNzcyOTcwMDE5LCJleHAiOjE3NzU1MTI4MDB9.lJXEzd4fCQHH726AvvWpk-wVjBtb9lla8AIL8vCwbzQ"

PASS=0
FAIL=0

check() {
    local desc="$1" result="$2"
    if [ "$result" = "ok" ]; then
        echo "  ✓ $desc"
        ((PASS++))
    else
        echo "  ✗ $desc — $result"
        ((FAIL++))
    fi
}

echo "=== OpenRegister MCP Discovery ==="
echo ""

# Test 1: Tier 1 - Public catalog
echo "--- Tier 1: Public Catalog (no auth) ---"
RESP=$(curl -s -w "\n%{http_code}" "$NC_URL/index.php/apps/openregister/api/mcp/v1/discover")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

if [ "$CODE" = "200" ]; then
    check "HTTP 200" "ok"
else
    check "HTTP 200" "got $CODE"
fi

CAP_COUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('capabilities',[])))" 2>/dev/null || echo "0")
if [ "$CAP_COUNT" -ge 10 ]; then
    check "≥10 capabilities" "ok"
else
    check "≥10 capabilities" "got $CAP_COUNT"
fi

VERSION=$(echo "$BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('version',''))" 2>/dev/null || echo "")
if [ "$VERSION" = "1.0" ]; then
    check "Version 1.0" "ok"
else
    check "Version 1.0" "got '$VERSION'"
fi

CHAR_COUNT=$(echo "$BODY" | wc -c)
if [ "$CHAR_COUNT" -lt 3000 ]; then
    check "Under 3000 chars ($CHAR_COUNT)" "ok"
else
    check "Under 3000 chars" "got $CHAR_COUNT"
fi

echo ""

# Test 2: Tier 2 - Authenticated capability detail
echo "--- Tier 2: Capability Detail (auth required) ---"

# Without auth should fail
RESP=$(curl -s -w "\n%{http_code}" "$NC_URL/index.php/apps/openregister/api/mcp/v1/discover/registers")
CODE=$(echo "$RESP" | tail -1)
if [ "$CODE" = "401" ]; then
    check "Unauthenticated → 401" "ok"
else
    check "Unauthenticated → 401" "got $CODE"
fi

# With auth should succeed
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" "$NC_URL/index.php/apps/openregister/api/mcp/v1/discover/registers")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

if [ "$CODE" = "200" ]; then
    check "Authenticated → 200" "ok"
else
    check "Authenticated → 200" "got $CODE"
fi

HAS_ENDPOINTS=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if d.get('endpoints') else 'no')" 2>/dev/null || echo "no")
if [ "$HAS_ENDPOINTS" = "yes" ]; then
    check "Has endpoints array" "ok"
else
    check "Has endpoints array" "missing"
fi

HAS_CONTEXT=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if d.get('context',{}).get('registers') else 'no')" 2>/dev/null || echo "no")
if [ "$HAS_CONTEXT" = "yes" ]; then
    check "Has live register data" "ok"
else
    check "Has live register data" "missing"
fi

# Test unknown capability
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" "$NC_URL/index.php/apps/openregister/api/mcp/v1/discover/nonexistent")
CODE=$(echo "$RESP" | tail -1)
if [ "$CODE" = "404" ]; then
    check "Unknown capability → 404" "ok"
else
    check "Unknown capability → 404" "got $CODE"
fi

# Test all 10 capabilities
echo ""
echo "--- All Capabilities ---"
for cap in registers schemas objects search files audit bulk webhooks chat views; do
    RESP=$(curl -s -u admin:admin -w "\n%{http_code}" "$NC_URL/index.php/apps/openregister/api/mcp/v1/discover/$cap")
    CODE=$(echo "$RESP" | tail -1)
    if [ "$CODE" = "200" ]; then
        check "$cap" "ok"
    else
        check "$cap" "got $CODE"
    fi
done

echo ""
echo "=== n8n API (via port forward) ==="
echo ""

# Test n8n healthcheck
RESP=$(curl -s -w "\n%{http_code}" "$N8N_URL/healthz" 2>/dev/null)
CODE=$(echo "$RESP" | tail -1)
if [ "$CODE" = "200" ]; then
    check "n8n healthz" "ok"
else
    check "n8n healthz" "got $CODE (is n8n-port-forward container running?)"
fi

# Test n8n API with key
RESP=$(curl -s -H "X-N8N-API-KEY: $N8N_API_KEY" -w "\n%{http_code}" "$N8N_URL/api/v1/workflows")
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

if [ "$CODE" = "200" ]; then
    check "n8n API auth" "ok"
else
    check "n8n API auth" "got $CODE"
fi

WF_COUNT=$(echo "$BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('data',[])))" 2>/dev/null || echo "0")
check "n8n workflows found: $WF_COUNT" "ok"

# Test n8n-mcp stdio server
echo ""
echo "--- n8n-mcp Server ---"
MCP_RESP=$(echo '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}' | \
    MCP_MODE=stdio LOG_LEVEL=error DISABLE_CONSOLE_OUTPUT=true N8N_API_URL="$N8N_URL" N8N_API_KEY="$N8N_API_KEY" \
    timeout 15 npx -y n8n-mcp@latest 2>/dev/null | grep -m1 '^{' || echo '{}')

if echo "$MCP_RESP" | python3 -c "import sys,json; d=json.loads(sys.stdin.read()); assert d.get('result',{}).get('serverInfo')" 2>/dev/null; then
    SERVER_NAME=$(echo "$MCP_RESP" | python3 -c "import sys,json; print(json.loads(sys.stdin.read())['result']['serverInfo']['name'])")
    check "n8n-mcp server responds ($SERVER_NAME)" "ok"
else
    check "n8n-mcp server responds" "no valid response"
fi

echo ""
echo "=== OpenRegister MCP Standard Protocol ==="
echo ""

# Initialize — get session ID
echo "--- Initialize ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -D /tmp/mcp-headers.txt -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"test","version":"1.0"}}}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

if [ "$CODE" = "200" ]; then
    check "Initialize → 200" "ok"
else
    check "Initialize → 200" "got $CODE"
fi

HAS_PROTO=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if d.get('result',{}).get('protocolVersion') else 'no')" 2>/dev/null || echo "no")
if [ "$HAS_PROTO" = "yes" ]; then
    check "Has protocolVersion" "ok"
else
    check "Has protocolVersion" "missing"
fi

HAS_TOOLS_CAP=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if 'tools' in d.get('result',{}).get('capabilities',{}) else 'no')" 2>/dev/null || echo "no")
if [ "$HAS_TOOLS_CAP" = "yes" ]; then
    check "Capabilities include tools" "ok"
else
    check "Capabilities include tools" "missing"
fi

# Extract session ID from response headers
SESSION_ID=$(grep -i 'mcp-session-id' /tmp/mcp-headers.txt 2>/dev/null | sed 's/.*: //' | tr -d '\r\n')
if [ -n "$SESSION_ID" ]; then
    check "Mcp-Session-Id header present" "ok"
else
    check "Mcp-Session-Id header present" "missing"
fi

echo ""
echo "--- Notification ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","method":"notifications/initialized"}')
CODE=$(echo "$RESP" | tail -1)
if [ "$CODE" = "202" ]; then
    check "Notification → 202" "ok"
else
    check "Notification → 202" "got $CODE"
fi

echo ""
echo "--- Ping ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":2,"method":"ping"}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)
HAS_RESULT=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if 'result' in d else 'no')" 2>/dev/null || echo "no")
if [ "$CODE" = "200" ] && [ "$HAS_RESULT" = "yes" ]; then
    check "Ping → result" "ok"
else
    check "Ping → result" "got $CODE / $HAS_RESULT"
fi

echo ""
echo "--- Tools ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/list"}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

TOOL_COUNT=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('result',{}).get('tools',[])))" 2>/dev/null || echo "0")
if [ "$TOOL_COUNT" = "3" ]; then
    check "3 tools returned" "ok"
else
    check "3 tools returned" "got $TOOL_COUNT"
fi

TOOL_NAMES=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(','.join(t['name'] for t in d.get('result',{}).get('tools',[])))" 2>/dev/null || echo "")
if [ "$TOOL_NAMES" = "registers,schemas,objects" ]; then
    check "Tool names correct" "ok"
else
    check "Tool names correct" "got '$TOOL_NAMES'"
fi

echo ""
echo "--- Tool Call: registers list ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"registers","arguments":{"action":"list"}}}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

HAS_CONTENT=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if d.get('result',{}).get('content') else 'no')" 2>/dev/null || echo "no")
if [ "$CODE" = "200" ] && [ "$HAS_CONTENT" = "yes" ]; then
    check "tools/call registers list" "ok"
else
    check "tools/call registers list" "got $CODE / content=$HAS_CONTENT"
fi

echo ""
echo "--- Resources ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":5,"method":"resources/list"}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

RES_COUNT=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('result',{}).get('resources',[])))" 2>/dev/null || echo "0")
if [ "$RES_COUNT" -ge 2 ]; then
    check "≥2 resources ($RES_COUNT)" "ok"
else
    check "≥2 resources" "got $RES_COUNT"
fi

echo ""
echo "--- Resource Read ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":6,"method":"resources/read","params":{"uri":"openregister://registers"}}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

HAS_CONTENTS=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if d.get('result',{}).get('contents') else 'no')" 2>/dev/null || echo "no")
if [ "$CODE" = "200" ] && [ "$HAS_CONTENTS" = "yes" ]; then
    check "resources/read registers" "ok"
else
    check "resources/read registers" "got $CODE / contents=$HAS_CONTENTS"
fi

echo ""
echo "--- Resource Templates ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":7,"method":"resources/templates/list"}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)

TPL_COUNT=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d.get('result',{}).get('resourceTemplates',[])))" 2>/dev/null || echo "0")
if [ "$TPL_COUNT" = "3" ]; then
    check "3 resource templates" "ok"
else
    check "3 resource templates" "got $TPL_COUNT"
fi

echo ""
echo "--- Session Validation ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":8,"method":"ping"}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)
HAS_SESSION_ERR=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if d.get('error',{}).get('code') == -32000 else 'no')" 2>/dev/null || echo "no")
if [ "$HAS_SESSION_ERR" = "yes" ]; then
    check "Missing session → error -32000" "ok"
else
    check "Missing session → error -32000" "got $BODY"
fi

echo ""
echo "--- Error Handling ---"
RESP=$(curl -s -u admin:admin -w "\n%{http_code}" -X POST \
  "$NC_URL/index.php/apps/openregister/api/mcp" \
  -H "Content-Type: application/json" \
  -H "Mcp-Session-Id: $SESSION_ID" \
  -d '{"jsonrpc":"2.0","id":9,"method":"nonexistent/method"}')
CODE=$(echo "$RESP" | tail -1)
BODY=$(echo "$RESP" | head -n -1)
HAS_METHOD_ERR=$(echo "$BODY" | python3 -c "import sys,json; d=json.load(sys.stdin); print('yes' if d.get('error',{}).get('code') == -32601 else 'no')" 2>/dev/null || echo "no")
if [ "$HAS_METHOD_ERR" = "yes" ]; then
    check "Unknown method → error -32601" "ok"
else
    check "Unknown method → error -32601" "got $BODY"
fi

rm -f /tmp/mcp-headers.txt

echo ""
echo "=== Results ==="
echo "  Passed: $PASS"
echo "  Failed: $FAIL"
echo ""

if [ "$FAIL" -gt 0 ]; then
    echo "Some tests failed. Troubleshooting:"
    echo "  - Ensure Nextcloud is running: curl http://localhost:8080/status.php"
    echo "  - Ensure n8n port forward: docker ps | grep n8n-port-forward"
    echo "  - If missing, run: docker run -d --name n8n-port-forward --network openregister-network -p 5679:5678 alpine/socat tcp-listen:5678,fork,reuseaddr tcp-connect:openregister-exapp-n8n:5678"
    exit 1
fi
