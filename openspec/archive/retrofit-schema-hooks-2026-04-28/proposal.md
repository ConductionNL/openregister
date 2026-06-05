# Retrofit — schema-hooks (private helper methods)

Maps 7 private/unlisted methods that the scanner could not match to the existing schema-hooks retrofit-annotate tasks. All behaviors are fully covered — no new REQs required.

## Affected code units
- lib/Listener/HookListener.php::handle (task-65 coverage)
- lib/Listener/HookListener.php::getObjectFromEvent (task-65 coverage, private)
- lib/Service/HookExecutor.php::getObjectFromEvent (task-65 coverage, private)
- lib/Service/HookExecutor.php::isEventStopped (task-68 coverage, private)
- lib/Service/HookExecutor.php::executeSingleHook (task-65 coverage, private)
- lib/Service/HookExecutor.php::setModifiedDataOnEvent (task-67 coverage, private)
- lib/Service/HookExecutor.php::setValidationMetadata (task-69 coverage, private)

## Approach
All 7 methods are private implementation helpers whose public-facing behaviors are already annotated at class level (HookExecutor class docblock lists task-65 through task-72). The scanner missed them because it couldn't follow the private method call chain. Annotating at the method level adds precision without new REQs.

Source: openspec/coverage-report.md generated 2026-04-23. See retrofit playbook.
