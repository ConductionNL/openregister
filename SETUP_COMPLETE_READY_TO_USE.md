# ✅ Setup Complete! Ready to Fix PHPCS Errors

## 🎉 Status: All Systems Ready!

✅ **n8n** - Running at http://localhost:5678  
✅ **Ollama** - Running with CodeLlama 7B Instruct  
✅ **PostgreSQL** - Running with pgvector + pg_trgm  
✅ **Workflow Template** - Ready to import  

## 🚀 Next Steps - Import and Run

### Step 1: Access n8n

1. Open your browser: **http://localhost:5678**
2. Login credentials:
   - Username: `admin`
   - Password: `admin`

### Step 2: Import the Workflow

1. Click **"Workflows"** in the left menu
2. Click **"Add workflow"** (top right)
3. Click **"Import from file"**
4. Navigate to: `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/n8n-templates/`
5. Select: `phpcs-auto-fixer-workflow.json`
6. Click **"Import"**

### Step 3: Understand the Workflow

The imported workflow has these nodes:

```
1. Start (Manual Trigger)
   ↓
2. Run PHPCS (Execute Command)
   ↓
3. Parse PHPCS Errors (Code Node)
   ↓
4. Split Into Batches (5 errors at a time)
   ↓
5. Prepare Prompt (Code Node)
   ↓
6. Call Ollama (HTTP Request)
   ↓
7. Extract Fix (Code Node)
   ↓
8. Merge Results
   ↓
9. Generate Report (Code Node)
   ↓
10. Save Report (to /tmp/)
```

### Step 4: Test with a Small Batch

Before running on all errors, let's test:

1. Open the workflow in n8n
2. Find the **"Split Into Batches"** node
3. Click on it
4. Change `batchSize` from 5 to **2** (test with just 2 errors)
5. Click **"Execute Workflow"** button (top right)
6. Watch it process in real-time!

### Step 5: Review Results

After execution:
- Click on each node to see its output
- The **"Generate Report"** node shows summary
- Report is saved to `/tmp/phpcs-fixes-TIMESTAMP.json`

## 🧪 Quick Test

Let's do a quick test now:

```bash
# Test Ollama directly
curl http://localhost:11434/api/generate -d '{
  "model": "codellama:7b-instruct",
  "prompt": "Fix this PHP PHPCS error:\nFile: test.php\nLine: 10\nError: Method name Get_Data is not in camel caps format\nProvide only the fixed method name.",
  "stream": false
}'
```

Expected response: Should suggest `getData()`

## 📊 Your Current PHPCS Errors

To see how many errors you have:

```bash
# From your workspace
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister

# Count errors
docker exec -u 33 nextcloud bash -c "cd /var/www/html/custom_apps/openregister && vendor/bin/phpcs --report=summary lib/"
```

## 🎨 Customizing the Workflow

### Adjust Batch Size

In n8n workflow, edit **"Split Into Batches"** node:

```javascript
{
  "batchSize": 10,  // Process 10 errors at a time
  "options": {}
}
```

**Recommendations:**
- **2-5**: Testing / slow systems
- **10-20**: Normal usage
- **50+**: Fast systems with good GPU

### Customize Prompts

In **"Prepare Prompt"** node, edit the prompt:

```javascript
const prompt = `You are a PHP expert following PSR-12 standards.

Fix this PHPCS error:
File: ${error.file}
Line: ${error.line}
Error: ${error.message}
Rule: ${error.source}

Provide ONLY the corrected code line, nothing else.`;
```

### Change Model

In **"Call Ollama"** node, change the model:

```json
{
  "model": "codellama:13b-instruct",  // Bigger = better quality, slower
  "prompt": "...",
  "stream": false
}
```

Available models:
- `codellama:7b-instruct` - Fast, good (recommended)
- `codellama:13b-instruct` - Better quality
- `mistral:7b` - Alternative
- `llama3.2:3b` - Fastest

