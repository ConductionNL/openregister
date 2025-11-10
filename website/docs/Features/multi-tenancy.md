# Multi-Tenancy

OpenRegister's multi-tenancy system provides complete organisation-based data isolation, enabling multiple organizations to securely share the same application instance while maintaining strict data segregation.

## Overview

Multi-tenancy enables organizations to:

- **Maintain Complete Data Isolation**: Each organisation's data remains completely separate
- **Manage User Memberships**: Users can belong to multiple organisations with flexible switching
- **Automatic Entity Assignment**: All registers, schemas, and objects automatically assigned to active organisation
- **Session-Based Context**: Active organisation context maintained throughout user sessions
- **Enterprise-Grade Security**: Prevents cross-organisation data access and maintains audit trails

## Core Components

### Organisation Management
- **Organisation Creation**: Create and manage organisations with names, descriptions, and settings
- **User Membership**: Manage which users belong to which organisations
- **User Selection for Joining**: Optionally specify which user to add when joining an organisation (defaults to current user)
- **Default Organisation**: Automatic fallback organisation for users without specific memberships
- **Organisation Statistics**: Track usage, user counts, and system metrics

### Session Management
- **Active Organisation**: Users have one active organisation per session
- **Context Switching**: Seamless switching between organisations the user belongs to
- **Session Persistence**: Active organisation maintained across requests and sessions
- **Cache Management**: Performance-optimized with intelligent caching and cache clearing

### Automatic Entity Assignment
- **Registers**: Automatically assigned to user's active organisation when created
- **Schemas**: Inherit organisation context from active organisation
- **Objects**: Scoped to organisation context with automatic assignment
- **Cross-Organisation Prevention**: Prevents users from creating entities in organisations they don't belong to

## Key Features

### Enterprise Scalability
```mermaid
graph LR
    A[User] --> B[Multiple Organisations]
    B --> C[Active Context]
    C --> D[Entity Creation]
    D --> E[Organisation Assignment]
    E --> F[Data Isolation]
```

### Security Model
- **Access Control Integration**: Works seamlessly with existing RBAC system
- **Cross-Organisation Prevention**: Users cannot access data from other organisations
- **Audit Trails**: All actions include organisation context for compliance
- **Session Isolation**: User sessions isolated by organisation context

### Performance Optimizations
- **Database Query Filtering**: Efficient filtering at database level using joins
- **Session-Based Caching**: Active organisation cached in user sessions
- **Lazy Loading**: Organisation data loaded only when needed
- **Query Optimization**: Optimized queries prevent unnecessary cross-organisation data retrieval

## API Endpoints

### Organisation Management
- `GET /api/organisations` - List user's organisations with active organisation
- `POST /api/organisations` - Create new organisation
- `GET /api/organisations/{uuid}` - Get organisation details
- `PUT /api/organisations/{uuid}` - Update organisation
- `GET /api/organisations/search` - Search organisations

### Active Organisation
- `GET /api/organisations/active` - Get current active organisation
- `POST /api/organisations/{uuid}/set-active` - Set active organisation

### User-Organisation Relationships
- `POST /api/organisations/{uuid}/join` - Join organisation (with optional user selection)
- `POST /api/organisations/{uuid}/leave` - Leave organisation

### System Management
- `GET /api/organisations/stats` - System-wide statistics
- `POST /api/organisations/clear-cache` - Clear organisation cache

## Implementation Benefits

### For Organizations
- **Complete Data Isolation**: Guarantee that organizational data remains separate and secure
- **Flexible User Management**: Users can belong to multiple organisations as needed
- **Seamless Experience**: Transparent organisation switching without losing context
- **Enterprise Ready**: Built for enterprise-scale deployments with multiple tenant organizations

### For Administrators
- **Centralized Management**: Single application instance serving multiple organizations
- **Resource Efficiency**: Shared infrastructure with isolated data
- **Monitoring & Analytics**: Organization-scoped metrics and usage statistics
- **Maintenance Simplicity**: Single codebase serving all organizations

### For Developers
- **Automatic Implementation**: Entity assignment handled automatically
- **Session Integration**: Organisation context available throughout the application
- **Performance Optimized**: Efficient queries with proper database indexing
- **Testing Framework**: Comprehensive 113 test cases ensuring reliability

## Migration Support

The multi-tenancy system includes comprehensive migration support:

