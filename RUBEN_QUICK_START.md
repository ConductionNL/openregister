# 🎯 Quick Setup Guide for Ruben

## ✅ n8n is Running and Ready!

Your browser should have opened to: **http://localhost:5678**

---

## Step 1: Login to n8n (30 seconds)

### Login Credentials:
```
Email:    ruben@conduction.nl
Password: 4257
```

1. Enter your email: `ruben@conduction.nl`
2. Enter your password: `4257`
3. Click "Sign in" or press Enter

---

## Step 2: Import the Enhanced Workflow (2 minutes)

### Visual Guide:

```
┌─────────────────────────────────────────────┐
│  n8n Dashboard                              │
│  ┌──────────┐                               │
│  │Workflows │ ← Click here                  │
│  └──────────┘                               │
└─────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────┐
│  Workflows                                  │
│                        ┌──────────────┐     │
│                        │ + Add workflow│ ← Click │
│                        └──────────────┘     │
└─────────────────────────────────────────────┘
         ↓
┌─────────────────────────────────────────────┐
│  New Workflow                               │
│  ┌───┐                                      │
│  │ ⋮ │ ← Click the 3-dot menu               │
│  └───┘                                      │
│    ↓                                        │
│  ┌─────────────────┐                        │
│  │Import from file │ ← Click this           │
│  └─────────────────┘                        │
└─────────────────────────────────────────────┘
```

### File to Import:

**Path:**
```
/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/n8n-templates/enhanced-phpqa-auto-fixer-with-loop-and-testing.json
```

**Or shorter:**
```
~/nextcloud-docker-dev/workspace/server/apps-extra/openregister/n8n-templates/
```

Then select: `enhanced-phpqa-auto-fixer-with-loop-and-testing.json`

---

## Step 3: Execute the Workflow (1 minute)

After importing, you'll see the workflow canvas with many connected nodes.

```
┌─────────────────────────────────────────────┐
│  Enhanced PHPQA Auto-Fixer                  │
│                                             │
│  [Configuration] → [Run composer phpqa]...  │
│                                             │
│                    ┌────────────────────┐   │
│                    │Execute Workflow ▶  │ ← Click this!
│                    └────────────────────┘   │
└─────────────────────────────────────────────┘
```

Watch the nodes light up as they execute:
1. Green = Success
2. Red = Error
3. Running = Processing

---

## What Will Happen?

### Iteration 1:
```
1. Run composer phpqa → Analyze quality
2. Find PHPCS errors → Parse them
3. Send to AI (Ollama) → Generate fixes
4. Apply fixes → Update files
5. Run Newman tests → Verify nothing broke
6. Tests pass? → Commit changes
7. Check quality → Improved? Loop again!
```

### It Will Loop Until:
- ✅ All issues are fixed
- ✅ Quality stops improving
- ✅ Maximum iterations reached (5)
- ❌ Tests fail (auto-rollback)

---

## Expected Timeline

| Stage | Time |
|-------|------|
| PHPQA Analysis | ~30 sec |
| Get errors | ~10 sec |
| Generate fixes (10 errors) | ~1 min |
| Apply fixes | ~5 sec |
| Run tests | ~2 min |
| Commit | ~1 sec |
| **Per iteration** | **~4 min** |
| **5 iterations** | **~20 min** |

---

## Monitoring Progress

### In n8n:
- Click on any node to see its output
- Check the "Generate Report" node for summary
- Watch the iteration count increase

### In Terminal:
```bash
# Watch n8n logs
docker logs -f openregister-n8n

# Watch Ollama (AI) processing
docker logs -f openregister-ollama

# Check git commits
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister
git log --oneline -10
```

---

## Troubleshooting

### Can't Login?
```bash
# Check n8n is running
docker ps | grep n8n

# Restart if needed
docker-compose --profile n8n restart n8n
```

### Workflow Fails?
1. Click on the red node
2. Check the error message
3. Common fixes:
   - Ollama not ready: Wait 30 seconds
   - Newman not found: Check tests/integration/run-tests.sh
   - Git errors: Ensure repo is clean

### Browser Didn't Open?
Manually open: **http://localhost:5678**

---

## After Workflow Completes

### Check Results:
```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister

# See commits made
git log --oneline -10

# Check latest commit
git show HEAD

# See overall improvement
composer phpqa
```

### Review Changes:
```bash
# See what was changed
git diff HEAD~5 HEAD

# If you want to undo (only if something wrong)
git reset --hard HEAD~5
```

---

## Quick Reference

| What | Where |
|------|-------|
| **n8n URL** | http://localhost:5678 |
| **Login** | ruben@conduction.nl / 4257 |
| **Workflow File** | `n8n-templates/enhanced-phpqa-auto-fixer-with-loop-and-testing.json` |
| **Docs** | `ENHANCED_WORKFLOW_GUIDE.md` |
| **Logs** | `docker logs -f openregister-n8n` |

---

## 🎉 You're All Set!

The browser should be open at http://localhost:5678

**Next:**
1. Login with your credentials
2. Import the workflow
3. Click "Execute Workflow"
4. Watch the magic happen!

**The workflow will automatically:**
- ✅ Find and fix PHPCS errors
- ✅ Run tests to ensure nothing breaks
- ✅ Commit good changes
- ✅ Loop until quality improves
- ✅ Generate a detailed report

**Estimated time:** 15-30 minutes for full run

**Questions?** Check `ENHANCED_WORKFLOW_GUIDE.md`

---

**Happy automated fixing!** 🚀

