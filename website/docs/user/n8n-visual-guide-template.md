# n8n Workflow Configuration - Visual Guide

This guide provides step-by-step screenshots for configuring n8n workflow integration in OpenRegister.

## Prerequisites

Before starting, ensure you have:
- OpenRegister installed and running
- n8n instance accessible
- Admin access to both systems

## Step-by-Step Visual Guide

### Step 1: Navigate to Settings

![Navigate to Settings](./screenshots/n8n-step1-navigate-settings.png)

**Actions:**
1. Click on your profile icon in the top right corner
2. Select "Admin Settings" (Beheerder instellingen)
3. Navigate to OpenRegister settings

**What you should see:**
- Settings navigation menu on the left
- OpenRegister settings sections displayed

---

### Step 2: Locate Workflow Configuration Section

![Workflow Configuration Section](./screenshots/n8n-step2-workflow-section.png)

**Actions:**
1. Scroll down in the settings page
2. Find the "Workflow Configuration" section
3. It should be located after "Search Configuration" and before "LLM Configuration"

**What you should see:**
- Section titled "Workflow Configuration"
- Description: "Configure n8n workflow automation integration"
- A toggle switch (initially disabled)
- Current status showing "n8n workflow integration disabled"

---

### Step 3: Enable n8n Integration

![Enable n8n Integration](./screenshots/n8n-step3-enable-toggle.png)

**Actions:**
1. Click the toggle switch to enable n8n integration
2. The switch should turn blue/green

**What you should see:**
- Toggle switch in "enabled" state
- Status changes to "n8n workflow integration enabled"
- Connection settings form becomes visible
- Saving indicator briefly appears

---

### Step 4: Enter n8n Connection Settings

![Connection Settings Form](./screenshots/n8n-step4-connection-settings.png)

**Actions:**
1. Fill in the **n8n Base URL**: `http://master-n8n-1:5678`
   (or your n8n instance URL)
2. Fill in the **n8n API Key**: Get this from n8n Settings → API
3. Fill in the **Project Name**: `openregister` (default)

**Form Fields:**
```
n8n Base URL:  http://master-n8n-1:5678
n8n API Key:   n8n_api_••••••••••••••••••
Project Name:  openregister
```

**What you should see:**
- Three input fields visible
- API key field shows masked characters (•••)
- Hint text under each field explaining its purpose
- "Save Configuration", "Test Connection", buttons visible (but not yet active)

---

### Step 5: Save Configuration

![Save Configuration](./screenshots/n8n-step5-save-configuration.png)

**Actions:**
1. Click the "Save Configuration" button
2. Wait for the success message

**What you should see:**
- Loading indicator on the Save button
- Success message: "n8n configuration saved successfully" (green notification)
- "Configuration saved" indicator with checkmark icon
- Save button becomes disabled (no changes to save)

---

### Step 6: Test Connection

![Test Connection](./screenshots/n8n-step6-test-connection.png)

**Actions:**
1. Click the "Test Connection" button
2. Wait for the test to complete (usually 2-5 seconds)

**What you should see (Success):**
- Loading indicator on Test button
- Success message box appears below the form:
  - Green checkmark icon
  - Message: "n8n connection successful"
  - Connection details showing:
    - n8n Version: Connected
    - Number of users

**What you might see (Error):**
- Red error message box:
  - Error icon
  - Message describing the issue (e.g., "Connection failed", "Invalid API key")

---

### Step 7: Initialize Project

![Initialize Project](./screenshots/n8n-step7-initialize-project.png)

**Actions:**
1. After successful connection test, click "Initialize Project"
2. Wait for initialization to complete

**What you should see (Success):**
- Loading indicator on Initialize button
- Success message box appears:
  - Green checkmark icon
  - Message: "n8n project initialized successfully"
  - Project details showing:
    - Project: openregister
    - Project ID: (numeric ID)
    - Workflows: X workflow(s) ready

**Result:**
- The project is now created in n8n
- Workflow Management section becomes visible

---

### Step 8: Workflow Management Section

![Workflow Management](./screenshots/n8n-step8-workflow-management.png)

**What you should see:**
- New collapsible section: "Workflow Management"
- Section intro text explaining workflow management
- Two buttons:
  - "Refresh Workflows" - reload workflow list
  - "Open n8n Editor" - opens n8n in new tab
- If workflows exist, they are listed with:
  - Workflow name
  - Status badge (Active/Inactive)
  - Tags (if any)

---

### Step 9: Open n8n Editor

![Open n8n Editor](./screenshots/n8n-step9-open-editor.png)

**Actions:**
1. Click "Open n8n Editor" button
2. New tab opens with n8n interface

**What you should see:**
- n8n editor interface in a new browser tab
- Your "openregister" project visible/selected
- Ability to create new workflows

