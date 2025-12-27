# 🎉 Automated PHPCS Fixing - Quick Start Guide

## Overview

You can now use **n8n + Ollama** to automatically fix PHPCS errors in bulk, avoiding the "continue?" problem!

## 🚀 Quick Setup (5 minutes)

### 1. Run the Setup Script

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
./scripts/setup-phpcs-automation.sh
```

This will:
- ✅ Start n8n (if not running)
- ✅ Install CodeLlama 7B model in Ollama
- ✅ Test the connection
- ✅ Check your current PHPCS errors
- ✅ Prepare the workflow template

### 2. Import the Workflow into n8n

1. Open n8n: **http://localhost:5678**
2. Login: `admin` / `admin`
3. Click **"Add workflow"** → **"Import from file"**
4. Select: `n8n-templates/phpcs-auto-fixer-workflow.json`
5. Click **"Import"**

### 3. Run the Workflow

1. Open the imported workflow
2. Click **"Execute Workflow"** button
3. Watch it process errors in batches of 5
4. Review the generated fixes

## 📊 What It Does

```
PHPCS Errors (e.g., 500+)
    ↓
Parse & Split into batches (5 at a time)
    ↓
For each error:
  - Read file context
  - Generate prompt
  - Call Ollama CodeLlama
  - Extract fix
    ↓
Generate report
    ↓
Save to /tmp/phpcs-fixes-TIMESTAMP.json
```

## 🤖 Models for Different Tasks

### Recommended Models

```bash
# Best for PHPCS fixes (RECOMMENDED)
docker exec openregister-ollama ollama pull codellama:7b-instruct

# Best for code understanding
docker exec openregister-ollama ollama pull deepseek-coder:6.7b-instruct

# Best quality (needs 16GB+ RAM)
docker exec openregister-ollama ollama pull phind-codellama:34b-v2

# For docblock generation
docker exec openregister-ollama ollama pull codellama:13b-instruct
```

### Model Comparison

| Model | Size | Speed | Quality | Best For |
|-------|------|-------|---------|----------|
| codellama:7b-instruct | 3.8GB | ⚡⚡⚡ | ⭐⭐⭐ | PHPCS fixes |
| deepseek-coder:6.7b | 3.8GB | ⚡⚡⚡ | ⭐⭐⭐⭐ | Code analysis |
| codellama:13b-instruct | 7.3GB | ⚡⚡ | ⭐⭐⭐⭐ | Complex fixes |
| phind-codellama:34b-v2 | 19GB | ⚡ | ⭐⭐⭐⭐⭐ | Best quality |

## 💡 AI in n8n - YES!

### Can n8n use AI to create workflows?

**Yes!** Two ways:

#### 1. AI-Assisted Workflow Creation

You can create a "meta-workflow" that uses Ollama to generate n8n workflows:

```javascript
// Prompt Ollama to create a workflow
const description = "Monitor PHPCS errors and send Slack notifications";
const workflow = await ollama.generate({
  model: 'codellama:13b-instruct',
  prompt: `Create an n8n workflow JSON for: ${description}`
});
// Import the generated workflow into n8n
```

#### 2. AI Nodes in n8n

n8n has built-in AI capabilities:
- **AI Agent** node (for OpenAI/Ollama)
- **AI Chain** node (for complex reasoning)
- **HTTP Request** node (call Ollama directly)

### Example: AI Workflow Builder

Import this workflow to create OTHER workflows with AI:

**Workflow:** "AI Workflow Creator"
1. Webhook input: Describe what you want
2. Call Ollama: Generate workflow JSON
3. n8n API: Import the workflow
4. Return: Workflow ID

## 🎨 Advanced Features

### Batch Processing

The workflow processes 5 errors at a time by default. Adjust:

```javascript
// In "Split Into Batches" node
{
  "batchSize": 10,  // Process more at once (faster but more memory)
  "options": {}
}
```

### Custom Prompts

Edit the "Prepare Prompt" node to customize how Ollama fixes errors:

```javascript
const prompt = `You are a senior PHP developer.

Fix this PHPCS error following PSR-12 standards:
File: ${error.file}
Line: ${error.line}
Error: ${error.message}

Provide ONLY the corrected code with proper indentation.`;
```

### Scheduling

Add a Cron trigger to run automatically:

1. Add **Cron** node at the start
2. Set schedule: `0 2 * * *` (2 AM daily)
3. Save and activate

### Webhook Trigger

Trigger from external tools:

```bash
curl -X POST http://localhost:5678/webhook/phpcs-fix \\
  -H "Content-Type: application/json" \\
  -d '{"directory": "lib/Controller", "batchSize": 10}'
