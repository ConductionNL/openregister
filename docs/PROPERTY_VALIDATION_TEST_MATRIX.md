# Property Validation Test Matrix

## Overview

This document defines a comprehensive test matrix for all property validation scenarios in OpenRegister, based on `SchemaPropertyValidatorService.php`.

## Test Categories

### 1. String Type Tests

#### 1.1 String Formats (Valid)
| Format | Valid Example | Test Case |
|--------|---------------|-----------|
| `text` | "Plain text content" | testStringFormatText |
| `markdown` | "# Heading\n\n**Bold**" | testStringFormatMarkdown |
| `html` | "\<p\>HTML content\</p\>" | testStringFormatHtml |
| `date-time` | "2024-01-15T10:30:00Z" | testStringFormatDateTime |
| `date` | "2024-01-15" | testStringFormatDate |
| `time` | "10:30:00" | testStringFormatTime |
| `email` | "user@example.com" | testStringFormatEmail |
| `idn-email` | "用户@例え.jp" | testStringFormatIdnEmail |
| `hostname` | "example.com" | testStringFormatHostname |
| `ipv4` | "192.168.1.1" | testStringFormatIpv4 |
| `ipv6` | "2001:0db8::1" | testStringFormatIpv6 |
| `uri` | "https://example.com/path" | testStringFormatUri |
| `uuid` | "550e8400-e29b-41d4-a716-446655440000" | testStringFormatUuid |
| `url` | "https://www.example.com" | testStringFormatUrl |
| `color` | "#FF5733" | testStringFormatColor |
| `color-hex` | "#FF5733" | testStringFormatColorHex |
| `color-rgb` | "rgb(255, 87, 51)" | testStringFormatColorRgb |
| `color-rgba` | "rgba(255, 87, 51, 0.5)" | testStringFormatColorRgba |
| `semver` | "1.2.3" | testStringFormatSemver |

#### 1.2 String Constraints
| Constraint | Valid | Invalid | Test Case |
|------------|-------|---------|-----------|
| `minLength: 5` | "12345" | "1234" | testStringMinLength |
| `maxLength: 10` | "1234567890" | "12345678901" | testStringMaxLength |
| `pattern: "^[A-Z]"` | "ABC" | "abc" | testStringPattern |
| `enum: ["a","b"]` | "a" | "c" | testStringEnum |

#### 1.3 String Format Errors (Should Fail)
| Format | Invalid Example | Expected Error | Test Case |
|--------|-----------------|----------------|-----------|
| `date` | "15/01/2024" | 400 Bad Request | testStringDateFormatError |
| `email` | "not-an-email" | 400 Bad Request | testStringEmailFormatError |
| `uuid` | "not-a-uuid" | 400 Bad Request | testStringUuidFormatError |
| `url` | "not a url" | 400 Bad Request | testStringUrlFormatError |
| `ipv4` | "999.999.999.999" | 400 Bad Request | testStringIpv4FormatError |

### 2. Number/Integer Type Tests

#### 2.1 Number Constraints
| Type | Constraint | Valid | Invalid | Test Case |
|------|-----------|-------|---------|-----------|
| `integer` | `minimum: 0` | 0, 5 | -1 | testIntegerMinimum |
| `integer` | `maximum: 100` | 50, 100 | 101 | testIntegerMaximum |
| `integer` | `minimum: 18, maximum: 65` | 25 | 17, 66 | testIntegerRange |
| `number` | `minimum: 0.0` | 0.5 | -0.1 | testNumberMinimum |
| `number` | `maximum: 1.0` | 0.5 | 1.1 | testNumberMaximum |
| `number` | `multipleOf: 0.5` | 1.5 | 1.3 | testNumberMultipleOf |

#### 2.2 Number Type Errors
| Type | Invalid Value | Expected Error | Test Case |
|------|---------------|----------------|-----------|
| `integer` | "not a number" | 400 | testIntegerTypeError |
| `integer` | 3.14 | 400 | testIntegerFloatError |
| `number` | "string" | 400 | testNumberTypeError |

### 3. Boolean Type Tests

| Value | Valid | Test Case |
|-------|-------|-----------|
| `true` | ✅ | testBooleanTrue |
| `false` | ✅ | testBooleanFalse |
| `"true"` (string) | ❌ | testBooleanStringError |
| `1` (number) | ❌ | testBooleanNumberError |
| `null` | ❌ | testBooleanNullError |

