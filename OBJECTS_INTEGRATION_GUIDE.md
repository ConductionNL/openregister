# Objects Handler Integration Guide

**Phase 2: Integration Strategy**

---

## Overview

This guide documents how to integrate the 8 new handlers into `ObjectService` and `ObjectsController`.

---

## Integration Strategy

### Step 1: Update ObjectService (Coordinator Pattern)

**Current:** 5,305 lines with embedded logic  
**Target:** ~800 lines delegating to handlers

#### Changes Required:

1. **Constructor**: Inject all 8 handlers
2. **Delegation**: Replace inline logic with handler calls
3. **Keep**: Coordination logic, register/schema management, transaction handling

#### Example Pattern:

**Before:**
```php
public function publish($uuid, $date = null) {
    // 50 lines of inline logic
    $object = $this->find($uuid);
    $object->setPublicationDate($date ?? new DateTime());
    return $this->update($object);
}
```

**After:**
```php
public function publish($uuid, $date = null) {
    return $this->publishHandler->publish($uuid, $date);
}
```

---

### Step 2: Update ObjectsController (Thin Controller Pattern)

**Current:** 2,084 lines with business logic  
**Target:** ~500 lines handling HTTP only

#### Changes Required:

1. **Keep**: HTTP request/response handling, parameter extraction
2. **Delegate**: All business logic to ObjectService/handlers
3. **Remove**: Inline business logic

#### Example Pattern:

**Before:**
```php
public function lock($id) {
    $data = $this->request->getParams();
    $process = $data['process'] ?? null;
    // More HTTP + business logic mixed
    $object = $this->objectEntityMapper->lockObject($id, $process);
    return new JSONResponse($object);
}
```

**After:**
```php
public function lock($id) {
    $data = $this->request->getParams();
    $object = $this->objectService->lockObject(
        $id, 
        $data['process'] ?? null,
        (int) ($data['duration'] ?? null)
    );
    return new JSONResponse($object);
}
```

---

## Handler Mapping

### Methods to Delegate

| Controller Method | Handler | Handler Method |
|-------------------|---------|----------------|
| `lock()` | LockHandler | `lock()` |
| `unlock()` | LockHandler | `unlock()` |
| `logs()` | AuditHandler | `getLogs()` |
| `publish()` | PublishHandler | `publish()` |
| `depublish()` | PublishHandler | `depublish()` |
| `contracts()` | RelationHandler | `getContracts()` |
| `uses()` | RelationHandler | `getUses()` |
| `used()` | RelationHandler | `getUsedBy()` |
| `merge()` | MergeHandler | `merge()` |
| `migrate()` | MergeHandler | `migrate()` |
| `export()` | ExportHandler | `export()` |
| `import()` | ExportHandler | `import()` |
| `downloadFiles()` | ExportHandler | `downloadObjectFiles()` |
| `vectorizeBatch()` | VectorizationHandler | `vectorizeBatch()` |
| `getObjectVectorizationStats()` | VectorizationHandler | `getStatistics()` |
| `getObjectVectorizationCount()` | VectorizationHandler | `getCount()` |
| `index()` | CrudHandler | `list()` |
| `objects()` | CrudHandler | `list()` |
| `show()` | CrudHandler | `get()` |
| `create()` | CrudHandler | `create()` |
| `update()` | CrudHandler | `update()` |
| `patch()` | CrudHandler | `patch()` |
| `destroy()` | CrudHandler | `delete()` |

---

## Implementation Plan

### Phase 2A: ObjectService Integration

1. **Add handler properties** to ObjectService
2. **Update constructor** to inject handlers
3. **Create delegation methods** for each handler operation
4. **Update existing methods** to use handlers where applicable

### Phase 2B: Controller Integration

1. **Simplify controller methods** to HTTP-only concerns
2. **Replace direct mapper calls** with ObjectService calls
3. **Remove inline business logic**
4. **Keep parameter extraction and response formatting**

### Phase 2C: Dependency Injection

1. **Update Application.php** to register all handlers
2. **Wire up dependencies** in DI container
3. **Ensure handlers are autowired** where possible

---

## Best Practices

### DO:
✅ Delegate all business logic to handlers  
✅ Keep controllers thin (HTTP concerns only)  
✅ Use ObjectService as coordinator  
✅ Log at handler level, not controller level  
✅ Return domain objects from handlers  
✅ Format responses in controller  

### DON'T:
❌ Put business logic in controllers  
❌ Call mappers directly from controllers  
❌ Duplicate logic across handlers  
❌ Skip error handling  
❌ Forget to update tests  

---

## Testing Strategy

### Unit Tests (Handlers)
- Test each handler method independently
- Mock dependencies (mappers, services)
- Verify logging calls
- Test error scenarios

### Integration Tests (ObjectService)
- Test handler coordination
- Verify transaction handling
- Test cross-handler interactions

### API Tests (Controller)
- Test HTTP endpoints
- Verify status codes
- Test authentication/authorization
- Validate response formats

---

## Rollout Strategy

### Option A: Big Bang (Not Recommended)
- Integrate all handlers at once
- High risk, hard to debug

### Option B: Incremental (Recommended)
1. Start with simple handlers (Lock, Audit)
2. Test thoroughly
3. Move to complex handlers (CRUD, Merge)
4. Validate each step

### Option C: Feature Flag (Safest)
- Add feature flags for new vs old code paths
- Gradually enable new handlers
- Easy rollback if issues found

---

## Rollback Plan

If issues arise:
1. **Handlers are additive** - old code still works
2. **Can revert controller changes** without affecting handlers
3. **Handlers can be disabled** individually
4. **Full rollback**: Revert ObjectService and Controller changes

---

## Success Metrics

**Code Quality:**
- ✅ ObjectService < 1,000 lines
- ✅ ObjectsController < 600 lines
- ✅ PHPQA passes
- ✅ All tests pass

**Performance:**
- ✅ No regression in API response times
- ✅ Memory usage stable or improved

**Maintainability:**
- ✅ Clear separation of concerns
- ✅ Easy to locate functionality
- ✅ New developers can understand code quickly

---

## Next Steps

1. Review this integration guide
2. Choose rollout strategy
3. Begin Phase 2A (ObjectService)
4. Test thoroughly
5. Continue to Phase 2B (Controller)
6. Final testing and validation

