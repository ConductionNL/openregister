# Fix: npm run watch - Permission Denied Error

## Problem
When running `npm run watch`, you encounter:
```bash
sh: 1: webpack: Permission denied
```

## Root Cause
The webpack executable in `node_modules/.bin/webpack` (which is a symlink to `node_modules/webpack/bin/webpack.js`) doesn't have execute permissions.

This happens because:
1. Files owned by `www-data` in the Docker container
2. Node modules installed without proper execute permissions
3. WSL file permission handling

## Solution

### Quick Fix
```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister

# Fix webpack specifically
chmod +x node_modules/webpack/bin/webpack.js

# Fix all node_modules executables
find node_modules/.bin -type l -exec sh -c 'target=$(readlink -f "{}"); [ -f "$target" ] && chmod +x "$target"' \; 2>/dev/null

# Try running watch again
npm run watch
```

### Permanent Fix (Add to fix-permissions.sh)
Add this to the `fix-permissions.sh` script we created earlier:

```bash
# Fix node_modules executables
echo "Fixing node_modules executables..."
docker exec -u 0 master-nextcloud-1 bash -c "
    find /var/www/html/apps-extra/openregister/node_modules/.bin -type l -exec sh -c 'target=\$(readlink -f \"{}\"); [ -f \"\$target\" ] && chmod +x \"\$target\"' \; 2>/dev/null
    echo '✓ Node modules executables fixed'
"
```

## Verification

### Check webpack is executable:
```bash
ls -la node_modules/webpack/bin/webpack.js
# Should show: -rwxrwxr-x (executable)
```

### Test webpack directly:
```bash
./node_modules/.bin/webpack --version
# Should show version number without permission error
```

### Run watch mode:
```bash
npm run watch
```

**Expected output:**
```
> openregister@1.0.0 watch
> NODE_ENV=development webpack --config webpack.config.js --progress --watch

<s> [webpack.Progress] 10% building...
<s> [webpack.Progress] 50% building...
webpack 5.94.0 compiled successfully in XXXXX ms
```

## What npm run watch Does

When you run `npm run watch`, it:
1. Sets `NODE_ENV=development`
2. Runs webpack with the config file
3. Watches for file changes
4. Automatically recompiles when you edit:
   - Vue components (`*.vue`)
   - JavaScript files (`*.js`)
   - TypeScript files (`*.ts`)
   - SCSS/CSS files (`*.scss`, `*.css`)

## Using Watch Mode

### Starting Watch Mode:
```bash
# In terminal 1
npm run watch
```

The process will stay running and show:
- Initial compilation status
- File changes detected
- Recompilation progress
- Any errors or warnings

### Making Changes:
1. Edit your Vue component (e.g., `N8nConfiguration.vue`)
2. Save the file
3. Webpack automatically detects the change
4. Recompiles affected modules
5. Updates `js/openregister-main.js`
6. Refresh browser to see changes

### Stopping Watch Mode:
- Press `Ctrl+C` in the terminal
- Or close the terminal

## Webpack Output Files

After compilation, check:
```bash
ls -lh js/
# Should see:
# - openregister-main.js
# - openregister-main.js.map
```

These files are:
- Bundled JavaScript from all Vue components
- Source maps for debugging
- Loaded by Nextcloud when you access OpenRegister

## Troubleshooting

### Still Getting Permission Denied?
```bash
# Check file ownership
ls -la node_modules/webpack/bin/webpack.js

# If owned by www-data, use Docker to fix
docker exec -u 0 master-nextcloud-1 chmod +x /var/www/html/apps-extra/openregister/node_modules/webpack/bin/webpack.js
```

### Webpack Not Found?
```bash
# Reinstall node modules
rm -rf node_modules package-lock.json
npm install
# Then fix permissions again
```

### Compilation Errors?
Check the webpack output for:
- Syntax errors in Vue components
- Missing imports
- TypeScript errors
- SCSS/CSS errors

Fix the reported errors and webpack will auto-recompile.

### Changes Not Showing in Browser?
1. Check webpack compiled successfully
2. Hard refresh browser: `Ctrl+F5` or `Cmd+Shift+R`
3. Clear browser cache
4. Check browser console for JavaScript errors

## Alternative: Build Without Watch

If you just want to build once without watching:
```bash
npm run build
```

This compiles everything once and exits.

## Development Workflow

**Recommended setup:**
1. **Terminal 1**: Run `npm run watch` (keeps running)
2. **Terminal 2**: Git commands, testing, etc.
3. **Browser**: Open OpenRegister settings
4. **Editor**: Edit Vue components

**Workflow:**
1. Start watch mode in Terminal 1
2. Edit `N8nConfiguration.vue` in your editor
3. Save file
4. Watch Terminal 1 for compilation success
5. Refresh browser to see changes
6. Repeat steps 2-5 as needed

## Success Confirmation

✅ **Watch mode is working when you see:**
```
webpack 5.94.0 compiled successfully in XXXXX ms
<s> [webpack.Progress] 100%
```

✅ **And the process stays running (doesn't exit)**

✅ **When you edit a file, you see:**
```
<s> [webpack.Progress] 10% building...
webpack 5.94.0 compiled successfully in XXX ms
```

---

**Issue:** Permission denied on webpack executable  
**Solution:** Fixed with `chmod +x`  
**Status:** ✅ Resolved  
**Watch Mode:** ✅ Running successfully  
**Compilation Time:** ~34 seconds for initial build





