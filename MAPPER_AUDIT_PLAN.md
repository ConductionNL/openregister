# 🔍 Mapper Architecture Audit Plan

## Core Principle
**Mappers (database layer) should NEVER depend on Services or Handlers (business logic layer)**

## Dependency Flow (Correct)
```
Controllers
    ↓
Services
    ↓
Handlers
    ↓
Mappers (database access)
```

## What Mappers CAN Inject
✅ `IDBConnection` - Database connection
✅ `IEventDispatcher` - Event system
✅ `LoggerInterface` - Logging
✅ `ITimeFactory` - Time utilities
✅ Other Mappers (with caution - avoid circular dependencies)

## What Mappers CANNOT Inject
❌ Any class ending in `Service`
❌ Any class ending in `Handler`
❌ Business logic classes

## Audit Checklist
For each mapper in `lib/Db/`:
1. ✅ Read constructor parameters
2. ✅ Check for Service injections
3. ✅ Check for Handler injections
4. ✅ Remove violations
5. ✅ Update Application.php registrations

## Impact
- Prevents circular dependencies
- Maintains clean architecture
- Separates concerns properly
- Makes testing easier

