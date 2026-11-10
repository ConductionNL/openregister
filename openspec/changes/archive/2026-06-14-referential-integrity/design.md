# Design: Referential Integrity

## Approach
Implement the requirements defined in the spec using OpenRegister's existing service architecture.

## Files Affected
- `lib/Db/Schema.php`
- `lib/Dto/DeletionAnalysis.php`
- `lib/Exception/ReferentialIntegrityException.php`
- `lib/Service/Object/CascadingHandler.php`
- `lib/Service/Object/DeleteObject.php`
- `lib/Service/Object/ReferentialIntegrityService.php`
- `lib/Service/Object/RelationHandler.php`
- `lib/Service/Object/SaveObject.php`
- `lib/Service/Object/SaveObject/RelationCascadeHandler.php`