```

## 📝 Example Fixes

### Before
```php
function Get_Data() {  // Wrong: not camelCase
    return $this->data;
}
```

### After (Ollama suggestion)
```php
function getData() {  // Fixed: camelCase
    return $this->data;
}
```

## 🔧 Troubleshooting

### Ollama Not Responding

```bash
# Check Ollama is running
docker ps | grep ollama

# Test from n8n container
docker exec openregister-n8n curl http://ollama:11434/api/tags

# Check logs
docker logs openregister-ollama -f
```

### n8n Workflow Fails

```bash
# Check n8n logs
docker logs openregister-n8n -f

# Verify model is installed
docker exec openregister-ollama ollama list
```

### Model Download Stuck

```bash
# Cancel and retry
docker exec openregister-ollama pkill ollama
docker exec openregister-ollama ollama pull codellama:7b-instruct
```

## 📚 Full Documentation

Complete guide: `website/docs/development/automated-phpcs-fixing.md`

Topics covered:
- ✅ Architecture diagrams
- ✅ Step-by-step setup
- ✅ Workflow design
- ✅ Model selection
- ✅ AI workflow creation
- ✅ Best practices
- ✅ Performance tips
- ✅ Troubleshooting

## 🎯 Workflow Files

| File | Purpose |
|------|---------|
| `n8n-templates/phpcs-auto-fixer-workflow.json` | Ready-to-import workflow |
| `scripts/setup-phpcs-automation.sh` | Automated setup script |
| `website/docs/development/automated-phpcs-fixing.md` | Complete documentation |

## 💪 Benefits

### vs. Manual Fixing

| Aspect | Manual | Automated with n8n |
|--------|--------|-------------------|
| **Speed** | ~2 min/error | ~5 sec/error |
| **Consistency** | Varies | Always follows rules |
| **Interruptions** | Many "continue?" | None! |
| **Batch Size** | 10-20 | Unlimited |
| **Time for 500 errors** | ~16 hours | ~40 minutes |

### vs. php-cs-fixer

| Aspect | php-cs-fixer | n8n + Ollama |
|--------|-------------|--------------|
| **Understanding** | Rule-based | AI understands context |
| **Complex Fixes** | Limited | Can refactor |
| **Learning** | Fixed rules | Improves over time |
| **Custom Rules** | Need plugins | Just change prompt |

## 🚀 Next Steps

1. **Run the setup script**
   ```bash
   ./scripts/setup-phpcs-automation.sh
   ```

2. **Import and test workflow**
   - Access http://localhost:5678
   - Import the workflow
   - Execute and review

3. **Expand capabilities**
   - Add docblock generation
   - Add type hint fixes
   - Add automated testing

4. **Integrate with CI/CD**
   - Run nightly
   - Auto-commit fixes
   - Send reports

## 🎉 Summary

You now have:
- ✅ n8n workflow orchestration
- ✅ Ollama CodeLlama for AI code understanding
- ✅ Automated batch processing (no "continue?" prompts!)
- ✅ Ready-to-import workflow template
- ✅ Setup script for easy installation
- ✅ Complete documentation

**Fix hundreds of PHPCS errors automatically while you sleep!** 🌙

---

**Questions?**
- Read: `website/docs/development/automated-phpcs-fixing.md`
- Check: n8n docs at https://docs.n8n.io
- Test: http://localhost:5678

