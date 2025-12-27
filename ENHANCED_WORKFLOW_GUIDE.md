# 🎯 Enhanced Workflow: PHPQA Auto-Fixer with Loop & Testing

## ✅ Answers to Your Questions

### Question 1: Does it use composer phpqa and composer phpcs?

**Answer: The ENHANCED workflow does!**

- ✅ Uses `composer phpqa` for comprehensive quality analysis
- ✅ Uses `composer phpcs` for detailed error listing
- ✅ Runs both at different stages

### Question 2: Does it have a loop for continuous improvement?

**Answer: YES! The enhanced workflow has a smart feedback loop:**

```
Run PHPQA → Fix Issues → Test → Commit → Check Quality
                ↑                                      ↓
                └──────────── Loop if improved ────────┘
```

**Loop Logic:**
- Runs up to 5 iterations (configurable)
- Continues if quality improves
- Stops if no improvement or all fixed
- Tracks metrics each iteration

### Question 3: Does it test with Newman?

**Answer: YES! Tests before committing:**

```
Apply Fixes → Verify PHPCS → Run Newman Tests
                                     ↓
                           Tests Pass? → Commit
                                     ↓
                           Tests Fail? → Rollback
```

**Safety Features:**
- Runs Newman tests after each fix batch
- Only commits if tests pass
- Auto-rollbacks if tests fail
- Git safety built-in

## 🚀 Quick Start Guide

### Step 1: Access n8n

**URL:** http://localhost:5678  
**Login:** admin / admin

### Step 2: Import Enhanced Workflow

1. Click **"Workflows"** → **"Add workflow"**
2. Click **⋮** menu → **"Import from file"**
3. Select: `n8n-templates/enhanced-phpqa-auto-fixer-with-loop-and-testing.json`
4. Click **"Import"**

### Step 3: Configure (Optional)

Click the **"Configuration"** node to adjust:

```javascript
{
  "maxIterations": 5,          // Max improvement loops
  "currentIteration": 0,        // Starting iteration
  "container": "nextcloud",     // Docker container name
  "appPath": "/var/www/html/custom_apps/openregister"
}
```

### Step 4: Execute

Click **"Execute Workflow"** and watch it:
1. Run PHPQA analysis
2. Fix issues with AI
3. Run tests
4. Commit or rollback
5. Loop until quality improves

## 🎨 Workflow Visualization

### Complete Flow

```
┌─────────────┐
│Configuration│
└──────┬──────┘
       │
       ▼
┌─────────────────┐
│ Run composer    │
│ phpqa           │
└────────┬────────┘
         │
         ▼
┌──────────────────┐
│ Parse PHPQA      │◄─────────────────┐
│ Results          │                  │
└────────┬─────────┘                  │
         │                            │
         ▼                            │
    ┌────────┐                        │
    │Issues? │                        │
    └───┬────┘                        │
        │                             │
    Yes │  No                         │
        │   │                         │
        ▼   └──────────────┐          │
┌──────────────┐           │          │
│Get Detailed  │           │          │
│PHPCS Errors  │           │          │
└──────┬───────┘           │          │
       │                   │          │
       ▼                   │          │
┌──────────────┐           │          │
│Batch Errors  │           │          │
│(10 at a time)│           │          │
└──────┬───────┘           │          │
       │                   │          │
       ▼                   │          │
┌──────────────┐           │          │
│Generate Fixes│           │          │
│with AI       │           │          │
│(Ollama)      │           │          │
└──────┬───────┘           │          │
       │                   │          │
       ▼                   │          │
┌──────────────┐           │          │
│Apply Fixes   │           │          │
│to Files      │           │          │
└──────┬───────┘           │          │
       │                   │          │
       ▼                   │          │
┌──────────────┐           │          │
│Verify PHPCS  │           │          │
└──────┬───────┘           │          │
       │                   │          │
       ▼                   │          │
┌──────────────┐           │          │
│Run Newman    │           │          │
│Tests         │           │          │
└──────┬───────┘           │          │
       │                   │          │
       ▼                   │          │
    ┌──────┐               │          │
    │Tests?│               │          │
    └──┬───┘               │          │
       │                   │          │
  Pass │  Fail             │          │
       │   │               │          │
       ▼   ▼               │          │
   ┌────┐ ┌────┐           │          │
   │Commit Rollback│       │          │
   └──┬─┘ └──┬──┘          │          │
      │      │              │          │
      ▼      └──────────────┤          │
   ┌─────────┐              │          │
   │Continue?│              │          │
   └────┬────┘              │          │
        │                   │          │
   Yes  │  No               │          │
        └───────────────────┘          │
                            │          │
                            ▼          │
                    ┌──────────────┐   │
                    │Final Report  │   │
                    └──────────────┘   │
```

## 📊 What Gets Checked

### PHPQA Checks

1. **PHPCS** - Coding standards (PSR-12)
2. **PHPMD** - Mess detection (complexity, unused code)
3. **PHPStan** - Static analysis (type errors)
4. **PHP Copy/Paste Detector** - Code duplication
5. **PHPMetrics** - Code quality metrics

### Newman Tests

Runs your full integration test suite:
- API endpoint tests
- CRUD operations
- Authentication
- Data validation
- All your Postman collections

## 🔧 Advanced Configuration

### Adjust Batch Size

In **"Batch Errors"** node:

