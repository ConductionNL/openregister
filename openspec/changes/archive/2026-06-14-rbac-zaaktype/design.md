# Design: RBAC per Zaaktype

## Approach
Implement the requirements defined in the spec using OpenRegister's existing service architecture.

## Files Affected
- `lib/Db/AuditTrail.php`
- `lib/Db/MagicMapper/MagicRbacHandler.php`
- `lib/Db/MultiTenancyTrait.php`
- `lib/Db/Schema.php`
- `lib/Service/ConditionMatcher.php`
- `lib/Service/Object/PermissionHandler.php`
- `lib/Service/OperatorEvaluator.php`
- `lib/Service/PropertyRbacHandler.php`
