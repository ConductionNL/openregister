# Circular Dependency Root Cause

## Summary
Using phpmetrics analysis tool, we discovered the circular dependency chain.

## The Chain
1. **ObjectService** → injects **SaveObject**
2. **SaveObject** → injects (via Application.php line 446, 452):
   - FileService (REMOVED)
   - OrganisationService (REMOVED)
   - FilePropertyHandler
3. **SaveObject/FilePropertyHandler** → injects **FileService** (FIXED)
4. **ObjectService** → injects **SaveObjects**  
5. **SaveObjects** → injects **PreparationHandler**, **TransformationHandler**
6. **SaveObjects/PreparationHandler** → injects **OrganisationService** (FIXED)
7. **SaveObjects/TransformationHandler** → injects **OrganisationService** (FIXED)
8. **ObjectService** → injects **SettingsService**
9. **SettingsService** → lazy loads **ObjectService** via `container->get()` (FIXED)
10. **ObjectService** → used by **ConfigurationService**
11. **ConfigurationService** → injects **ObjectService** in Application.php:488 (FIXED)

## Still Fails
After fixing all 13+ circular dependency points, app STILL won't load.

## Next Investigation
Must be an even deeper chain or Application.php autowiring issue.

