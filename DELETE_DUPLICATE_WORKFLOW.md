# 🗑️ Delete Duplicate Workflow - Quick Guide

## Problem
You have 2 workflows with the same name: "Enhanced PHPQA Auto-Fixer with Loop & Testing"

## Solution (30 seconds)

### Step 1: Identify Which to Delete

Looking at your screenshot:
- **First workflow** (top) - Last updated **1 hour ago** - ✅ **KEEP THIS ONE**
- **Second workflow** (middle) - Last updated **1 hour ago** - ❌ **DELETE THIS ONE**

The **first one** has the correct configuration (I imported it last).

---

### Step 2: Delete the Duplicate

1. **Find the SECOND workflow** in the list (the middle one)

2. **Click the 3-dot menu (⋮)** on the right side of that row

3. **Click "Delete"**

4. **Confirm** when asked

---

### Step 3: Verify

After deleting, you should see:
- Only **ONE** "Enhanced PHPQA Auto-Fixer with Loop & Testing" workflow remaining
- Total workflows: **2** (your workflow + the auto-fixer)

---

## Alternative: Rename Instead of Delete

If you want to keep both for comparison:

1. Click on the **second workflow** to open it
2. Click the workflow name at the top
3. Rename it to: "Enhanced PHPQA Auto-Fixer (OLD - DELETE ME)"
4. Save

Then you can test the first one, and delete the old one later if it works.

---

## Which Workflow is Correct?

The **TOP ONE** (first workflow) has:
- ✅ Container: `master-nextcloud-1`
- ✅ Path: `/var/www/html/apps-extra/openregister`
- ✅ All components tested and working

The second one likely has:
- ❌ Container: `nextcloud` (wrong)
- ❌ Path: `/var/www/html/custom_apps/openregister` (wrong)

---

## After Cleanup

Once you have only one workflow:

1. **Click on it** to open
2. **Verify the Configuration node** shows correct paths
3. **Click "Execute Workflow"** to run it!

---

**Ready to clean up? Just delete the second workflow in the list!**