- **Default Organisation Creation**: Automatically creates default organisation if none exists
- **Legacy Data Assignment**: Assigns existing registers, schemas, and objects to default organisation
- **User Auto-Assignment**: Users without organisations automatically assigned to default
- **Backwards Compatibility**: Existing functionality continues working seamlessly

## Testing & Quality Assurance

### Comprehensive Test Suite
- **113 Test Scenarios**: Complete coverage of all multi-tenancy functionality
- **10 Specialized Test Files**: Organized by functional area for maintainability  
- **Unit Testing**: PHPUnit-based testing with mock dependencies
- **Integration Testing**: Full API testing with real database operations
- **Performance Testing**: Load testing for scalability verification
- **Security Testing**: Cross-organisation access prevention validation

### Quality Metrics
- **100% Feature Coverage**: All multi-tenancy features tested
- **Edge Case Handling**: Comprehensive error scenarios and boundary conditions
- **Security Validation**: Complete access control and data isolation verification
- **Performance Benchmarks**: Response time and scalability metrics

## Usage Examples

### Creating Organization-Scoped Entities

All entities automatically inherit the active organisation context. With hierarchies, child organisations can query and use parent resources:

```php
// Set active organisation (e.g., Gemeente Amsterdam with VNG as parent)
$organisationService->setActiveOrganisation('amsterdam-uuid');

// Register automatically assigned to active organisation
$register = $registerService->createFromArray([
    'title' => 'Customer Database',
    'description' => 'Customer records for Amsterdam'
]);
// organisation: 'amsterdam-uuid' (automatically set)

// Schema inherits organisation context  
$schema = $schemaService->createFromArray([
    'title' => 'Customer Schema',
    'version' => '1.0.0'
]);
// organisation: 'amsterdam-uuid' (automatically set)

// Agent created in Amsterdam context
$agent = $agentService->createFromArray([
    'name' => 'Amsterdam Support Agent',
    'model' => 'gpt-4'
]);
// organisation: 'amsterdam-uuid' (automatically set)

// Configuration for Amsterdam
$config = $configService->createFromArray([
    'name' => 'API Settings',
    'settings' => ['timeout' => 30]
]);
// organisation: 'amsterdam-uuid' (automatically set)
```

### Querying with Hierarchy

All queries automatically include parent organisations via `MultiTenancyTrait`:

```php
// User active in 'Noord' (parent chain: Noord → Amsterdam → VNG)
$organisationService->setActiveOrganisation('noord-uuid');

// Get all schemas visible to Noord
$schemas = $schemaMapper->findAll();
// SQL: WHERE organisation IN ('noord-uuid', 'amsterdam-uuid', 'vng-uuid')
// Returns schemas from: Noord + Amsterdam + VNG

// Get all agents available to Noord  
$agents = $agentMapper->findAll();
// Returns agents from all three organisations in hierarchy

// Create object using parent's schema
$object = $objectService->createFromArray([
    'schema' => 'vng-schema-uuid', // Schema from VNG (grandparent)
    'register' => 'amsterdam-register-uuid', // Register from Amsterdam (parent)
    'data' => ['name' => 'Test']
]);
// Works! Can use resources from any parent organisation
```

### Organisation Management

```php
// Get user's organisations with active context
$organisations = $organisationService->getUserOrganisations();

// Switch active organisation
$organisationService->setActiveOrganisation('uuid-of-new-org');

// Create new organisation with parent hierarchy
$newOrg = $organisationService->createFromArray([
    'name' => 'Deelgemeente Zuid',
    'description' => 'Sub-municipality of Amsterdam',
    'parent' => 'amsterdam-uuid' // Set parent relationship
]);
// Zuid will now see all Amsterdam AND VNG resources
```

## Best Practices

### For Implementation
1. **Always use Organisation Service**: Use the OrganisationService for all organisation operations
2. **Check Active Context**: Verify active organisation before entity creation
3. **Handle Membership**: Ensure users belong to organisations before setting as active
4. **Cache Appropriately**: Use session caching for performance, clear when necessary
5. **Test Cross-Organisation**: Always test cross-organisation access prevention

### For Security
1. **Validate Organisation Access**: Always check user belongs to organisation before operations
2. **Use Database Filtering**: Filter queries by organisation at database level
3. **Audit Organisation Context**: Include organisation in all audit logs
4. **Session Management**: Properly manage organisation context in sessions
5. **Regular Testing**: Run comprehensive security tests regularly

## Integration with Other Features

