---
title: Entity Relationships Correction
sidebar_position: 123
---

# Entity Relationships Correction Summary

## Issue Identified

The original documentation incorrectly included person-to-person and person-to-organization relationships (family, employment, reporting structure), which are not part of the requirements.

## Correct Scope

**EntityLink is ONLY for contact information ownership**:

### ✅ Correct Relationships

**Contact Info → Person/Organization**:
- Phone(+31612345678) → belongs_to → Person(John Doe)
- Email(info@acme.com) → belongs_to → Organization(Acme Corp)
- Address(123 Main St) → belongs_to → Person(John Doe)
- Phone(+31201234567) → belongs_to → Organization(Acme Corp)

### ❌ Incorrect Relationships (Removed)

**Person → Person** (NOT supported):
- ~~Person → related_to → Person (family)~~
- ~~Person → reports_to → Person (manager)~~

**Person → Organization** (NOT supported):
- ~~Person → works_for → Organization~~
- ~~Person → manages → Organization~~

**Organization → Organization** (NOT supported):
- ~~Organization → part_of → Organization~~

## Reasoning

1. **GDPR Focus**: The primary purpose is tracking which contact information belongs to which data subject
2. **Attribute-like Relationships**: Phone, email, and address are attributes that would normally be properties on a Person or Organization entity
3. **Data Structure**: Employment and family relationships should be handled through the object data model, not the entity link system

## Updated Relationship Types

### belongs_to (Primary)
- Links contact info to person or organization
- Source: Phone/Email/Address entity
- Target: Person/Organization entity

### associated_with
- Generic association for uncertain relationships
- Example: Shared mailbox to organization

### primary_contact / alternate_contact
- Marks contact priority
- Helps identify which contact method is preferred

## Documentation Files Corrected

1. ✅ **[Text Extraction Database Entities](./text-extraction.md#database-schema)**
   - Removed person-to-person relationships
   - Removed person-to-organization relationships
   - Updated relationship types constants
   - Updated use case examples
   - Updated knowledge graph queries

2. ✅ **[Enhanced Text Extraction](../features/text-extraction-enhanced.md)**
   - Updated relationship types
   - Updated example relationships
   - Updated GDPR use case output

3. ✅ **[Entity Relationships Addition](./entity-relationships-addition.md)**
   - Updated relationship types section
   - Updated use case examples
   - Updated entity graph example
   - Updated detection example

## Benefits of Correct Scope

### 1. Simpler Implementation
- Focus on contact info detection
- Clear ownership model
- No complex social graph logic

### 2. Better GDPR Compliance
- Complete contact info inventory per person
- All phone numbers/emails/addresses tracked
- Clear data subject access reports

### 3. Cleaner Data Model
- Contact info as entity attributes
- Employment/family in object data
- No overlap or confusion

### 4. Easier Anonymization
- Anonymize all contact info for a person
- Update all entity links
- Maintain data structure

## Example: Corrected GDPR Profile

```
Data Subject Request: John Doe

Contact Information Owned:
- Phone: +31612345678 (primary)
- Phone: +31687654321 (alternate)
- Email: john.doe@example.com (primary)
- Email: j.doe@company.com (alternate)
- Address: 123 Main St, Amsterdam

Found in Documents:
- contract-2024.pdf (5 mentions)
- email-thread.eml (12 mentions)
- meeting-notes.docx (3 mentions)

Total: 5 phone numbers, emails, addresses linked to this person
```

**Not included** (handled elsewhere):
- Employment information
- Family relationships
- Organizational hierarchy

## Implementation Impact

### Database Schema
- ✅ No changes needed to table structure
- ✅ Relationship type constants updated
- ✅ Simpler than originally designed

### Detection Logic
- ✅ Focus on proximity detection (contact info near person/org name)
- ✅ Pattern matching for contact blocks
- ✅ LLM for context-aware detection
- ❌ Removed: Employment pattern detection
- ❌ Removed: Family relationship detection

### API Endpoints
- ✅ All endpoints still valid
- ✅ Simplified use cases
- ✅ Clearer purpose

## Migration from Incorrect Documentation

If any code was written based on the incorrect documentation:

1. **Remove** relationship types: `works_for`, `part_of`, `related_to`, `reports_to`, `manages`, `owned_by`
2. **Keep** relationship types: `belongs_to`, `associated_with`, `primary_contact`, `alternate_contact`
3. **Update** detection logic to focus on contact info proximity
4. **Simplify** use cases to contact info ownership only

## Correct Use Cases

### 1. Complete Contact Profile
Find all contact methods for a person:
```
Person(John Doe) has:
- 2 phone numbers
- 3 email addresses
- 1 physical address
```

### 2. Entity Deduplication
Merge persons based on shared contact info:
```
Person(John Doe) and Person(J. Doe) share:
- Same phone: +31612345678
- Same email: john.doe@example.com
→ Likely same person, merge entities
```

### 3. Anonymization Planning
Before anonymizing, gather all contact info:
```
To anonymize Person(John Doe):
- Find all linked phones
- Find all linked emails
- Find all linked addresses
- Anonymize all together
```

### 4. Organization Contact Directory
Get all contact methods for an organization:
```
Organization(Acme Corp) has:
- 2 phone numbers (main + support)
- 5 email addresses (info, sales, support, etc.)
- 2 addresses (HQ + warehouse)
```

## Questions Answered

**Q: Can we track employment relationships?**
A: Not through EntityLink. Use object data (e.g., Employee object with organization property).

**Q: Can we track family relationships?**
A: Not through EntityLink. Use object data if needed (e.g., Relationship object).

**Q: Can we track organizational hierarchy?**
A: Not through EntityLink. Use object data (e.g., Organization object with parent property).

**Q: What IS EntityLink for?**
A: Tracking which phone numbers, email addresses, and physical addresses belong to which persons or organizations.

## Conclusion

The corrected documentation now accurately reflects that **EntityLink is specifically for tracking contact information ownership**, not for social/organizational graphs. This makes the system:

- ✅ Simpler to implement
- ✅ More focused on GDPR use case
- ✅ Cleaner data model
- ✅ Easier to understand and maintain

All documentation has been updated to reflect this correct scope.

---

**Status**: ✅ Documentation corrected and consistent across all files

