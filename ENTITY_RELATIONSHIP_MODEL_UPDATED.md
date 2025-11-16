# Entity Relationship Model - Updated Structure

## Overview

This document describes the **updated entity relationship model** based on user feedback to create a more efficient and intuitive structure for GDPR entity management.

## Key Changes

### 1. BelongsTo Field on Entity (Many-to-One)

**Instead of**: Separate `EntityLink` table for all relationships (many-to-many)

**Now**: Direct `belongs_to_entity_id` field on each entity (many-to-one)

**Rationale**: Most contact information belongs to exactly ONE person or organization, making a foreign key more efficient than a join table.

### 2. Role Field on EntityRelation

**Added**: `role` field to track entity context for GDPR compliance

**Purpose**: Determine if an entity occurrence requires anonymization:
- **public_figure**: May not require anonymization
- **employee**: In official capacity, may not require anonymization
- **private_individual**: Always requires anonymization
- **customer**, **contractor**, **author**, **recipient**, **mentioned**: Context-dependent

### 3. Source Tracking on EntityRelation

**Added**: `file_id`, `object_id`, `email_id` fields to track ultimate source

**Purpose**: 
- Chunks may change over time (re-chunking, updates)
- GDPR requests need original source documents
- Anonymization must trace back to original files
- Audit trails require source document references

### 4. Source Tracking on Chunk

**Clarified**: `source_type` and `source_id` track the **ultimate source**, not intermediate text entities

**Values**:
- `source_type`: 'file', 'object', 'mail', 'chat'
- `source_id`: The actual file/object/email/chat ID (not the text extraction record ID)

## Updated Entity Structure

### Entity Table Schema

```sql
CREATE TABLE oc_openregister_entities (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid VARCHAR(255) NOT NULL UNIQUE,
    type VARCHAR(50) NOT NULL,
    value TEXT NOT NULL,
    category VARCHAR(50) NOT NULL,
    belongs_to_entity_id BIGINT,  -- NEW: Direct parent reference
    metadata JSON,
    owner VARCHAR(255),
    organisation VARCHAR(255),
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_type (type),
    INDEX idx_category (category),
    INDEX idx_belongs_to (belongs_to_entity_id),  -- NEW: Index for parent lookups
    
    FOREIGN KEY (belongs_to_entity_id) REFERENCES oc_openregister_entities(id) ON DELETE SET NULL
);
```

### EntityRelation Table Schema

```sql
CREATE TABLE oc_openregister_entity_relations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    entity_id BIGINT NOT NULL,
    chunk_id BIGINT NOT NULL,
    role VARCHAR(50),              -- NEW: Entity role/context
    file_id BIGINT,                -- NEW: Original file source
    object_id BIGINT,              -- NEW: Original object source
    email_id BIGINT,               -- NEW: Original email source
    position_start INT NOT NULL,
    position_end INT NOT NULL,
    confidence DECIMAL(3,2) NOT NULL,
    detection_method VARCHAR(50) NOT NULL,
    context TEXT,
    anonymized BOOLEAN NOT NULL DEFAULT FALSE,
    anonymized_value VARCHAR(255),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_role (role),         -- NEW: Query by role
    INDEX idx_file (file_id),      -- NEW: Find entities in file
    INDEX idx_object (object_id),  -- NEW: Find entities in object
    INDEX idx_email (email_id),    -- NEW: Find entities in email
    
    FOREIGN KEY (entity_id) REFERENCES oc_openregister_entities(id) ON DELETE CASCADE,
    FOREIGN KEY (chunk_id) REFERENCES oc_openregister_chunks(id) ON DELETE CASCADE
);
```

## Relationship Pattern

### Parent-Child (BelongsTo)

```
Person: John Doe (id=1)
  ├─ Phone: +31612345678 (id=2, belongs_to_entity_id=1)
  ├─ Phone: +31687654321 (id=3, belongs_to_entity_id=1)
  ├─ Email: john.doe@example.com (id=4, belongs_to_entity_id=1)
  ├─ Email: j.doe@company.com (id=5, belongs_to_entity_id=1)
  └─ Address: 123 Main St, Amsterdam (id=6, belongs_to_entity_id=1)

Organization: Acme Corp (id=10)
  ├─ Phone: +31201234567 (id=11, belongs_to_entity_id=10)
  ├─ Email: info@acme.com (id=12, belongs_to_entity_id=10)
  ├─ Email: sales@acme.com (id=13, belongs_to_entity_id=10)
  └─ Address: 456 Business Park (id=14, belongs_to_entity_id=10)
```

### Query Patterns

**Get all contact info for a person**:
```sql
SELECT * FROM oc_openregister_entities 
WHERE belongs_to_entity_id = 1;
```