### RBAC Integration
Multi-tenancy works seamlessly with the existing Role-Based Access Control system:
- **Organisation-Scoped Permissions**: RBAC permissions apply within organisation context
- **Cross-Organisation Prevention**: RBAC prevents access to other organisations' data
- **Admin Override**: Admin users can access all organisations within their scope

### Search and Faceting
- **Organisation Filtering**: Search results automatically filtered by active organisation
- **Facet Scoping**: Facets calculated only for organisation's data
- **Performance Optimization**: Efficient organisation-aware search queries

### Audit Trails
- **Organisation Context**: All audit entries include organisation information
- **Compliance Support**: Organisation-scoped audit trails for regulatory compliance
- **Cross-Reference Prevention**: Cannot view audit trails from other organisations

## Organisation Hierarchies

OpenRegister supports parent-child organisation relationships, enabling controlled resource sharing across organisational boundaries.

### Concept

Child organisations automatically inherit visibility to resources from their parent organisations, recursively up the entire hierarchy. This is a unidirectional relationship - parents CANNOT view child resources, only children can view parent resources.

#### Use Case: Government Organisations

```mermaid
graph TD
    VNG[VNG - National Government] -->|parent of| AMS[Gemeente Amsterdam]
    VNG -->|parent of| RTM[Gemeente Rotterdam]  
    AMS -->|parent of| NOORD[Deelgemeente Noord]
    
    style VNG fill:#2196F3,color:#fff
    style AMS fill:#4CAF50,color:#fff
    style RTM fill:#4CAF50,color:#fff
    style NOORD fill:#8BC34A,color:#fff
```

**Resource Visibility**:
- VNG creates national schemas for all municipalities
- Gemeente Amsterdam automatically sees all VNG schemas
- Deelgemeente Noord automatically sees schemas from both Amsterdam AND VNG
- Amsterdam does NOT see Noord schemas (no downward visibility)
- Rotterdam does NOT see Amsterdam or Noord schemas (no sibling visibility)

### Key Features

**Upward Visibility (Children → Parents)**

Child organisations automatically see and can use ALL parent resources:

| Resource Type | Mapper | Visible to Children | Use Case |
|---------------|--------|---------------------|----------|
| **Schemas** | `SchemaMapper` | ✅ Yes | Data structure definitions |
| **Registers** | `RegisterMapper` | ✅ Yes | Object collections |
| **Objects** | `ObjectEntityMapper` | ✅ Yes | Individual data records |
| **Agents** | `AgentMapper` | ✅ Yes | AI assistants and processors |
| **Sources** | `SourceMapper` | ✅ Yes | External API connections |
| **Configurations** | `ConfigurationMapper` | ✅ Yes | System settings |
| **Applications** | `ApplicationMapper` | ✅ Yes | Integrated applications |
| **Views** | `ViewMapper` | ✅ Yes | UI display configurations |

Visibility applies **recursively** to entire ancestor chain (grandparents, great-grandparents, etc.).

**Isolation Rules**
- Parents CANNOT view child resources (security boundary)
- Siblings CANNOT view each other's resources (organisational isolation)
- Children can READ parent resources but cannot MODIFY them
- Maximum hierarchy depth: 10 levels (prevents infinite recursion)

**Circular Reference Prevention**
- Automatic validation prevents A → B → A cycles
- Enforced at API level when setting parent via `OrganisationMapper->validateParentAssignment()`
- Clear error messages guide administrators

### Technical Implementation

#### Database Schema

```sql
-- Parent column added to organisations table
ALTER TABLE oc_openregister_organisations 
ADD COLUMN parent VARCHAR(255) DEFAULT NULL;

-- Index for efficient parent chain queries
CREATE INDEX idx_organisation_parent 
ON oc_openregister_organisations(parent);
```

#### Recursive Parent Chain Query

The system uses recursive Common Table Expressions (CTE) for efficient parent chain lookups:

```sql
WITH RECURSIVE org_hierarchy AS (
    -- Base case: the organisation itself
    SELECT uuid, parent, 0 as level
    FROM oc_openregister_organisations
    WHERE uuid = :org_uuid
    
    UNION ALL
    
    -- Recursive case: get parent organisations
    SELECT o.uuid, o.parent, oh.level + 1
    FROM oc_openregister_organisations o
    INNER JOIN org_hierarchy oh ON o.uuid = oh.parent
    WHERE oh.level < 10  -- Max depth protection
)
SELECT uuid 
FROM org_hierarchy 
WHERE level > 0
ORDER BY level ASC;
```

#### Multi-Tenancy Filtering

