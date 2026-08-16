#!/bin/bash
# Test script for RAG chat functionality via API

echo "=== RAG CHAT API TEST ==="
echo ""

echo "Step 1: Sending message to Agent 4..."
echo "Question: 'Wat is de kleur van Mokum?'"
echo ""

# Create JSON payload
JSON_PAYLOAD=$(cat <<EOF
{
  "agentUuid": "9966ab0a-f168-41ce-be82-fd1111f107e0",
  "message": "Wat is de kleur van Mokum?"
}
EOF
)

# Send request
RESPONSE=$(curl -s -X POST \
  -u "admin:admin" \
  -H "Content-Type: application/json" \
  -d "$JSON_PAYLOAD" \
  http://localhost/index.php/apps/openregister/api/chat/message)

echo "Step 2: Response received:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "$RESPONSE" | jq -r '.message // "NO MESSAGE"'
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

echo "Step 3: Sources:"
echo "$RESPONSE" | jq '.sources[] | "  - \(.name // "Unknown") (\(.type // "unknown")): \(.text[0:80])..."'
echo ""

echo "Step 4: Validation:"
if echo "$RESPONSE" | jq -r '.message' | grep -qi "blauw\|blue"; then
    echo "✅ SUCCESS! Answer mentions blauw/blue - RAG is working!"
else
    echo "❌ FAIL: Answer does not mention blauw/blue"
    echo "Full response:"
    echo "$RESPONSE" | jq '.'
fi

echo ""
echo "=== TEST COMPLETE ==="