**Get parent entity for a phone number**:
```sql
SELECT parent.* FROM oc_openregister_entities phone
JOIN oc_openregister_entities parent ON phone.belongs_to_entity_id = parent.id
WHERE phone.id = 2;
```

**Find all persons with a specific email domain**:
```sql
SELECT DISTINCT parent.* FROM oc_openregister_entities email
JOIN oc_openregister_entities parent ON email.belongs_to_entity_id = parent.id
WHERE email.type = 'email' 
  AND email.value LIKE '%@acme.com'
  AND parent.type = 'person';
```

## Role-Based Anonymization

### Example Detection

```
Text: "Contact John Doe (Sales Manager) at +31612345678"

Entities Detected:
1. Person: John Doe (id=1)
2. Phone: +31612345678 (id=2, belongs_to_entity_id=1)

EntityRelation Records:
1. entity_id=1, chunk_id=123, role='employee', position_start=8, position_end=16
2. entity_id=2, chunk_id=123, role='employee', position_start=37, position_end=50

Anonymization Decision:
- John Doe: role='employee' → May not require anonymization (business context)
- Phone: role='employee' → May not require anonymization (business contact)
```

### Example: Private Individual

```
Text: "Please forward this to Jane Smith (customer) at jane.smith@gmail.com"

Entities Detected:
1. Person: Jane Smith (id=20)
2. Email: jane.smith@gmail.com (id=21, belongs_to_entity_id=20)

EntityRelation Records:
1. entity_id=20, chunk_id=456, role='customer', position_start=23, position_end=33
2. entity_id=21, chunk_id=456, role='customer', position_start=48, position_end=69

Anonymization Decision:
- Jane Smith: role='customer' → Requires anonymization (private individual)
- Email: role='customer' → Requires anonymization (personal email)
```

## Source Tracking Benefits

### Scenario: File Re-chunking

```
Initial State:
- File: contract.pdf (file_id=100)
- FileText: id=200 (extraction record)
- Chunks: Created from file_id=100
- Entities found in chunks

After Re-chunking:
- File: contract.pdf (file_id=100) - UNCHANGED
- FileText: id=200 - Updated with new chunks
- Chunks: NEW chunks created
- EntityRelations: Still reference file_id=100 (original source)

Benefit: Can always trace entities back to original file, even after re-chunking
```

### Scenario: GDPR Request

```
Request: 'Give me all documents containing John Doe'

Query:
SELECT DISTINCT 
    er.file_id,
    er.object_id,
    er.email_id,
    er.role,
    er.created_at
FROM oc_openregister_entities e
JOIN oc_openregister_entity_relations er ON e.id = er.entity_id
WHERE e.value = 'John Doe' AND e.type = 'person'

Result:
file_id | object_id | email_id | role              | created_at
--------|-----------|----------|-------------------|-------------------
100     | NULL      | NULL     | employee          | 2024-01-15 10:30
NULL    | 500       | NULL     | mentioned         | 2024-02-20 14:15
NULL    | NULL      | 300      | recipient         | 2024-03-10 09:00
150     | NULL      | NULL     | private_individual| 2024-04-05 16:45

Interpretation:
- contract.pdf (file 100): John Doe as employee
- Customer record (object 500): John Doe mentioned
- Email thread (email 300): John Doe as recipient
- Personal letter (file 150): John Doe as private individual (requires anonymization)
```

## PHP Entity Class Updates

### GdprEntity Class

```php
class GdprEntity extends Entity implements JsonSerializable
{
    protected ?int $belongsToEntityId = null;  // NEW
    
    /**
     * Get all child entities (contact info) for this entity
     *
     * @param EntityMapper $mapper
     * @return GdprEntity[]
     */
    public function getChildren(EntityMapper $mapper): array
    {
        return $mapper->findByBelongsTo($this->id);
    }
    
    /**
     * Get the parent entity this belongs to
     *
     * @param EntityMapper $mapper
     * @return GdprEntity|null
     */
    public function getParent(EntityMapper $mapper): ?GdprEntity
    {
        if ($this->belongsToEntityId === null) {
            return null;
        }
        return $mapper->find($this->belongsToEntityId);
    }
}
```

### EntityRelation Class

```php
class EntityRelation extends Entity implements JsonSerializable
{
    protected ?string $role = null;     // NEW
    protected ?int $fileId = null;      // NEW
    protected ?int $objectId = null;    // NEW
    protected ?int $emailId = null;     // NEW
    
    // Role constants
    public const ROLE_PUBLIC_FIGURE = 'public_figure';
    public const ROLE_EMPLOYEE = 'employee';
    public const ROLE_PRIVATE_INDIVIDUAL = 'private_individual';
    public const ROLE_CUSTOMER = 'customer';
    
    /**
     * Check if this entity occurrence requires anonymization
     *
     * @return bool
     */
    public function requiresAnonymization(): bool
    {
        $nonPrivateRoles = [
            self::ROLE_PUBLIC_FIGURE,
            self::ROLE_EMPLOYEE,
        ];
        
        if ($this->role && in_array($this->role, $nonPrivateRoles)) {
            return false;
        }
        
        return true; // Default: require anonymization
    }
    
    /**
     * Get source type and ID
     *
     * @return array{type: string, id: int}|null
     */
    public function getSource(): ?array
    {
        if ($this->fileId) return ['type' => 'file', 'id' => $this->fileId];
        if ($this->objectId) return ['type' => 'object', 'id' => $this->objectId];
        if ($this->emailId) return ['type' => 'email', 'id' => $this->emailId];
        return null;
    }
}
```