Query filtering automatically includes parent organisations for ALL organisation-scoped entities:

```php
// Old behavior (pre-hierarchy)
WHERE organisation = '{active-org-uuid}'

// New behavior (with hierarchy)
WHERE organisation IN ('{active-uuid}', '{parent-uuid}', '{grandparent-uuid}')
```

**Entities with Automatic Hierarchy Support:**

The following entity mappers use `MultiTenancyTrait` and automatically inherit hierarchical multi-tenancy:

- **Agents** (`AgentMapper`) - AI agents are visible to child organisations
- **Schemas** (`SchemaMapper`) - Data schemas shared across hierarchy
- **Registers** (`RegisterMapper`) - Object collections visible to children
- **Views** (`ViewMapper`) - UI views available to child organisations
- **Sources** (`SourceMapper`) - Data sources accessible throughout hierarchy
- **Configurations** (`ConfigurationMapper`) - Configuration settings inherited by children
- **Applications** (`ApplicationMapper`) - Applications shared with child organisations
- **Objects** (`ObjectEntityMapper`) - Individual data objects follow organisation hierarchy

**How It Works:**

All these mappers call `applyOrganisationFilter()` from `MultiTenancyTrait`, which:

1. Calls `OrganisationService->getUserActiveOrganisations()`
2. Receives array: `['active-uuid', 'parent-uuid', 'grandparent-uuid']`
3. Applies SQL: `WHERE organisation IN (...)`
4. Returns results from active organisation AND all parents

**No Code Changes Required:**

Entity mappers require zero modifications - the trait handles everything automatically. This ensures consistent behaviour across all organisation-scoped entities.

### API Usage

**Set Parent Organisation:**

```bash
PUT /api/organisations/{uuid}
Content-Type: application/json

{
  'parent': '{parent-org-uuid}'
}
```

**Remove Parent:**

```bash
PUT /api/organisations/{uuid}
Content-Type: application/json

{
  'parent': null
}
```

**Get Organisation with Hierarchy:**

```bash
GET /api/organisations/{uuid}

Response:
{
  'uuid': 'amsterdam-uuid',
  'name': 'Gemeente Amsterdam',
  'parent': 'vng-uuid',
  'children': ['noord-uuid', 'zuid-uuid']
}
```

### Security Considerations

**Validation Rules**:
- Parent assignment validated before saving
- Circular references rejected (A → B → A)
- Maximum depth enforced (10 levels)
- Self-reference prevented (organisation cannot parent itself)

**Permission Model**:
- RBAC permissions apply within organisation context
- Parent resources are visible but respect RBAC rules
- Children cannot MODIFY parent resources (only read)
- Audit logs track which organisation owns each resource

**Error Responses**:
```json
{
  'error': 'Circular reference detected: The new parent organisation is already a descendant of this organisation.'
}

{
  'error': 'Maximum hierarchy depth exceeded. Total depth would be 11 levels (max 10 allowed).'
}
```

### Performance Optimizations

**Recursive CTE Benefits**:
- Single database query for entire parent chain
- No N+1 query problem
- Efficient even with deep hierarchies (10 levels)

**Benchmarks**:
- Parent chain lookup: < 10ms
- Filtered query with 3 organisations: < 50ms
- No measurable impact on single-org setups
- Index on parent column prevents full table scans

**Caching Strategy**:
- Parent chain computed per request
- Results cached in OrganisationService
- Cache invalidated on organisation updates

### Best Practices

1. **Keep Hierarchies Shallow**: Aim for ≤ 3 levels for clarity
2. **Clear Naming**: Use descriptive names showing hierarchy level
3. **Document Structure**: Maintain documentation of organisation tree
4. **Test Visibility**: Verify resource visibility in staging before production
5. **Monitor Performance**: Watch query times with deep hierarchies (> 5 levels)
6. **Plan Migrations**: Test parent assignments carefully to avoid disruption

### Example Scenarios

**Scenario 1: Schema Sharing (National to Municipal)**
```
VNG (parent) → Gemeente Amsterdam
- VNG creates 'Citizen Schema'
- Amsterdam automatically sees 'Citizen Schema'
- Amsterdam can create objects using VNG schema
- Amsterdam creates 'Local Events Schema'
- VNG does NOT see 'Local Events Schema'
```

