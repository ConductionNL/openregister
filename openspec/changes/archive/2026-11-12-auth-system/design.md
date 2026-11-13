# Design: Authentication and Authorization System

## Approach
Implement the requirements defined in the spec using OpenRegister's existing service architecture.

## Files Affected
- `lib/Controller/ConsumersController.php`
- `lib/Db/Consumer.php`
- `lib/Db/ConsumerMapper.php`
- `lib/Db/MagicMapper/MagicRbacHandler.php`
- `lib/Db/MultiTenancyTrait.php`
- `lib/Service/AuthenticationService.php`
- `lib/Service/AuthorizationService.php`
- `lib/Service/ConditionMatcher.php`
- `lib/Service/Object/PermissionHandler.php`
- `lib/Service/OperatorEvaluator.php`
- `lib/Service/PropertyRbacHandler.php`
- `lib/Service/SecurityService.php`
- `lib/Twig/AuthenticationExtension.php`
- `lib/Twig/AuthenticationRuntime.php`