### 4. Array Type Tests

#### 4.1 Array Constraints
| Constraint | Valid | Invalid | Test Case |
|------------|-------|---------|-----------|
| `items: {type: "string"}` | `["a","b"]` | `["a",1]` | testArrayItemType |
| `minItems: 1` | `["a"]` | `[]` | testArrayMinItems |
| `maxItems: 5` | `["a","b"]` | `["a","b","c","d","e","f"]` | testArrayMaxItems |
| `uniqueItems: true` | `["a","b"]` | `["a","a"]` | testArrayUniqueItems |

#### 4.2 Nested Array Types
| Items Definition | Valid | Invalid | Test Case |
|------------------|-------|---------|-----------|
| `{type: "integer", minimum: 0}` | `[1,2,3]` | `[1,-1,3]` | testArrayNestedConstraints |
| `{type: "object", properties: {...}}` | `[{name:"A"}]` | `[{invalid}]` | testArrayOfObjects |

### 5. Object Type Tests

#### 5.1 Object Structure
| Schema | Valid | Invalid | Test Case |
|--------|-------|---------|-----------|
| `properties: {name: {type: "string"}}` | `{name: "John"}` | `{name: 123}` | testObjectPropertyType |
| `required: ["name"]` | `{name: "John"}` | `{}` | testObjectRequired |
| `additionalProperties: false` | `{name: "John"}` | `{name: "John", extra: "x"}` | testObjectNoAdditional |

#### 5.2 Nested Objects
| Schema | Valid | Test Case |
|--------|-------|-----------|
| Nested object with required fields | `{person: {name: "John"}}` | testNestedObjectRequired |
| Multiple levels deep | `{a: {b: {c: "value"}}}` | testDeepNestedObject |

### 6. File Type Tests

#### 6.1 File Constraints
| Constraint | Valid | Invalid | Test Case |
|------------|-------|---------|-----------|
| `allowedTypes: ["application/pdf"]` | PDF file | JPEG file | testFileAllowedTypes |
| `maxSize: 5242880` (5MB) | 4MB file | 6MB file | testFileMaxSize |
| `allowedTags: ["public","internal"]` | with "public" tag | with "secret" tag | testFileAllowedTags |
| `autoTags: ["uploaded"]` | auto-tagged | - | testFileAutoTags |

#### 6.2 File Upload Methods
| Method | File Type | Test Case |
|--------|-----------|-----------|
| Multipart | PDF, JPEG, PNG | testFileMultipart |
| Base64 | Data URI | testFileBase64 |
| URL | External URL | testFileUrl |

### 7. Combined Constraints Tests

#### 7.1 Multiple Constraints
| Property Config | Valid | Invalid | Test Case |
|-----------------|-------|---------|-----------|
| String: minLength=5, maxLength=10, pattern="^[A-Z]" | "ABCDE" | "abc" / "A" / "ABCDEFGHIJK" | testStringMultipleConstraints |
| Integer: minimum=0, maximum=100, multipleOf=5 | 0, 5, 100 | -5, 3, 105 | testIntegerMultipleConstraints |

### 8. Special Property Flags

| Flag | Type | Valid Values | Test Case |
|------|------|--------------|-----------|
| `visible` | boolean | true, false | testPropertyVisible |
| `hideOnCollection` | boolean | true, false | testPropertyHideOnCollection |
| `hideOnForm` | boolean | true, false | testPropertyHideOnForm |
| `readOnly` | boolean | true, false | testPropertyReadOnly |
| `writeOnly` | boolean | true, false | testPropertyWriteOnly |

### 9. Schema-Level Tests

#### 9.1 Required Fields
| Schema Config | Object Data | Should Pass | Test Case |
|---------------|-------------|-------------|-----------|
| `required: ["name"]` | `{name: "John"}` | ✅ | testRequiredFieldPresent |
| `required: ["name"]` | `{}` | ❌ | testRequiredFieldMissing |
| `required: ["name", "email"]` | `{name: "John"}` | ❌ | testMultipleRequiredPartial |

#### 9.2 Optional Fields
| Schema Config | Object Data | Should Pass | Test Case |
|---------------|-------------|-------------|-----------|
| `properties: {name, email}` (no required) | `{name: "John"}` | ✅ | testOptionalFieldPartial |
| `properties: {name, email}` (no required) | `{}` | ✅ | testOptionalFieldEmpty |