**Scenario 2: Multi-Level Hierarchy (All Resources)**
```
VNG → Amsterdam → Noord

Resources visible to Noord:
✓ Schemas from VNG and Amsterdam
✓ Registers from VNG and Amsterdam
✓ Configurations from VNG and Amsterdam
✓ Agents from VNG and Amsterdam
✓ Sources from VNG and Amsterdam
✓ Views from VNG and Amsterdam
✓ Applications from VNG and Amsterdam
✓ Objects from VNG and Amsterdam

Resources Noord creates:
- Only visible to Noord itself
- NOT visible to Amsterdam or VNG (upward isolation)
```

**Scenario 3: Configuration Inheritance**
```
VNG (parent) → Multiple Municipalities
- VNG creates 'National API Configuration'
- All municipalities automatically use this configuration
- Municipality can override with local configuration
- Local config takes precedence over parent config (when both exist)
```

**Scenario 4: Sibling Isolation**
```
VNG → Amsterdam
VNG → Rotterdam
- Amsterdam and Rotterdam are siblings
- Amsterdam creates 'Amsterdam Transport Agent'
- Rotterdam does NOT see Amsterdam's agent
- Both see VNG's national agents
- Complete resource isolation between siblings
```

**Scenario 5: Agent and Source Sharing**
```
Company HQ → Department A → Team 1
- HQ creates 'Corporate AI Agent' and 'External API Source'
- Department A sees and can use HQ agent/source
- Team 1 sees agents/sources from both HQ and Department A
- Team 1 creates team-specific agent
- Only Team 1 can use their team agent
```

## Future Enhancements

Planned enhancements for the multi-tenancy system include:

- **Role-Based Organisation Access**: Organisation-specific role assignments
- **Organisation Templates**: Predefined organisation setups for quick deployment
- **Controlled Cross-Organisation Sharing**: Share specific resources across sibling organisations
- **Organisation Branding**: Custom branding and themes per organisation
- **Usage Analytics**: Detailed organisation usage metrics and reporting

---

## Technical Implementation

### Architecture Overview

Multi-tenancy in OpenRegister uses a combination of organisation-based filtering and Solr tenant collections:

```mermaid
graph TB
    subgraph "User Layer"
        User[User Session]
        ActiveOrg[Active Organisation]
    end
    
    subgraph "Application Layer"
        OrgService[Organisation Service]
        ObjectMapper[Object Mapper]
        SchemaMapper[Schema Mapper]
        RegisterMapper[Register Mapper]
    end
    
    subgraph "Data Layer"
        DB[(MySQL Database<br/>Organisation Field)]
        SolrBase[Solr Base Collection]
        SolrTenant1[Solr Tenant Collection<br/>org-uuid-1]
        SolrTenant2[Solr Tenant Collection<br/>org-uuid-2]
    end
    
    User -->|has| ActiveOrg
    ActiveOrg -->|managed by| OrgService
    
    ObjectMapper -->|filters by organisation| DB
    SchemaMapper -->|filters by organisation| DB
    RegisterMapper -->|filters by organisation| DB
    
    ObjectMapper -->|indexes to| SolrTenant1
    ObjectMapper -->|indexes to| SolrTenant2
    
    SolrBase -.->|creates| SolrTenant1
    SolrBase -.->|creates| SolrTenant2
    
    style ActiveOrg fill:#e1f5ff
    style OrgService fill:#fff4e1
```

### Tenant Isolation Strategy

OpenRegister implements multi-tenancy at two levels:

**1. Database-Level Filtering**
- All entities have `organisation` field
- Queries automatically filtered by active organisation
- Prevents cross-organisation data access

**2. Solr Collection Isolation**
- Each tenant gets separate Solr collection
- Collection naming: `{base-collection}-{org-uuid}`
- Complete search index isolation

### Organisation Context Flow

```mermaid
sequenceDiagram
    participant User as User
    participant Session as Session
    participant OrgService as Organisation Service
    participant Mapper as Entity Mapper
    participant DB as Database
    participant Solr as Solr (Tenant Collection)
    
    User->>OrgService: setActiveOrganisation(orgUuid)
    OrgService->>Session: Store active org in session
    Session-->>User: Context switched
    
    Note over User: Create Object
    
    User->>Mapper: createObject(data)
    Mapper->>OrgService: getActiveOrganisation()
    OrgService->>Session: Retrieve active org
    Session-->>OrgService: orgUuid
    OrgService-->>Mapper: organisationUuid
    
    Mapper->>Mapper: Set object.organisation = orgUuid
    Mapper->>DB: INSERT with organisation field
    DB-->>Mapper: Object saved
    
    Mapper->>Solr: Index to tenant collection
    Note over Solr: Collection: base-{orgUuid}
    Solr-->>Mapper: Indexed
    
    Mapper-->>User: Object created
```