## Migration from EntityLink Table

### Current State (Before)

```
EntityLink table with many-to-many relationships:
- source_entity_id
- target_entity_id
- relationship_type ('belongs_to', 'associated_with', etc.)
```

### New State (After)

```
Entity table with belongs_to_entity_id:
- Direct foreign key to parent entity
- Simpler queries
- Better performance
- More intuitive data model
```

### Migration Strategy

```sql
-- Migrate EntityLink 'belongs_to' relationships to Entity.belongs_to_entity_id
UPDATE oc_openregister_entities e
JOIN oc_openregister_entity_links el ON e.id = el.source_entity_id
SET e.belongs_to_entity_id = el.target_entity_id
WHERE el.relationship_type = 'belongs_to';

-- Drop EntityLink table (if no other relationship types needed)
DROP TABLE oc_openregister_entity_links;
```

## Benefits Summary

### 1. Simpler Data Model
- ✅ Direct foreign key instead of join table for belongs_to
- ✅ One query instead of two to get contact info
- ✅ Intuitive parent-child structure

### 2. GDPR Compliance
- ✅ Role-based anonymization decisions
- ✅ Context-aware entity handling
- ✅ Public figures vs private individuals

### 3. Robust Source Tracking
- ✅ Always trace back to original document
- ✅ Survives re-chunking operations
- ✅ Complete audit trail

### 4. Query Performance
- ✅ Indexed belongs_to lookups
- ✅ Indexed role-based queries
- ✅ Indexed source-based queries

### 5. Flexibility
- ✅ Still supports complex relationships via EntityLink if needed
- ✅ Role field extensible for future needs
- ✅ Multiple source types supported

## Example Use Cases

### Use Case 1: Complete Contact Profile

```php
// Find person
$person = $entityMapper->findByValue('John Doe', GdprEntity::TYPE_PERSON);

// Get all contact info (one query)
$contactInfo = $entityMapper->findByBelongsTo($person->getId());

foreach ($contactInfo as $info) {
    echo "{$info->getType()}: {$info->getValue()}\n";
}

// Output:
// phone: +31612345678
// phone: +31687654321
// email: john.doe@example.com
// email: j.doe@company.com
// address: 123 Main St, Amsterdam
```

### Use Case 2: Role-Based Anonymization

```php
// Find all entity occurrences requiring anonymization
$relations = $entityRelationMapper->findAll();

foreach ($relations as $relation) {
    if ($relation->requiresAnonymization()) {
        $entity = $entityMapper->find($relation->getEntityId());
        
        // Anonymize this occurrence
        $anonymizedValue = $this->anonymize($entity->getType(), $entity->getId());
        $relation->setAnonymized(true);
        $relation->setAnonymizedValue($anonymizedValue);
        $entityRelationMapper->update($relation);
    }
}
```

### Use Case 3: Source Document Retrieval

```php
// Find all files containing a specific entity
$entity = $entityMapper->findByValue('john.doe@example.com', GdprEntity::TYPE_EMAIL);
$relations = $entityRelationMapper->findByEntityId($entity->getId());

$files = [];
$objects = [];
$emails = [];

foreach ($relations as $relation) {
    if ($relation->getFileId()) {
        $files[] = $relation->getFileId();
    }
    if ($relation->getObjectId()) {
        $objects[] = $relation->getObjectId();
    }
    if ($relation->getEmailId()) {
        $emails[] = $relation->getEmailId();
    }
}

// Now retrieve actual documents
$fileEntities = $fileMapper->findByIds(array_unique($files));
$objectEntities = $objectMapper->findByIds(array_unique($objects));
$emailEntities = $emailMapper->findByIds(array_unique($emails));
```

## Conclusion

The updated entity relationship model provides:
- **Simpler structure**: Direct parent-child relationships via `belongs_to_entity_id`
- **GDPR compliance**: Role-based anonymization decisions
- **Robust tracking**: Ultimate source document references
- **Better performance**: Indexed queries for common patterns
- **Flexibility**: Extensible for future requirements

This model is ready for implementation and addresses all the requirements for efficient GDPR entity management in the OpenRegister text extraction system.