### 10. OneOf Tests

| Schema Config | Valid | Invalid | Test Case |
|---------------|-------|---------|-----------|
| `oneOf: [{type: "string"}, {type: "integer"}]` | "text" OR 42 | true | testOneOfValid |
| `oneOf: [{type: "string"}, {type: "integer"}]` | `{object}` | ❌ | testOneOfInvalid |

### 11. Edge Cases

#### 11.1 Boundary Values
| Test | Value | Expected | Test Case |
|------|-------|----------|-----------|
| String maxLength exactly | "1234567890" (len=10, max=10) | ✅ | testStringMaxLengthExact |
| Integer minimum exactly | 0 (min=0) | ✅ | testIntegerMinimumExact |
| Integer maximum exactly | 100 (max=100) | ✅ | testIntegerMaximumExact |
| Array minItems exactly | `["a"]` (len=1, min=1) | ✅ | testArrayMinItemsExact |

#### 11.2 Empty/Null Values
| Property Type | Value | Required | Should Pass | Test Case |
|---------------|-------|----------|-------------|-----------|
| `string` | "" | yes | ❌ | testStringEmptyRequired |
| `string` | "" | no | ✅ | testStringEmptyOptional |
| `string` | null | no | ✅ | testStringNullOptional |
| `array` | [] | no | ✅ | testArrayEmptyOptional |
| `object` | {} | no | ✅ | testObjectEmptyOptional |

#### 11.3 Type Coercion
| Property Type | Input Value | Should Pass | Test Case |
|---------------|-------------|-------------|-----------|
| `integer` | "123" (string) | ❓ | testIntegerStringCoercion |
| `boolean` | 1 (number) | ❌ | testBooleanNumberNoCoercion |
| `number` | "3.14" (string) | ❓ | testNumberStringCoercion |

### 12. Error Response Format Tests

All invalid inputs should return consistent error responses:

```json
{
  "error": "Validation failed",
  "message": "Property 'age' failed validation: value must be >= 18",
  "property": "age",
  "constraint": "minimum",
  "value": 15
}
```

Test cases:
- `testErrorResponseFormat` - Verify error structure
- `testErrorMessageClarity` - Verify error messages are clear
- `testMultipleErrors` - Multiple validation errors returned
- `testNestedPropertyErrors` - Errors in nested properties have correct path

## Implementation Priority

### Phase 1: Basic Types (Complete ✅)
- String (basic)
- Number/Integer min/max
- Boolean
- Required vs Optional
- Enum
- Pattern

### Phase 2: Advanced String Formats (Next)
- All string formats from `validStringFormats`
- Format validation errors

### Phase 3: Complex Types
- Array with all constraints
- Nested objects
- OneOf

### Phase 4: File Properties
- All file constraints
- File upload methods
- File validation errors

### Phase 5: Edge Cases
- Boundary values
- Empty/null values
- Type coercion
- Error response format

## Test Organization

Tests should be organized in the following structure:

```php
class CoreIntegrationTest extends TestCase
{
    // File Upload Tests (1-12) ✅
    
    // Basic Property Validation (13-21) ✅
    
    // String Format Matrix (22-40)
    public function testStringFormatDate(): void
    public function testStringFormatDateTime(): void
    public function testStringFormatEmail(): void
    // ...
    
    // Number/Integer Matrix (41-50)
    public function testIntegerMinimum(): void
    public function testNumberMultipleOf(): void
    // ...
    
    // Array Tests (51-60)
    public function testArrayItemType(): void
    public function testArrayMinItems(): void
    // ...
    
    // File Property Tests (61-70)
    public function testFileAllowedTags(): void
    public function testFileAutoTags(): void
    // ...
    
    // Edge Cases (71-80)
    public function testStringMaxLengthExact(): void
    public function testEmptyValues(): void
    // ...
    
    // Error Response Tests (81-90)
    public function testErrorResponseFormat(): void
    public function testMultipleValidationErrors(): void
    // ...
}
```

## Expected Test Count

- File Upload Tests: 12
- Basic Validation: 9 (13-21)
- String Format Tests: ~20
- Number/Integer Tests: ~10
- Array Tests: ~10
- Object Tests: ~8
- File Property Tests: ~10
- Edge Cases: ~10
- Error Response Tests: ~5

**Total: ~94 comprehensive integration tests**

This ensures complete coverage of all validation scenarios in OpenRegister.

