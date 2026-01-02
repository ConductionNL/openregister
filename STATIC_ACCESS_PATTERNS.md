# Static Access Patterns - Design Decisions

This document explains our approach to static method calls and dependency injection in the OpenRegister codebase, particularly in response to PHPMD warnings about static access.

## Summary of Refactoring (December 2024)

We addressed PHPMD 'StaticAccess' violations by:
1. **Fixed**: Replaced entity static validation methods with proper dependency injection
2. **Fixed**: Converted service static methods to instance methods with DI
3. **Fixed**: Added missing use statements for Exception classes
4. **Accepted**: Kept legitimate static factory and utility patterns

## Fixed Issues

### 1. Organisation::isValidUuid() - REFACTORED ✅

**Before:**
```php
if ($uuid !== '' && Organisation::isValidUuid($uuid) === false) {
    throw new Exception('Invalid UUID format.');
}
```

**After:**
```php
use Symfony\Component\Uid\Uuid;

if ($uuid !== '' && Uuid::isValid($uuid) === false) {
    throw new Exception('Invalid UUID format.');
}
```

**Reason:** Entity classes should not contain static validation methods. Use Symfony's UUID library directly.

### 2. SetupHandler::getObjectEntityFieldDefinitions() - REFACTORED ✅

**Before:**
```php
$expectedFields = \OCA\OpenRegister\Service\Index\SetupHandler::getObjectEntityFieldDefinitions();
```

**After:**
```php
class SettingsService {
    private SetupHandler $setupHandler;
    
    public function __construct(SetupHandler $setupHandler, ...) {
        $this->setupHandler = $setupHandler;
    }
    
    public function getExpectedSchemaFields(...) {
        $expectedFields = $this->setupHandler->getObjectEntityFieldDefinitions();
    }
}
```

**Reason:** Service methods should be instance methods with proper dependency injection for testability.

### 3. Uuid::v4() in EntityRecognitionHandler - REFACTORED ✅

**Before:**
```php
use Ramsey\Uuid\Uuid;

$entity->setUuid((string) Uuid::v4());
```

**After:**
```php
use Symfony\Component\Uid\Uuid;

$entity->setUuid((string) Uuid::v4());
```

**Reason:** Nextcloud uses Symfony UID components, not Ramsey UUID. Standardize on Symfony for consistency.

### 4. Missing Use Statements - FIXED ✅

**Before:**
```php
throw new \Exception('Message');
```

**After:**
```php
use Exception;

throw new Exception('Message');
```

**Reason:** Always import classes at the top of the file instead of using fully qualified names.

## Accepted Patterns

The following static access patterns are **acceptable** and were intentionally kept:

### 1. DateTime Factory Methods ✅

```php
$parsed = DateTime::createFromFormat('Y-m-d', $value);
```

**Why acceptable:**
- Standard PHP DateTime API uses static factory methods
- This is the correct and intended usage pattern
- Not a violation of dependency injection principles
- DateTime is not a service, it's a value object

### 2. Third-Party Library Factories ✅

```php
// PhpOffice libraries
$phpWord = WordIOFactory::load($tempPath);
$spreadsheet = SpreadsheetIOFactory::load($tempPath);
```

**Why acceptable:**
- Standard factory pattern from external libraries
- These are utility factories, not services
- Wrapping them would add unnecessary complexity
- The file content can still be mocked for testing

### 3. Symfony YAML Parser ✅

```php
use Symfony\Component\Yaml\Yaml;

$phpArray = Yaml::parse($responseBody);
```

**Why acceptable:**
- Symfony's YAML component uses static utility methods
- Standard pattern for parsers and formatters
- Pure functions with no side effects
- Similar to json_decode() - it's a utility, not a service

### 4. Symfony UUID Utilities ✅

```php
use Symfony\Component\Uid\Uuid;

$uuid = Uuid::v4();
$isValid = Uuid::isValid($uuidString);
```