## 🔧 Workflow Modes

### Manual Mode (Current Setup)

- Click "Execute Workflow" to run
- Good for testing
- Full control

### Scheduled Mode

Add a **Cron Trigger**:

1. Add new node: **Cron**
2. Set schedule: `0 2 * * *` (2 AM daily)
3. Connect to "Run PHPCS" node
4. Activate workflow

### Webhook Mode

Add a **Webhook Trigger**:

1. Add new node: **Webhook**
2. Set path: `/phpcs-fix`
3. Connect to "Run PHPCS"
4. Activate workflow

Then trigger via:
```bash
curl -X POST http://localhost:5678/webhook/phpcs-fix
```

## 📈 Expected Performance

| Errors | Batch Size | Time | Model |
|--------|-----------|------|-------|
| 10 | 5 | ~1 min | codellama:7b |
| 50 | 10 | ~4 min | codellama:7b |
| 100 | 20 | ~8 min | codellama:7b |
| 500 | 20 | ~40 min | codellama:7b |

**Factors:**
- CPU/GPU speed
- Model size
- Error complexity
- Network latency

## 🐛 Troubleshooting

### Workflow Fails at "Call Ollama"

```bash
# Check Ollama is accessible from n8n
docker exec openregister-n8n curl http://ollama:11434/api/tags
```

### "Model not found"

```bash
# Verify model is installed
docker exec openregister-ollama ollama list | grep codellama
```

### Slow Execution

- Reduce batch size to 2-5
- Use smaller model (llama3.2:3b)
- Check CPU usage: `docker stats`

### Connection Errors

```bash
# Check all containers are running
docker ps | grep -E "(n8n|ollama|postgres)"

# Check logs
docker logs openregister-n8n
docker logs openregister-ollama
```

## 🎯 Recommended Next Steps

1. **Import the workflow** (5 minutes)
2. **Test with 2 errors** (2 minutes)
3. **Review the fixes** (5 minutes)
4. **Adjust batch size** to 10
5. **Run on full codebase** (overnight)
6. **Review all fixes** in the morning
7. **Apply fixes** manually or with script

## 💡 Pro Tips

### 1. Save Fixes to Files

Modify the last node to save fixes as PHP files:

```javascript
// Generate PHP file with fixes
const content = fixes.map(f => 
  `// File: ${f.file}\n// Line: ${f.line}\n${f.fix}\n\n`
).join('\\n');

fs.writeFileSync('/tmp/fixes.php', content);
```

### 2. Create a Review Workflow

Create a second workflow that:
1. Reads the generated fixes
2. Shows them in a nice format
3. Lets you approve/reject each one

### 3. Integrate with Git

Add nodes to:
1. Create a new branch
2. Apply fixes
3. Commit changes
4. Create pull request

## 📚 Full Documentation

- **Complete Guide**: `website/docs/development/automated-phpcs-fixing.md`
- **n8n Docs**: https://docs.n8n.io
- **Ollama Docs**: https://github.com/ollama/ollama
- **CodeLlama Info**: https://github.com/meta-llama/codellama

## ✨ What You Have Now

✅ **Automated PHPCS fixing** - No more "continue?" prompts!  
✅ **AI-powered** - Understands context, not just rules  
✅ **Batch processing** - Handle hundreds of errors  
✅ **Customizable** - Easy to adjust prompts and logic  
✅ **Production-ready** - Can schedule and automate  
✅ **Well-documented** - Complete guides and examples  

## 🎊 Success!

You're all set! The system is ready to:
- Fix your PHPCS errors automatically
- Process them in manageable batches
- Generate detailed reports
- Run on-demand or on schedule

**Go to http://localhost:5678 and import the workflow!**

---

**Questions or issues?**
- Check: `website/docs/development/automated-phpcs-fixing.md`
- Test: http://localhost:5678
- Logs: `docker logs openregister-n8n -f`

Happy fixing! 🚀

