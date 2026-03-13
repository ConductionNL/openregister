# Entity __toString() Magic Method Fix

## Problem Description

When saving object entities in the OpenRegister application, the system was encountering the following error:

```
Object of class OCA\OpenRegister\Db\Organisation could not be converted to string in file '/var/www/html/lib/public/AppFramework/Db/Entity.php' line 115
```

This error occurred because the Nextcloud framework was attempting to convert entity objects to strings during entity operations, but the entity classes lacked the required `__toString()` magic method.

## Root Cause

The issue was identified in the `SaveObject` service where:

1. `getOrganisationForNewEntity()` returns a **string** (UUID)
2. `ensureDefaultOrganisation()` returns an **Organisation object**

When `ensureDefaultOrganisation()` was called, it returned an `Organisation` object, but the `setOrganisation()` method expected a string UUID. The framework then attempted to convert the `Organisation` object to a string, which failed because the class didn't have a `__toString()` method.

## Solution Implemented

### 1. Fixed SaveObject Service Logic

Updated the `SaveObject` service to properly handle the return value from `ensureDefaultOrganisation()`:

```php
// Before (causing the error)
$organisationUuid = $this->organisationService->ensureDefaultOrganisation();
$objectEntity->setOrganisation($organisationUuid);

// After (fixed)
$organisation = $this->organisationService->ensureDefaultOrganisation();
$organisationUuid = $organisation->getUuid();
$objectEntity->setOrganisation($organisationUuid);
```

### 2. Added __toString() Methods to All Entity Classes

Added `__toString()` magic methods to all entity classes to prevent similar issues in the future:

#### Organisation Class
```php
public function __toString(): string
{
    if ($this->name !== null && $this->name !== '') {
        return $this->name;
    }
    
    if ($this->slug !== null && $this->slug !== '') {
        return $this->slug;
    }
    
    return 'Organisation #' . ($this->id ?? 'unknown');
}
```

#### Register Class
```php
public function __toString(): string
{
    if ($this->slug !== null && $this->slug !== '') {
        return $this->slug;
    }
    
    if ($this->title !== null && $this->title !== '') {
        return $this->title;
    }
    
    return 'Register #' . ($this->id ?? 'unknown');
}
```

#### Schema Class
```php
public function __toString(): string
{
    if ($this->slug !== null && $this->slug !== '') {
        return $this->slug;
    }
    
    if ($this->title !== null && $this->title !== '') {
        return $this->title;
    }
    
    return 'Schema #' . ($this->id ?? 'unknown');
}
```

#### ObjectEntity Class
```php
public function __toString(): string
{
    if ($this->uuid !== null && $this->uuid !== '') {
        return $this->uuid;
    }
    
    if ($this->id !== null) {
        return 'Object #' . $this->id;
    }
    
    return 'Object Entity';
}
```

#### SearchTrail Class
```php
public function __toString(): string
{
    if ($this->uuid !== null && $this->uuid !== '') {
        return $this->uuid;
    }
    
    if ($this->searchTerm !== null && $this->searchTerm !== '') {
        return 'Search: ' . $this->searchTerm;
    }
    
    if ($this->id !== null) {
        return 'SearchTrail #' . $this->id;
    }
    
    return 'Search Trail';
}
```

#### AuditTrail Class
```php
public function __toString(): string
{
    if ($this->uuid !== null && $this->uuid !== '') {
        return $this->uuid;
    }
    
    if ($this->action !== null && $this->action !== '') {
        return 'Audit: ' . $this->action;
    }
    
    if ($this->id !== null) {
        return 'AuditTrail #' . $this->id;
    }
    
    return 'Audit Trail';
}
```

#### DataAccessProfile Class
```php
public function __toString(): string
{
    if ($this->name !== null && $this->name !== '') {
        return $this->name;
    }
    
    if ($this->uuid !== null && $this->uuid !== '') {
        return $this->uuid;
    }
    
    if ($this->id !== null) {
        return 'DataAccessProfile #' . $this->id;
    }
    
    return 'Data Access Profile';
}
```

#### Source Class
```php
public function __toString(): string
{
    if ($this->title !== null && $this->title !== '') {
        return $this->title;
    }
    
    if ($this->uuid !== null && $this->uuid !== '') {
        return $this->uuid;
    }
    
    if ($this->id !== null) {
        return 'Source #' . $this->id;
    }
    
    return 'Source';
}
```

#### Configuration Class
```php
public function __toString(): string
{
    if ($this->title !== null && $this->title !== '') {
        return $this->title;
    }
    
    if ($this->type !== null && $this->type !== '') {
        return 'Config: ' . $this->type;
    }
    
    if ($this->id !== null) {
        return 'Configuration #' . $this->id;
    }
    
    return 'Configuration';
}
```

## Benefits of This Fix

1. **Prevents String Conversion Errors**: All entity classes now have proper string representations
2. **Improves Debugging**: Better error messages and logging when entities are converted to strings
3. **Framework Compatibility**: Ensures compatibility with Nextcloud's entity handling mechanisms
4. **Future-Proofing**: Prevents similar issues from occurring with other entity operations

## Testing

The fix has been implemented and all modified files have been syntax-checked to ensure no PHP syntax errors were introduced.

## Files Modified

- `lib/Service/ObjectHandlers/SaveObject.php` - Fixed organisation handling logic
- `lib/Db/Organisation.php` - Added __toString() method
- `lib/Db/Register.php` - Added __toString() method
- `lib/Db/Schema.php` - Added __toString() method
- `lib/Db/ObjectEntity.php` - Added __toString() method
- `lib/Db/SearchTrail.php` - Added __toString() method
- `lib/Db/AuditTrail.php` - Added __toString() method
- `lib/Db/DataAccessProfile.php` - Added __toString() method
- `lib/Db/Source.php` - Added __toString() method
- `lib/Db/Configuration.php` - Added __toString() method

## Related Issues

This fix addresses the string conversion error that was preventing object entities from being saved properly in the OpenRegister application.

## Prevention

To prevent similar issues in the future:

1. Always ensure entity classes have `__toString()` methods
2. Be careful when mixing object returns and string expectations in service methods
3. Test entity operations thoroughly, especially when dealing with relationships
4. Follow consistent patterns for entity handling across the application