### Tenant Collection Management

```mermaid
graph TD
    Start[User Request] --> CheckCollection{Tenant collection<br/>exists?}
    
    CheckCollection -->|Yes| UseCollection[Use Tenant Collection<br/>{base}-{org-uuid}]
    CheckCollection -->|No| CreateCollection[Create Tenant Collection]
    
    CreateCollection --> ConfigSet[Apply ConfigSet<br/>openregister]
    ConfigSet --> SetupShards[Configure Shards<br/>& Replicas]
    SetupShards --> CollectionReady[Collection Ready]
    CollectionReady --> UseCollection
    
    UseCollection --> IndexData[Index/Search Data]
    IndexData --> Success[✓ Operation Complete]
    
    style Success fill:#90EE90
```

### Database Schema

Organisation field added to all tenant-aware tables:

```sql
-- Objects table
ALTER TABLE oc_openregister_objects 
ADD COLUMN organisation VARCHAR(255),
ADD INDEX idx_organisation (organisation);

-- Schemas table
ALTER TABLE oc_openregister_schemas
ADD COLUMN organisation VARCHAR(255),
ADD INDEX idx_organisation (organisation);

-- Registers table
ALTER TABLE oc_openregister_registers
ADD COLUMN organisation VARCHAR(255),
ADD INDEX idx_organisation (organisation);

-- Organisations table
CREATE TABLE oc_openregister_organisations (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    settings JSON,
    created DATETIME,
    updated DATETIME,
    INDEX idx_uuid (uuid),
    INDEX idx_name (name)
);

-- User-Organisation mapping
CREATE TABLE oc_openregister_user_organisations (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id VARCHAR(255) NOT NULL,
    organisation_uuid VARCHAR(255) NOT NULL,
    role VARCHAR(50) DEFAULT 'member',
    created DATETIME,
    UNIQUE KEY unique_user_org (user_id, organisation_uuid),
    INDEX idx_user (user_id),
    INDEX idx_org (organisation_uuid)
);
```

### Performance Optimizations

**1. Session Caching**
- Active organisation cached in user session
- Reduces database queries

**2. Database Indexes**
- Indexed `organisation` field on all tables
- Fast filtering queries

**3. Lazy Collection Creation**
- Tenant collections created on-demand
- Reduces initial overhead

**4. Query Optimization**
- Automatic `WHERE organisation = ?` in all queries
- Prevents full table scans

### Code Examples

**Setting Active Organisation:**

```php
use OCA\OpenRegister\Service\OrganisationService;

$organisationService->setActiveOrganisation($organisationUuid);

// All subsequent operations use this organisation context
$object = $objectService->create($data);
// object.organisation automatically set to $organisationUuid
```

**Querying with Organisation Filter:**

```php
// Automatic organisation filtering
$objects = $objectMapper->findAll();
// SELECT * FROM objects WHERE organisation = '{active-org-uuid}'

// Explicit organisation override (admin only)
$objects = $objectMapper->findByOrganisation($specificOrgUuid);
```

**Tenant Collection Operations:**

```php
use OCA\OpenRegister\Service\GuzzleSolrService;

// Ensure tenant collection exists
$solrService->ensureTenantCollection();

// Get active collection name
$collectionName = $solrService->getActiveCollectionName();
// Returns: "openregister-{org-uuid}"

// Index to tenant collection
$solrService->indexObject($object);
// Automatically uses tenant collection
```

### Best Practices

**✓ DO:**
- Always check active organisation before operations
- Use session-based context switching
- Validate user membership before switching
- Monitor tenant collection sizes
- Implement organisation-scoped audit logs

**✗ DON'T:**
- Don't bypass organisation filtering
- Don't share collections between tenants
- Don't hard-code organisation UUIDs
- Don't skip permission checks
- Don't forget to clear caches on context switch

### Monitoring

```bash
# Check organisation isolation
SELECT organisation, COUNT(*) as count
FROM oc_openregister_objects
GROUP BY organisation;

# List tenant collections
curl "http://solr:8983/solr/admin/collections?action=LIST"

# Monitor collection sizes
curl "http://solr:8983/solr/admin/collections?action=CLUSTERSTATUS"
```

*For testing information, see [Multi-Tenancy Testing Framework](../technical/multi-tenancy-testing.md)*
*For API details, see [Organisations API](../api/organisations.md)* 