**Why acceptable:**
- Symfony's intended API for UUID generation/validation
- Factory methods and validators are appropriate as static
- No state, no dependencies - pure utility functions
- Used throughout Nextcloud core

### 5. Exception Factory Methods ✅

```php
throw DatabaseConstraintException::fromMessage('Constraint violation');
```

**Why acceptable:**
- Named constructors (factory methods) for exceptions are a common pattern
- Provides better semantics than multiple constructor parameters
- Exceptions are value objects, not services

## Guidelines for Future Development

### When to Use Static Methods

Static methods are **acceptable** for:
1. **Pure utility functions** with no dependencies or state
2. **Factory methods** that create instances (named constructors)
3. **Third-party library APIs** that require static calls
4. **Value object creation** (DateTime, UUID, etc.)
5. **Constants and enums** (PHP 8.1+)

### When to Use Dependency Injection

Use instance methods with DI for:
1. **Service classes** that have dependencies
2. **Business logic** that needs testing with mocks
3. **Methods that access external resources** (database, filesystem, network)
4. **Methods with side effects** (logging, events, caching)
5. **Complex validation** that requires multiple dependencies

### Example Decision Tree

```
Does the method access a dependency (DB, logger, service)?
├─ YES → Use instance method with DI
└─ NO
   ├─ Is it a pure function (no side effects)?
   │  ├─ YES
   │  │  ├─ Is it a utility (like parsing, formatting)?
   │  │  │  ├─ YES → Static method OK
   │  │  │  └─ NO → Consider instance method
   │  │  └─ Is it a factory/constructor?
   │  │     ├─ YES → Static method OK (named constructor pattern)
   │  │     └─ NO → Use instance method
   │  └─ NO (has side effects) → Use instance method with DI
   └─ Is it from a third-party library?
      ├─ YES → Accept as-is (can't change)
      └─ NO → Refactor to instance method
```

## Testing Considerations

### Testing Static Methods
```php
// Static utility methods are easy to test
public function testUuidValidation(): void {
    $this->assertTrue(Uuid::isValid('550e8400-e29b-41d4-a716-446655440000'));
    $this->assertFalse(Uuid::isValid('invalid'));
}
```

### Testing with Dependency Injection
```php
// Instance methods allow mocking dependencies
public function testGetExpectedFields(): void {
    $setupHandler = $this->createMock(SetupHandler::class);
    $setupHandler->expects($this->once())
        ->method('getObjectEntityFieldDefinitions')
        ->willReturn(['field1' => [...], 'field2' => [...]]);
    
    $service = new SettingsService($setupHandler, ...);
    $fields = $service->getExpectedSchemaFields(...);
    
    $this->assertArrayHasKey('field1', $fields);
}
```

## PHPMD Configuration

Our `phpmd.xml` includes the StaticAccess rule, but we accept certain violations as documented above. To suppress false positives, add annotations:

```php
/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 * Reason: Using Symfony UUID utility as intended
 */
public function generateId(): string {
    return (string) Uuid::v4();
}
```

## Summary

This refactoring:
- **Improved testability** by removing unnecessary static methods from services
- **Maintained pragmatism** by keeping standard library and utility static calls
- **Enhanced consistency** by using Nextcloud's preferred libraries (Symfony UUID)
- **Added clarity** with proper use statements

Not all static access is bad - context matters. We follow the principle of using the right tool for the job.

## References

- [Nextcloud Developer Manual - Dependency Injection](https://docs.nextcloud.com/server/latest/developer_manual/basics/controllers.html#dependency-injection)
- [Symfony UUID Component](https://symfony.com/doc/current/components/uid.html)
- [PHP The Right Way - Design Patterns](https://phptherightway.com/pages/Design-Patterns.html)
- [PHPMD Rules - StaticAccess](https://phpmd.org/rules/controversial.html#staticaccess)