---

### Step 10: Create a Test Workflow in n8n

![Create Test Workflow](./screenshots/n8n-step10-create-workflow.png)

**Actions in n8n:**
1. Click "+ New workflow" or "Add workflow"
2. Add a simple Manual trigger node
3. Add an HTTP Request node or other node
4. Save the workflow with a descriptive name (e.g., "Test Workflow")
5. Ensure the workflow is assigned to the "openregister" project

**What you should see:**
- n8n workflow canvas
- Workflow nodes connected
- Workflow saved successfully
- Project assignment: openregister

---

### Step 11: View Workflows in OpenRegister

![View Workflows List](./screenshots/n8n-step11-view-workflows.png)

**Actions:**
1. Return to OpenRegister settings tab
2. In the Workflow Management section, click "Refresh Workflows"

**What you should see:**
- Loading indicator briefly appears
- Workflow list updates
- Your test workflow appears in the list:
  - Workflow name: "Test Workflow"
  - Status badge: Active or Inactive
  - Tags (if you added any in n8n)

---

### Step 12: Complete Setup View

![Complete Setup](./screenshots/n8n-step12-complete-setup.png)

**Final view showing:**
- ✅ n8n integration enabled
- ✅ Connection successful
- ✅ Project initialized
- ✅ Workflows visible
- All sections expanded showing full configuration

---

## Screenshot Capture Guide

### How to Capture These Screenshots

1. **Open OpenRegister** in your browser
2. **Navigate to Settings**
3. **Use browser screenshot tool** or press:
   - Windows: `Win + Shift + S`
   - Mac: `Cmd + Shift + 4`
   - Linux: `PrtScr` or screenshot tool

4. **Capture each step** as you follow the configuration process
5. **Save screenshots** with the naming convention:
   - `n8n-step1-navigate-settings.png`
   - `n8n-step2-workflow-section.png`
   - etc.

6. **Place screenshots** in the `website/docs/user/screenshots/` directory

### Screenshot Tips

- **Resolution**: Capture at a reasonable resolution (1920x1080 or similar)
- **Full width**: Capture the full settings panel width
- **Highlight important elements**: Consider adding red arrows or boxes to highlight key buttons/fields
- **Clean UI**: Remove any personal data or test data that shouldn't be public
- **Consistent state**: Try to keep the same browser theme/settings across all screenshots

### Tools for Adding Annotations

If you want to add arrows, boxes, or text to screenshots:
- **Windows**: Snipping Tool, Paint, or Greenshot
- **Mac**: Preview (built-in), Skitch, or Monosnap
- **Linux**: GIMP, Shutter, or Flameshot
- **Cross-platform**: Inkscape, GIMP, or online tools like Photopea

---

## Alternative: Video Tutorial

Instead of screenshots, you could also create a video tutorial:

1. **Record your screen** using:
   - OBS Studio (free, cross-platform)
   - Loom (online, easy to use)
   - Windows Game Bar (`Win + G`)
   - Mac QuickTime Screen Recording

2. **Follow the steps** while narrating what you're doing

3. **Upload to YouTube** (unlisted or public)

4. **Embed in documentation**:
   ```markdown
   <iframe width="560" height="315" 
     src="https://www.youtube.com/embed/YOUR_VIDEO_ID" 
     frameborder="0" allowfullscreen>
   </iframe>
   ```

---

## Adding Screenshots to Documentation

Once you have the screenshots, update the main documentation file:

```markdown
## Configuration Steps

### 1. Navigate to Settings

[Screenshot here]

To access the n8n Workflow Configuration:
1. Click your profile icon
2. Select "Admin Settings"
...
```

Or use the included screenshot references:

```markdown
![Navigate to Settings](screenshots/n8n-step1-navigate-settings.png)
*Figure 1: Navigating to OpenRegister Settings*
```

---

## Troubleshooting Screenshots

If a screenshot doesn't capture properly:

1. **Check browser zoom**: Set to 100%
2. **Maximize window**: Ensure full visibility
3. **Wait for loading**: Let animations/spinners complete
4. **Try different browser**: Sometimes Firefox/Chrome renders differently
5. **Use developer tools**: F12 → Device toolbar for consistent resolution

---

## Next Steps

After capturing all screenshots:

1. Review each image for quality and clarity
2. Add annotations if needed (arrows, highlights, text)
3. Place in the `website/docs/user/screenshots/` directory
4. Update the main documentation to reference them
5. Test the documentation flow by following your own guide
6. Ask a colleague to test the setup using only the visual guide

---

## Document Metadata

**Created**: 2025-12-28  
**Author**: Conduction Development Team  
**For**: OpenRegister n8n Integration  
**Purpose**: Visual configuration guide with screenshot placeholders

