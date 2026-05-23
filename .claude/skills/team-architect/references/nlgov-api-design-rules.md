# NLGov REST API Design Rules 2.0

Reference for API design review in `team-architect`. Since 2020, these are on the "pas toe of leg uit" (comply or explain) list. All government REST APIs MUST follow them.

---

## NLGov Mandatory Rules

- API version in URL or header
- Use JSON as default format
- Use standard HTTP methods (GET, POST, PUT, PATCH, DELETE)
- Use standard HTTP status codes
- Support content negotiation via `Accept` header
- Pagination for all collection endpoints
- Filtering, sorting, and field selection via query parameters
- HATEOAS `_links` in responses for discoverability
- Standard error response format with `type`, `title`, `status`, `detail`, `instance`

---

## URL Pattern Standards

```
GET    /index.php/apps/{app}/api/{resource}           → list
GET    /index.php/apps/{app}/api/{resource}/{id}       → show
POST   /index.php/apps/{app}/api/{resource}            → create
PUT    /index.php/apps/{app}/api/{resource}/{id}       → update
DELETE /index.php/apps/{app}/api/{resource}/{id}       → delete
```

Nested resources for OpenRegister:
```
/api/objects/{register}/{schema}                        → list objects
/api/objects/{register}/{schema}/{id}                   → single object
/api/registers/{id}/oas                                 → OpenAPI spec
```

Check for:
- [ ] RESTful URL patterns (nouns, not verbs)
- [ ] Consistent resource naming (plural)
- [ ] Route ordering: specific routes BEFORE wildcard `{slug}` routes (Symfony router requirement)
- [ ] No business logic in route definitions

---

## CORS & Security Annotations

```php
/**
 * @NoAdminRequired
 * @NoCSRFRequired
 * @CORS
 */
public function publicEndpoint(): JSONResponse
```

Check for:
- [ ] Public endpoints have `@CORS`, `@NoCSRFRequired`, `@NoAdminRequired`
- [ ] OPTIONS routes registered for CORS preflight
- [ ] Internal endpoints do NOT have `@CORS`
- [ ] Admin-only endpoints omit `@NoAdminRequired`

---

## Error Response Consistency

```php
// Standard error response pattern
return new JSONResponse(
    data: ['message' => $e->getMessage()],
    statusCode: Http::STATUS_NOT_FOUND  // 404
);

// Validation error with details
return new JSONResponse(
    data: ['message' => $e->getMessage(), 'errors' => $e->getErrors()],
    statusCode: Http::STATUS_BAD_REQUEST  // 400
);
```

Exception → HTTP status mapping:
| Exception | Status Code |
|-----------|------------|
| NotFoundException | 404 |
| ValidationException | 400 |
| NotAuthorizedException | 403 |
| LockedException | 423 |
| Generic Exception | 500 |