```javascript
{
  "batchSize": 10  // Process 10 errors at a time
}
```

**Recommendations:**
- Small codebase: 20-50
- Medium: 10-20
- Large/complex: 5-10

### Adjust Max Iterations

In **"Configuration"** node:

```javascript
{
  "maxIterations": 5  // Stop after 5 improvement loops
}
```

### Change AI Model

In **"Generate Fix with AI"** node:

```json
{
  "model": "codellama:13b-instruct"  // Better quality, slower
}
```

### Skip Newman Tests (Not Recommended)

If you want to skip testing (NOT RECOMMENDED):
1. Find **"Run Newman Tests"** node
2. Delete connection to next node
3. Connect **"Verify PHPCS"** directly to **"Git Commit"**

## 📈 Expected Behavior

### Iteration 1
```
Initial: 500 PHPCS errors, 150 PHPMD violations
↓
Fix 50 errors with AI
↓
Run tests: PASS
↓
Commit changes
↓
Re-run PHPQA: 450 errors, 145 violations
↓
Improved! Continue to Iteration 2
```

### Iteration 2
```
Current: 450 errors
↓
Fix 50 more
↓
Tests: PASS
↓
Commit
↓
Re-run: 400 errors
↓
Improved! Continue to Iteration 3
```

### Final Iteration
```
Current: 50 errors
↓
Fix remaining
↓
Tests: PASS
↓
Commit
↓
Re-run: 0 errors!
↓
Done! Generate report
```

## 🎯 Loop Exit Conditions

The workflow stops when:

1. **All issues fixed** ✅
2. **No improvement** (quality got worse)
3. **Max iterations reached** (default: 5)
4. **Tests fail** (safety rollback)

## 🔒 Safety Features

### Git Safety

- ✅ Commits only if tests pass
- ✅ Rollbacks if tests fail
- ✅ Each iteration is a separate commit
- ✅ Easy to review/revert changes

### Test Safety

- ✅ Newman tests run after every fix batch
- ✅ Catches breaking changes immediately
- ✅ No bad code gets committed

### Quality Safety

- ✅ Stops if quality degrades
- ✅ Tracks improvement metrics
- ✅ Won't infinite loop

## 📋 Example Report

```json
{
  "title": "PHPQA Auto-Fix Report",
  "timestamp": "2025-12-27T20:00:00Z",
  "summary": {
    "iterations": 3,
    "initialIssues": 500,
    "finalIssues": 0,
    "issuesFixed": 500,
    "testsStatus": "PASSED",
    "reason": "All issues fixed"
  },
  "metrics": {
    "phpcsErrors": 0,
    "phpmdViolations": 0,
    "phpstanErrors": 0
  },
  "tests": {
    "passed": 176,
    "total": 176,
    "failed": 0,
    "success": true
  }
}
```

## 🐛 Troubleshooting

### Workflow Gets Stuck in Loop

**Cause:** Quality not improving  
**Solution:** Check "Should Continue?" node logs

### Tests Keep Failing

**Cause:** Fixes are breaking functionality  
**Solution:** 
1. Review AI prompts
2. Reduce batch size
3. Check specific failing tests

### Newman Not Found

**Cause:** Tests not set up  
**Solution:**
```bash
cd tests/integration
chmod +x run-tests.sh
./run-tests.sh --setup
```

### PHPQA Command Not Found

**Cause:** Composer dependencies not installed  
**Solution:**
```bash
docker exec -u 33 nextcloud bash -c "cd /var/www/html/custom_apps/openregister && composer install"
```

## 💡 Pro Tips

### 1. Start with Small Batches

Test with 2-5 errors first, then increase batch size.

### 2. Monitor First Iteration

Watch the first iteration carefully to ensure:
- AI generates good fixes
- Tests pass
- Commits work

### 3. Review Commits

After workflow completes:
```bash
git log -5 --oneline  # See all auto-fix commits
git show HEAD         # Review latest changes
```

### 4. Schedule Nightly Runs

Add a Cron trigger:
```
0 2 * * *  # Run at 2 AM every night
```

## 🎊 Comparison: Basic vs Enhanced

| Feature | Basic Workflow | Enhanced Workflow |
|---------|---------------|-------------------|
| **PHPQA** | ❌ Only PHPCS | ✅ Full PHPQA suite |
| **Loop** | ❌ Single run | ✅ Iterative improvement |
| **Testing** | ❌ No tests | ✅ Newman tests every iteration |
| **Git** | ❌ Manual | ✅ Auto-commit/rollback |
| **Safety** | ⚠️ Basic | ✅ Multiple safeguards |
| **Metrics** | ⚠️ Limited | ✅ Comprehensive tracking |

## ✨ Summary

The **Enhanced Workflow** provides:

✅ **Comprehensive Quality**: Uses full PHPQA suite  
✅ **Continuous Improvement**: Loops until quality improves  
✅ **Safety First**: Tests before committing, rolls back on failure  
✅ **Git Integration**: Auto-commits good changes  
✅ **Smart Stopping**: Knows when to stop iterating  
✅ **Detailed Reporting**: Tracks all metrics  

**This is production-ready automated code quality improvement!**

---

**Ready to use?**
1. Login: http://localhost:5678 (admin/admin)
2. Import: `enhanced-phpqa-auto-fixer-with-loop-and-testing.json`
3. Execute and watch the magic! 🚀

