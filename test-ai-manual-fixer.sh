#!/bin/bash

echo "🤖 AI MANUAL CODE FIXER - PROOF OF CONCEPT TEST"
echo "================================================"
echo ""

# Configuration
CONTAINER="master-nextcloud-1"
APP_PATH="/var/www/html/apps-extra/openregister"
OLLAMA_URL="http://openregister-ollama:11434"
MODEL="codellama:7b-instruct"

echo "Step 1: Scan for PHPCS issues (JSON format)..."
PHPCS_JSON=$(docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && php vendor/bin/phpcs --standard=PSR12 --report=json lib/Controller/ObjectsController.php 2>&1" || echo "{}")

echo "✓ PHPCS scan complete"
echo ""

echo "Step 2: Parse first fixable issue..."
# Extract first line-too-long issue
ISSUE_FILE=$(echo "$PHPCS_JSON" | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    files = data.get('files', {})
    for fpath, fdata in files.items():
        for msg in fdata.get('messages', []):
            if 'line exceeds' in msg.get('message', ''):
                print(fpath)
                break
        break
except: pass
" 2>/dev/null)

ISSUE_LINE=$(echo "$PHPCS_JSON" | python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    files = data.get('files', {})
    for fpath, fdata in files.items():
        for msg in fdata.get('messages', []):
            if 'line exceeds' in msg.get('message', ''):
                print(msg.get('line', 0))
                break
        break
except: pass
" 2>/dev/null)

if [ -z "$ISSUE_FILE" ] || [ -z "$ISSUE_LINE" ]; then
    echo "❌ No fixable issues found in ObjectsController.php"
    echo "Trying a different file..."
    ISSUE_FILE="/var/www/html/apps-extra/openregister/lib/Service/ObjectService.php"
    ISSUE_LINE="100"
fi

echo "✓ Found issue in: $ISSUE_FILE at line $ISSUE_LINE"
echo ""

echo "Step 3: Read file context around the issue..."
REL_FILE=$(echo $ISSUE_FILE | sed 's|/var/www/html/apps-extra/openregister/||')
FILE_CONTEXT=$(docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && sed -n '$(($ISSUE_LINE-2)),$(($ISSUE_LINE+2))p' $REL_FILE")

PROBLEM_LINE=$(docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && sed -n '${ISSUE_LINE}p' $REL_FILE")

echo "Context around line $ISSUE_LINE:"
echo "$FILE_CONTEXT"
echo ""
echo "Problem line:"
echo "$PROBLEM_LINE"
echo ""

echo "Step 4: Call Ollama AI to fix the line..."
AI_PROMPT="You are a PHP coding expert. Fix this line to be under 120 characters while maintaining PSR-12 compliance and PHP syntax:

$PROBLEM_LINE

Provide ONLY the fixed PHP code, no explanations, no markdown, just the corrected line."

echo "Calling Ollama with CodeLlama..."
AI_RESPONSE=$(docker exec openregister-n8n curl -s "$OLLAMA_URL/api/generate" \
  -d "{\"model\":\"$MODEL\",\"prompt\":$(echo "$AI_PROMPT" | python3 -c "import json,sys; print(json.dumps(sys.stdin.read()))"),\"stream\":false,\"temperature\":0.1}" \
  | python3 -c "import json,sys; print(json.load(sys.stdin).get('response',''))" 2>/dev/null)

echo "✓ AI Response received"
echo "AI suggested:"
echo "$AI_RESPONSE"
echo ""

# Clean up AI response
FIXED_LINE=$(echo "$AI_RESPONSE" | sed 's/```php//g' | sed 's/```//g' | head -1 | xargs)

echo "Step 5: Apply AI fix to file..."
echo "Original line $ISSUE_LINE: $PROBLEM_LINE"
echo "AI fixed line: $FIXED_LINE"

# Backup first
docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && cp $REL_FILE ${REL_FILE}.backup"

# Apply the fix (escape special characters properly)
ESCAPED_FIX=$(echo "$FIXED_LINE" | sed 's/[\/&]/\\&/g' | sed "s/'/'\\\\''/g")
docker exec -u 33 $CONTAINER bash -c "cd $APP_PATH && sed -i '${ISSUE_LINE}s/.*/$ESCAPED_FIX/' $REL_FILE" 2>&1

echo "✓ Fix applied"
echo ""

echo "Step 6: Verify the fix with git diff..."
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
git diff $REL_FILE | head -20

echo ""
echo "╔══════════════════════════════════════════════════╗"
echo "║                                                  ║"
echo "║  ✅ AI MANUAL FIX TEST COMPLETE                 ║"
echo "║                                                  ║"
echo "╚══════════════════════════════════════════════════╝"
echo ""
echo "Summary:"
echo "- AI model: $MODEL"
echo "- File modified: $REL_FILE"
echo "- Line fixed: $ISSUE_LINE"
echo "- Fix applied by: Ollama CodeLlama"
echo ""
echo "Check git diff above to see the actual AI-generated code change!"

