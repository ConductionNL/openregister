# Audit Trails

Audit trails provide a complete history of all changes made to objects in Open Register. This feature ensures transparency and accountability by tracking who made what changes and when. The implementation follows the [GEMMA Processing Logging standard](https://vng-realisatie.github.io/gemma-verwerkingenlogging/gegevensmodel/basisterminologie.html).

## Overview

The audit trail system automatically records:
- All modifications to objects
- Individual object reads (but not collection reads)
- Who made the changes
- When changes occurred
- What specific data was changed
- The reason for changes (when provided)
- Processing activities

## Read Action Logging

The system only logs read actions when accessing individual objects (e.g., GET /api/objects/123). Collection reads and search operations (e.g., GET /api/objects?name=test) are intentionally not logged for several important reasons:

1. **Performance Impact**
   - Collection reads can return hundreds or thousands of objects
   - Logging each object view would create massive amounts of audit data
   - Database performance would degrade significantly

2. **Storage Concerns**
   - The audit log would grow exponentially
   - Storage costs would increase dramatically
   - Valuable audit data would be diluted with less meaningful entries

3. **Meaningful Tracking**
   - Individual object reads indicate specific interest in that object
   - Collection reads are often exploratory or part of routine operations
   - Focus on logging deliberate access to specific objects

## Logged Information

| Field | Description | Example | How Determined |
|-------|-------------|---------|----------------|
| uuid | Unique identifier for the audit entry | 550e8400-e29b-41d4-a716-446655440000 | Generated using UUID v4 |
| schema | Schema ID of the modified object | 42 | From object's schema reference |
| register | Register ID of the modified object | 123 | From object's register reference |
| object | Object ID that was modified | 456 | From modified object's ID |
| action | Type of change that occurred | create, read, update, delete | Determined by system operation |
| changed | Array of modified fields with old/new values | `{"name": {"old": "John", "new": "Jane"}}` | Diff between object states |
| user | ID of the user who made the change | admin | From authenticated user session |
| userName | Display name of the user | Administrator | From user profile |
| session | Session ID when change occurred | sess_89d7h2 | From current session |
| request | Request ID for tracing | req_7d8h3j | Generated per request |
| ipAddress | IP address of the request | 192.168.1.1 | From request headers |
| version | Object version after change | 1.0.0 | Incremented on each change |
| created | Timestamp of the change | 2024-03-15T14:30:00Z | Server timestamp |

### Additional Required Fields

To enhance audit logging, we should add:

| Field | Description | How Determined |
|-------|-------------|----------------|
| processingActivity | The processing activity from the registry | From process registry lookup |
| processing | The specific task being performed | From application context |
| operation | The step in the processing task | From system operation |
| legalBasis | Legal basis for the processing | From process configuration |
| retentionPeriod | Retention period for the data | From schema configuration |
| executor | The system or person executing the action | From authenticated context |
| system | The system where the action occurred | From application config |
| dataSource | The source of the data | From data origin tracking |

## Processing Logging Structure

The audit trail system follows this hierarchical structure:

1. **Processing Activity**
   - High-level category of data processing
   - Determined by: Process registry lookup
   - Example: "Citizen Registration"

2. **Processing**
   - Specific task being executed
   - Determined by: Application context
   - Example: "Address Change Request"

3. **Operation**
   - Individual step in processing
   - Determined by: System operation
   - Example: "Validate Address"

4. **Action**
   - Actual system operation
   - Determined by: Database operation
   - Example: "Update Address Field"

## Key Benefits

1. **Compliance & Accountability**
   - Meet regulatory requirements
   - Track responsibility for changes
   - Maintain data integrity records

2. **Change Management**
   - Review modification history
   - Understand data evolution
   - Investigate data issues

3. **Security**
   - Detect unauthorized changes
   - Monitor sensitive data access
   - Support security audits

## Integration

Audit trails are automatically enabled for all objects in Open Register. No additional configuration is required to start tracking changes.

## Access

Audit trails can be accessed:
- Through the object detail view
- Via the API
- Through database queries (for system administrators)

## Related Features

- [Time Travel](time-travel.md) - Use audit trails to restore previous states
- [Object Locking](object-locking.md) - Prevent unauthorized changes
- [Access Control](access-control.md) - Manage who can view audit trails 