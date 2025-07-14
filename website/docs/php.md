## API Testing

### CRITICAL: Local Development API Testing Requirements

For local development environments, API calls **MUST** be made from within the Nextcloud Docker container. External calls will fail due to authentication and routing issues.

#### Common Mistakes to Avoid

1. **❌ DO NOT** make API calls from the host machine to `http://localhost` or `http://nextcloud.local`
   - These will result in 401 Unauthorized errors
   - Authentication cookies and sessions don't work properly from external calls

2. **❌ DO NOT** use standalone PHP server for API testing
   - `php -S localhost:8000` lacks the Nextcloud framework and routing system
   - API routes will return 404 errors
   - Dependency injection and service container won't work

3. **❌ DO NOT** forget authentication headers
   - Always include `-u 'admin:admin'` for basic auth
   - Always include `-H 'OCS-APIREQUEST: true'` header

### Handling Empty Values in API Requests

The OpenRegister API intelligently handles empty values based on schema requirements to prevent cascading errors in related apps while maintaining data integrity.

#### Empty Object Properties

For object properties (type: 'object'):

**✅ Non-required object properties:**
```json
{
  "contactgegevens": {}    // Converted to null automatically
}
// Result: "contactgegevens": null
```

**⚠️ Required object properties:**
```json
{
  "requiredObject": {}     // Kept as {} but will fail validation
}
// Result: Validation error with clear message
```

#### Empty Array Properties

For array properties (type: 'array'):

**✅ Arrays with no minItems constraint:**
```json
{
  "links": []              // Preserved as valid empty array
}
// Result: "links": []
```

**⚠️ Arrays with minItems > 0:**
```json
{
  "requiredItems": []      // Kept as [] but will fail validation
}
// Result: Validation error: "Property 'requiredItems' should have at least 1 items, but has 0"
```

#### Empty String Properties

For string properties (type: 'string'):

**✅ Non-required string properties:**
```json
{
  "optionalField": ""      // Converted to null automatically
}
// Result: "optionalField": null
```

**⚠️ Required string properties:**
```json
{
  "requiredField": ""      // Kept as "" but will fail validation
}
// Result: Validation error with guidance
```

#### Explicit Null Values

Explicit null values are always preserved for clearing fields:

```json
{
  "fieldToClear": null     // Always preserved
}
// Result: "fieldToClear": null
```

#### Best Practices for API Clients

1. **Use explicit null values** when you want to clear a field:
   ```json
   { "contactgegevens": null }  // Clear the field
   ```

2. **Omit properties entirely** if you don't want to change them:
   ```json
   { "naam": "Updated Name" }   // Only update name, leave other fields unchanged
   ```

3. **Provide valid data** for required fields:
   ```json
   { 
     "naam": "Organization Name",           // Required string
     "website": "https://example.com",     // Required string
     "contactgegevens": {                  // Required object with data
       "email": "contact@example.com"
     }
   }
   ```

4. **Handle validation errors** properly by checking the error message:
   ```json
   {
     "status": "error",
     "message": "Validation failed",
     "errors": [{
       "property": "naam",
       "message": "The required property 'naam' is missing. Please provide a value for this property or set it to null if allowed."
     }]
   }
   ```

### Proper API Testing Methods

#### 1. REQUIRED: Test from within the Docker Container
Execute curl commands from inside the Nextcloud Docker container:

**Step 1: Find your Nextcloud container name**
```bash
# List running containers to find Nextcloud container
docker ps | grep nextcloud
```

**Step 2: Test API from within container (REQUIRED for local development)**
```bash
# Execute curl command in the container (replace 'master-nextcloud-1' with your container name)
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' 'http://localhost/index.php/apps/openregister/api/search-trails?limit=50&page=1'"

# For statistics endpoint specifically
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'OCS-APIREQUEST: true' 'http://localhost/index.php/apps/openregister/api/search-trails/statistics'"

# Or get a shell in the container for interactive testing
docker exec -it -u 33 master-nextcloud-1 /bin/bash
```

**Important Notes:**
- Use `-u 33` flag to run as the correct user (www-data)
- Include authentication with `-u 'admin:admin'` or your Nextcloud credentials
- Add `OCS-APIREQUEST: true` header for proper API handling
- Use single quotes to avoid shell interpretation of special characters

#### 2. Alternative: Use Browser Developer Tools
For testing authenticated endpoints in the browser:
1. Open Browser Developer Tools (F12)
2. Go to Network tab
3. Access the page that calls the API
4. Look for API requests in the network log
5. Right-click on failed requests and select 'Copy as cURL'
6. Use the copied cURL command for testing

#### 3. Required Headers for API Testing
Always include these headers when testing:
```bash
# Test with authentication headers (REQUIRED)
curl -u 'admin:admin' \
     -H 'OCS-APIREQUEST: true' \
     -H 'Content-Type: application/json' \
     'http://localhost/index.php/apps/openregister/api/search-trails/statistics'
```

#### 4. External API Testing (Production/Staging Only)
Only use external calls for production or staging environments:
```bash
# For production/staging environments only
curl -H 'Authorization: Bearer your-token' \
     -H 'Content-Type: application/json' \
     'https://your-domain.com/index.php/apps/openregister/api/search-trails/statistics'
```

### Debugging API Endpoint Issues

#### 1. Check App Status
Ensure the app is enabled in Nextcloud:
```bash
# Check if app is enabled (replace 'master-nextcloud-1' with your container name)
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:list | grep openregister

# Enable the app if needed
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable openregister

# Verify app is enabled (should show 'openregister already enabled')
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable openregister
```

#### 2. Verify Routes Configuration
Check that routes are properly defined in `appinfo/routes.php`:
```php
// Ensure routes are properly defined
['name' => 'controller#method', 'url' => '/api/endpoint', 'verb' => 'GET'],
```

#### 3. Check Controller Methods
Verify that controller methods have proper annotations:
```php
/**
 * @NoAdminRequired
 * @NoCSRFRequired
 */
public function statistics(): JSONResponse
{
    // Method implementation
}
```

#### 4. Monitor Nextcloud Logs
Check Nextcloud logs for API errors:
```bash
# View live logs (replace 'master-nextcloud-1' with your container name)
docker exec -u 33 master-nextcloud-1 tail -f /var/www/html/data/nextcloud.log

# Check recent errors
docker exec -u 33 master-nextcloud-1 grep -i error /var/www/html/data/nextcloud.log | tail -10
```

#### 5. Test Database Connectivity
Verify database queries work properly:
```bash
# Test database connection in container (replace 'master-nextcloud-1' with your container name)
docker exec -u 33 master-nextcloud-1 php -r "
\$config = include '/var/www/html/config/config.php';
\$pdo = new PDO('mysql:host=' . \$config['dbhost'] . ';dbname=' . \$config['dbname'], \$config['dbuser'], \$config['dbpassword']);
var_dump(\$pdo->query('SELECT COUNT(*) FROM oc_search_trails')->fetchColumn());
"
```