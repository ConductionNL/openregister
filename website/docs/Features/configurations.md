# Configurations

Configurations in OpenRegister allow you to package and manage related registers, schemas, and objects together as a coherent unit. This feature is particularly useful for multi-tenancy, application packaging, and organizing complex data structures.

## Overview

A Configuration is a container that groups together:
- **Registers**: Collections of objects that share similar characteristics
- **Schemas**: Data structure definitions that define object properties
- **Objects**: Individual data items (optional)

```mermaid
graph TD
    A[Configuration] --> B[Registers]
    A --> C[Schemas]
    A --> D[Objects]
    
    B --> B1[Register 1]
    B --> B2[Register 2]
    B --> B3[Register N]
    
    C --> C1[Schema 1]
    C --> C2[Schema 2]
    C --> C3[Schema N]
    
    D --> D1[Object 1]
    D --> D2[Object 2]
    D --> D3[Object N]
    
    style A fill:#e3f2fd
    style B fill:#fff3e0
    style C fill:#ffe0b2
    style D fill:#c8e6c9
```

## Key Concepts

### Configuration Properties

Each configuration has the following properties:

- **Title**: A human-readable name for the configuration
- **Description**: Detailed description of what the configuration contains
- **Type**: The type or category of the configuration (e.g., 'application', 'tenant', 'module')
- **Owner/App**: The application or owner identifier
- **Registers**: Array of register IDs that are part of this configuration
- **Schemas**: Array of schema IDs that are part of this configuration
- **Objects**: Array of object IDs that are part of this configuration (optional)
- **Version**: Configuration version for tracking changes
- **Created/Updated**: Timestamps for auditing

## Use Cases

### Application Packaging

Package all registers and schemas for a specific application:

```mermaid
graph LR
    A[App Configuration] --> B[User Register]
    A --> C[Product Register]
    A --> D[User Schema]
    A --> E[Product Schema]
    
    style A fill:#e3f2fd
    style B fill:#fff3e0
    style C fill:#fff3e0
    style D fill:#ffe0b2
    style E fill:#ffe0b2
```

### Multi-Tenancy

Create separate configurations for different tenants:

```mermaid
graph TD
    A[System] --> B[Tenant A Config]
    A --> C[Tenant B Config]
    
    B --> B1[Tenant A Registers]
    B --> B2[Tenant A Schemas]
    
    C --> C1[Tenant B Registers]
    C --> C2[Tenant B Schemas]
    
    style A fill:#e3f2fd
    style B fill:#c8e6c9
    style C fill:#c8e6c9
```

### Module Organization

Group related functionality into logical modules:

- **Core Module**: Base registers and schemas
- **Customer Module**: Customer-related data structures
- **Product Module**: Product catalog structures
- **Order Module**: Order processing structures

## Creating a Configuration

### Step 1: Navigate to Configurations

1. Log in to your Nextcloud instance
2. Navigate to the OpenRegister app
3. Click on **Configurations** in the left sidebar

### Step 2: Add New Configuration

1. Click the **Add Configuration** button
2. Fill in the configuration details:
   - **Title**: Give your configuration a meaningful name
   - **Description**: Describe what this configuration contains
   - **Type**: Specify the configuration type (e.g., 'application', 'tenant')

### Step 3: Select Registers and Schemas

1. **Select Registers**: 
   - Click on the Registers dropdown
   - Select multiple registers that should be part of this configuration
   - The count of selected registers is displayed below the selector

2. **Select Schemas**:
   - Click on the Schemas dropdown
   - Select multiple schemas that should be part of this configuration
   - The count of selected schemas is displayed below the selector

```mermaid
sequenceDiagram
    participant User
    participant UI as Configuration Modal
    participant API as OpenRegister API
    participant DB as Database
    
    User->>UI: Click 'Add Configuration'
    UI->>API: Fetch available registers
    UI->>API: Fetch available schemas
    API-->>UI: Return register list
    API-->>UI: Return schema list
    
    User->>UI: Enter title, description, type
    User->>UI: Select registers
    User->>UI: Select schemas
    User->>UI: Click 'Save'
    
    UI->>API: POST /configurations
    API->>DB: Save configuration
    DB-->>API: Configuration saved
    API-->>UI: Return saved configuration
    UI-->>User: Show success message
```

### Step 4: Save the Configuration

1. Review your selections
2. Click the **Save** button
3. The configuration is created and added to the list

## Editing a Configuration

### Modifying Configuration Details

1. Navigate to the Configurations page
2. Find the configuration you want to edit
3. Click the **Actions** menu (three dots)
4. Select **Edit**
5. Modify the fields as needed:
   - Update title, description, or type
   - Add or remove registers
   - Add or remove schemas
6. Click **Save** to apply changes

### Managing Associations

You can dynamically manage which registers and schemas are associated with a configuration:

- **Add new associations**: Select additional items from the dropdowns
- **Remove associations**: Deselect items from the dropdowns
- **View current associations**: The modal shows all currently selected items

## Viewing Configurations

### Card View

The card view displays configurations as cards showing:
- Configuration title
- Description
- Type
- Owner/App
- Count of associated items (registers, schemas, objects)

### Table View

The table view shows configurations in a tabular format with columns:
- Title
- Type
- Owner
- Config Keys count
- Created date
- Updated date
- Actions

Switch between views using the **Cards** / **Table** toggle buttons.

## Exporting and Importing Configurations

### Exporting

1. Select a configuration
2. Click **Actions** → **Export**
3. The configuration (including its registers, schemas, and optionally objects) is exported as JSON
4. Use this for:
   - Backup and restore
   - Moving configurations between environments
   - Sharing configurations with other installations

### Importing

1. Click **Import Configuration**
2. Select a configuration JSON file
3. Choose whether to include objects
4. The configuration and its associated items are imported

```mermaid
graph LR
    A[Source System] --> B[Export Configuration]
    B --> C[JSON File]
    C --> D[Import Configuration]
    D --> E[Target System]
    
    style A fill:#e3f2fd
    style C fill:#fff3e0
    style E fill:#c8e6c9
```

## Local vs External Configurations

OpenRegister distinguishes between two types of configurations:

### Local Configurations

**Local configurations** are created and maintained within your Nextcloud installation. These are configurations you own and control.

**Characteristics:**
- Created using the 'Create Configuration' button
- Maintained and updated within your installation
- Marked with a green 'Local' pill in the UI
- Can be exported to share with others
- Version is managed by you

```mermaid
graph LR
    A[Create Configuration] --> B[Local Config]
    B --> C[Edit & Manage]
    B --> D[Export to Share]
    D --> E[Other Installations]
    
    style B fill:#d4edda,color:#155724
```

### External Configurations

**External configurations** are imported from external sources and kept in sync with their origin. These are configurations created by others that you import and use.

**Characteristics:**
- Imported from GitHub, GitLab, or URLs
- Marked with a blue 'External' pill in the UI
- Can be automatically synchronized
- Read-only (modifications would be overwritten on sync)
- Version controlled by the source

```mermaid
graph LR
    A[External Source] --> B[Import Config]
    B --> C[External Config]
    C --> D[Auto-Sync]
    D --> A
    
    style C fill:#d1ecf1,color:#0c5460
```

### Sync Status Indicators

External configurations display sync status badges showing:
- **Synced just now**: Recently synchronized
- **Synced 2h ago**: Time since last sync
- **Sync failed**: Last sync attempt failed
- **Never synced**: No sync performed yet

## Discovering and Importing Configurations

OpenRegister provides multiple ways to discover and import configurations from external sources:

### Discovery from GitHub/GitLab

**OpenRegister uses a two-phase discovery approach** for optimal global discovery and content validation:

**Phase 1: Organization Discovery**
- Searches globally for any JSON files containing 'openregister' in the filename or path
- Identifies all organizations/users that have potential OpenRegister files
- No organization restrictions - searches **all public repositories**

**Phase 2: Content Validation**
- Searches within discovered organizations for files containing 'x-openregister'
- Validates that files are actual OpenRegister configurations
- Filters out false positives automatically

**Benefits:**
- ✅ Truly global discovery - automatically finds new organizations
- ✅ Content validation - ensures files are real OpenRegister configs
- ✅ No hardcoded lists - organizations are dynamically discovered
- ✅ Flexible naming - works with any JSON filename containing openregister

**Steps:**
1. Click **Import Configuration**
2. Select the **Discover** tab
3. Enter search terms (optional) - searches in filename, repository, title, description
4. Click **Search GitHub** or **Search GitLab**
5. Browse discovered configurations from across GitHub/GitLab
6. Click **Import** on any configuration

**How to make your configuration discoverable:**
1. **Filename**: Include 'openregister' in your JSON filename (e.g., `myapp_openregister.json`, `catalog.openregister.json`)
2. **Content**: Ensure your JSON file contains the 'x-openregister' property
3. **Public repository**: File must be in a public GitHub/GitLab repository

**Note on naming:** While any filename containing 'openregister' will be discovered, we recommend the `*_openregister.json` pattern to avoid conflicts with legacy OpenAPI specification files.

**Alternative import methods:**
- **Import from GitHub tab**: Browse any repository directly and select any JSON file
- **Import from URL tab**: Use the raw GitHub URL (e.g., `https://raw.githubusercontent.com/owner/repo/branch/path/to/file.json`)

```mermaid
sequenceDiagram
    participant User
    participant UI as Import Modal
    participant API as OpenRegister API
    participant GitHub as GitHub API
    
    User->>UI: Click 'Import Configuration'
    User->>UI: Select 'Discover' tab
    User->>UI: Enter search query
    User->>UI: Click 'Search GitHub'
    UI->>API: /api/configurations/discover?source=github&_search=...
    
    Note over API: Phase 1: Organization Discovery
    API->>GitHub: Search: openregister.json extension:json in:path
    GitHub-->>API: Return all repos with 'openregister' files
    API->>API: Extract unique organizations
    
    Note over API: Phase 2: Content Validation
    API->>GitHub: Search: x-openregister org:OrgA org:OrgB ...
    Note over GitHub: Content search within discovered organizations<br/>Validates files contain x-openregister
    GitHub-->>API: Return validated configurations
    
    API-->>UI: Return filtered results
    UI-->>User: Display configuration cards
    User->>UI: Click 'Import'
    UI->>API: /api/configurations/import/github
    API-->>UI: Configuration imported
```

### Import from Specific Repository

1. Click **Import Configuration**
2. Select the **GitHub / GitLab** tab
3. Select source platform (GitHub or GitLab)
4. Enter repository details:
   - For GitHub: Owner and Repository name
   - For GitLab: Namespace and Project name
5. Click **Load Branches**
6. Select a branch
7. Choose a configuration file from the list
8. Configure sync settings
9. Click **Import**

### Import from Direct URL

1. Click **Import Configuration**
2. Select the **Import from URL** tab
3. Enter the direct URL to a JSON configuration file
4. Configure sync settings:
   - Enable automatic synchronization
   - Set sync interval (in hours)
5. Click **Import**

**Getting the raw URL from GitHub:**
1. Navigate to your file on GitHub
2. Click the **Raw** button (top right of the file viewer)
3. Copy the URL from your browser's address bar
4. Example format: `https://raw.githubusercontent.com/owner/repo/branch/path/to/file.json`

**Tip:** This method works immediately after pushing - no need to wait for GitHub's search index!

## File Naming Conventions for Discoverability

To make your configurations discoverable by others, include 'openregister' in your JSON filename:

### Recommended File Names

- **Preferred format**: `{app}_openregister.json` (e.g., `myapp_openregister.json`)
- **Alternative formats**: `{app}.openregister.json`, `openregister.{app}.json`
- **Simple format**: `openregister.json` (works but less specific)

### Examples

```
# Preferred (with underscore)
softwarecatalogus_openregister.json
publication_openregister.json
customer_management_openregister.json

# Also works (without underscore)
opencatalogi.openregister.json
publication-register.openregister.json
customer-management.openregister.json
openregister.json
```

**Why the underscore (`_`) is preferred:**
- More explicit pattern for OpenRegister configurations
- Avoids confusion with legacy OpenAPI specification files
- Consistent convention across all apps
- Recommended but not required - all patterns above work!

### File Location

Place configuration files in a logical location within your repository:

```
my-app/
├── lib/
│   └── Settings/
│       └── my-app.openregister.json
├── config/
│   └── openregister.json
└── README.md
```

### Metadata for Discovery

Ensure your configuration includes proper metadata in the 'x-openregister' section:

```json
{
    'openapi': '3.0.0',
    'info': {
        'title': 'My App Configuration',
        'description': 'Configuration for my awesome application',
        'version': '1.0.0'
    },
    'x-openregister': {
        'type': 'application',
        'app': 'myapp',
        'sourceType': 'github',
        'sourceUrl': 'https://github.com/MyOrg/myapp/blob/main/lib/Settings/myapp_openregister.json',
        'openregister': '^v0.2.10',
        'github': {
            'repo': 'MyOrg/myapp',
            'branch': 'main',
            'path': 'lib/Settings/myapp_openregister.json'
        }
    },
    'components': {
        'registers': {},
        'schemas': {}
    }
}
```

**Key requirements for discoverability:**
1. **Filename**: Must contain 'openregister' (e.g., `myapp_openregister.json`)
2. **x-openregister property**: Must be present in the JSON file (triggers Phase 2 validation)
3. **Public repository**: File must be accessible in a public GitHub/GitLab repository
4. **Valid JSON**: File must be valid JSON and follow the OpenAPI 3.0 structure

### Making Configurations Discoverable

For your configuration to be discoverable via GitHub/GitLab search in OpenRegister, follow these requirements:

#### 1. File Structure

Your configuration file MUST be a valid OpenAPI 3.0 document with an 'x-openregister' extension:

```json
{
    'openapi': '3.0.0',
    'info': {
        'title': 'Required: Configuration Title',
        'description': 'Required: Detailed description',
        'version': 'Required: Semantic version (e.g., 1.0.0)'
    },
    'x-openregister': {
        'type': 'Required: Type of configuration (application/tenant/module)',
        'app': 'Required: Application identifier',
        'sourceType': 'Required: github or gitlab',
        'sourceUrl': 'Required: Full URL to this file',
        'openregister': 'Optional: Version constraint (e.g., ^v0.2.10)',
        'github': {
            'repo': 'Required: owner/repository',
            'branch': 'Required: branch name',
            'path': 'Required: path to file in repo'
        }
    },
    'components': {
        'registers': {},
        'schemas': {}
    }
}
```

#### 2. File Naming Convention for Discovery

**How OpenRegister Discovery Works:**

OpenRegister uses a **two-phase discovery approach** for reliable global configuration discovery:

**Phase 1: Organization Discovery (Filename-based)**
- Searches for JSON files containing 'openregister' in the filename or path
- Works **globally** across all public repositories (no organization restrictions!)
- Discovers organizations/users that have potential OpenRegister files

**Phase 2: Content Validation**
- Searches within discovered organizations for files containing 'x-openregister'
- Validates that files are actual OpenRegister configurations
- Filters out false positives automatically

**File Naming Requirements:**

Your configuration filename **MUST** contain 'openregister' to be discoverable:

- ✅ **Preferred**: `*_openregister.json` (e.g., `myapp_openregister.json`)
- ✅ **Also works**: `*.openregister.json`, `openregister.*.json`, `openregister.json`

**Why `_openregister` is preferred:**
- More explicit pattern for OpenRegister configurations
- Avoids confusion with legacy OpenAPI specification files
- Consistent convention recommended across all apps
- But **any** filename containing 'openregister' works!

**Content Requirements (Phase 2 Validation):**
- File **MUST** contain an 'x-openregister' property in the JSON
- This is what validates your file as a real OpenRegister configuration
- Files without 'x-openregister' will be filtered out even if the filename matches

**Discovery Indexing:**

- New files are typically indexed within **minutes to hours** after pushing to GitHub
- Renamed files are re-indexed automatically
- No waiting period or organization approval needed!

**Testing Discovery:**

After pushing your file, test it appears in OpenRegister:
1. Go to **Import Configuration** → **Discover** tab
2. Search for keywords from your filename, title, or description
3. Your configuration should appear in global results
4. If it doesn't appear immediately, wait a few hours for GitHub to index it

#### 3. Required Fields

**info section:**
- 'title': Short, descriptive name shown in discovery results
- 'description': Detailed explanation of what the configuration provides
- 'version': Semantic version number (1.0.0, 2.1.3, etc.)

**x-openregister section:**
- 'type': Configuration type ('application', 'tenant', 'module', etc.)
- 'app': Application identifier (lowercase, no spaces)
- 'sourceType': Must be 'github' or 'gitlab' for discovery
- 'sourceUrl': Complete HTTPS URL to the file on GitHub/GitLab
- 'github' or 'gitlab': Object with 'repo', 'branch', and 'path'

**Optional fields:**
- 'openregister': Minimum OpenRegister version required (Composer notation)

#### 3. File Naming Conventions

For best discoverability, use one of these naming patterns:

- '*_openregister.json' (recommended): 'myapp_openregister.json'
- 'openregister.json': Generic name
- '*.openregister.json': 'config.openregister.json'

#### 4. Complete Example

Here's a complete, production-ready example:

```json
{
    'openapi': '3.0.0',
    'info': {
        'title': 'VNG Software Catalog Register',
        'description': 'Register containing AMEF and Voorzieningen schemas for the VNG Software Catalog application. This configuration includes schemas for modules, services, organizations, and compliance tracking.',
        'version': '2.0.1'
    },
    'x-openregister': {
        'type': 'application',
        'app': 'softwarecatalog',
        'sourceType': 'github',
        'sourceUrl': 'https://github.com/ConductionNL/opencatalogi/blob/master/apps-extra/softwarecatalog/lib/Settings/softwarecatalogus_register.json',
        'openregister': '^v0.2.10',
        'github': {
            'repo': 'ConductionNL/opencatalogi',
            'branch': 'master',
            'path': 'apps-extra/softwarecatalog/lib/Settings/softwarecatalogus_register.json'
        }
    },
    'components': {
        'registers': {
            'products': {
                'slug': 'products',
                'title': 'Products',
                'description': 'Product catalog register',
                'schemas': ['product']
            }
        },
        'schemas': {
            'product': {
                'title': 'Product',
                'description': 'Product schema',
                'properties': {
                    'name': {'type': 'string'},
                    'price': {'type': 'number'}
                }
            }
        }
    }
}
```

#### 5. Testing Discoverability

After publishing your configuration file to GitHub/GitLab:

1. **Verify your filename**: Ensure your file **ends with** `_openregister.json` (e.g., `app_openregister.json`)
2. **Push to GitHub**: Commit and push your configuration file
3. **Wait for indexing**: GitHub typically indexes new files within minutes to a few hours
4. **Test discovery**: In OpenRegister, click **Import Configuration** → **Discover** tab
5. **Search**: Enter keywords from your filename, title, or description
6. **Verify**: Your configuration should appear in the global results

**Troubleshooting:**
- **Not appearing in discovery?** 
  - **Check filename**: Does it **end with** `_openregister.json`? (e.g., `myapp_openregister.json`)
  - **Wrong pattern**: Files named just `openregister.json` (without prefix) won't be discovered
  - **Wait for indexing**: New files may take a few hours to appear in GitHub's search index
  - **Test file exists**: Visit your file URL directly on GitHub to confirm it's pushed
  - **Use alternative import methods**: 'Import from URL' or 'Import from GitHub' (by repository) work immediately for any public repository
- **Need immediate access?** Use the 'Import from GitHub' tab and browse your repository directly, or use 'Import from URL' with the raw file URL
- Validate your JSON structure
- Ensure all required fields are present
- Check that 'sourceUrl' is accessible publicly
- Verify the 'x-openregister' extension is at the root level
- Confirm the file is on a public repository (or you've configured API tokens)

#### 6. API Tokens for Private Repositories

If your configuration is in a private repository:

1. Navigate to **Settings** → **OpenRegister Settings**
2. Scroll to **API Token Configuration**
3. Enter your GitHub or GitLab Personal Access Token
4. Required scopes:
   - **GitHub**: 'repo' (for full access)
   - **GitLab**: 'read_api' (discovery only) or 'api' (discovery + publishing)

#### 7. Publishing Updates

When you update your configuration:

1. Increment the 'version' in the 'info' section
2. Commit and push the changes
3. External installations with auto-sync will automatically receive updates
4. Manual imports will see an 'Update Available' badge

## OpenRegister Version Requirements

Configurations can specify which OpenRegister versions they are compatible with using Composer-style notation:

### Version Constraint Format

Use the 'openregister' field in 'x-openregister':

```json
{
    'x-openregister': {
        'openregister': '^v0.2.10'
    }
}
```

### Supported Constraint Formats

Following Composer notation:

- **Caret (^)**: '^v1.2.3' - Allow version 1.2.3 and up, but not 2.0.0
- **Tilde (~)**: '~1.2.3' - Allow version 1.2.3 to 1.3.0 (excluding)
- **Exact**: 'v1.2.3' - Only version 1.2.3
- **Greater/Less**: '>=1.2.0' - Version 1.2.0 or higher
- **Range**: '>=1.0.0 <2.0.0' - Between 1.0.0 and 2.0.0

### Examples

```json
// Require v0.2.10 or higher (but not v0.3.0)
{'openregister': '^v0.2.10'}

// Require at least v1.0.0
{'openregister': '>=v1.0.0'}

// Require between v1.0 and v2.0
{'openregister': '>=v1.0.0 <v2.0.0'}

// Exact version
{'openregister': 'v1.5.0'}
```

## Automatic Synchronization

External configurations can be automatically synchronized with their source to keep them up-to-date.

### Configuring Auto-Sync

When importing a configuration from GitHub, GitLab, or URL:

1. Enable the **Enable automatic synchronization** toggle
2. Set the **Sync Interval** (in hours, 1-168)
3. Complete the import

### Sync Behavior

- **Check Interval**: OpenRegister checks for updates based on the configured interval
- **Version Comparison**: Compares local and remote versions
- **Automatic Update**: If a new version is available, automatically imports it
- **Status Tracking**: Records sync status and timestamp
- **Error Handling**: Failed syncs are logged and displayed in the UI

```mermaid
sequenceDiagram
    participant Cron as Background Job
    participant Service as ConfigurationService
    participant GitHub as External Source
    participant DB as Database
    
    Cron->>Service: Check configurations due for sync
    Service->>DB: Find syncEnabled=true configs
    DB-->>Service: Return configurations
    
    loop For each configuration
        Service->>GitHub: Fetch current version
        GitHub-->>Service: Return configuration
        Service->>Service: Compare versions
        alt New version available
            Service->>Service: Import updates
            Service->>DB: Update configuration
            Service->>DB: Set syncStatus='success'
        else No update needed
            Service->>DB: Update lastSyncDate
        end
    end
```

### Manual Sync

You can also manually trigger a sync:

1. Navigate to Configurations
2. Find the external configuration
3. Click **Actions** → **Check Version**
4. If an update is available, you'll be notified

### Sync Frequency

Recommended intervals based on use case:

- **Production**: 24-48 hours
- **Development**: 1-6 hours
- **Testing**: 1-2 hours

## Uploading Local Configurations for Sharing

To share your local configurations with others:

### Option 1: Upload to GitHub

1. Export your configuration (JSON file)
2. Create or navigate to your GitHub repository
3. Create a directory for configurations (e.g., 'config/')
4. Upload your configuration file
5. Commit with descriptive message
6. Others can now import using your repository URL

**File structure:**
```
my-repository/
├── config/
│   └── myapp.openregister.json
└── README.md
```

### Option 2: Upload to GitLab

Similar to GitHub:

1. Export configuration
2. Navigate to your GitLab project
3. Upload to a logical directory
4. Commit changes
5. Share project URL

### Option 3: Host on Your Server

1. Export configuration
2. Upload to your web server
3. Make accessible via HTTPS
4. Share the direct URL
5. Others can import using 'Import from URL'

**Example URL:**
```
https://mysite.com/configs/myapp.openregister.json
```

### Best Practices for Sharing

1. **Include README**: Document what your configuration does
2. **Version Properly**: Use semantic versioning in 'info.version'
3. **Set Version Requirements**: Specify compatible OpenRegister versions
4. **Test Before Sharing**: Verify imports work correctly
5. **Maintain Documentation**: Keep usage instructions updated
6. **Use Descriptive Names**: Make file names self-explanatory
7. **Regular Updates**: Keep configurations current with your app

## Programmatic Configuration Import for Apps

Apps can programmatically import their configurations using the ConfigurationService without needing to manually create Configuration entities. This is useful for:

- App initialization and setup
- Automatic configuration deployment
- Version-based configuration updates
- Bundling configurations with app releases

OpenRegister provides two methods for importing configurations:

### Method 1: Import from File Path (Recommended)

The `importFromFilePath` method is the recommended approach when your configuration is stored in a JSON file. OpenRegister handles all file reading and parsing:

```php
// Get the configuration service
$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');

// Import from file path (relative to Nextcloud root)
$result = $configurationService->importFromFilePath(
    appId: 'myapp',
    filePath: 'apps-extra/myapp/lib/Settings/myapp_config.json',
    version: '1.0.0',
    force: false
);
```

**Benefits:**
- OpenRegister handles file reading and JSON parsing
- Automatically sets sourceUrl for cron job tracking
- Ensures configuration uniqueness by sourceUrl
- Enables automatic update checking by the cron job

### Method 2: Import from Data Array

The `importFromApp` method is for when you already have configuration data in memory:

```php
// Get the configuration service
$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');

// Load your configuration data (from JSON file, array, etc.)
$configData = json_decode(file_get_contents('path/to/config.json'), true);

// Import the configuration
$result = $configurationService->importFromApp(
    appId: 'myapp',           // Your app ID
    data: $configData,        // Configuration data array
    version: '1.0.0',         // Version string
    force: false              // Force import even if version is not newer
);

// Result contains imported entities
// $result['registers'] - Array of imported Register entities
// $result['schemas'] - Array of imported Schema entities  
// $result['objects'] - Array of imported Object entities
```

### How It Works

Both import methods automatically:

1. **Finds or Creates Configuration Entity**: 
   - First checks by sourceUrl (if provided) to ensure uniqueness
   - Falls back to checking by app ID if no sourceUrl match
   - Creates a new Configuration entity if none exists
2. **Handles Version Checking**: Compares versions and skips import if the same or older version is already installed (unless force=true).
3. **Tracks Imported Entities**: Associates all imported registers, schemas, and objects with the Configuration entity.
4. **Updates Configuration**: Merges new entity IDs with existing ones on subsequent imports, and updates metadata from the import data.

```mermaid
sequenceDiagram
    participant App
    participant Service as ConfigurationService
    participant Mapper as ConfigurationMapper
    participant DB as Database
    
    App->>Service: importFromApp(appId, data, version)
    Service->>Mapper: findByApp(appId)
    
    alt Configuration exists
        Mapper-->>Service: Existing Configuration
    else Configuration does not exist
        Service->>Service: Create new Configuration
        Service->>Mapper: insert(configuration)
        Mapper-->>Service: Configuration with ID
    end
    
    Service->>Service: importFromJson(data, configuration)
    Service->>DB: Save registers, schemas, objects
    DB-->>Service: Import results
    
    Service->>Service: Update Configuration with entity IDs
    Service->>Mapper: update(configuration)
    Mapper-->>Service: Updated Configuration
    
    Service-->>App: Import results
```

### Example: App Installation Hook

Use this in your app's installation or upgrade hooks:

```php
namespace OCA\MyApp\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;

class InstallConfiguration implements IRepairStep {
    
    private ContainerInterface $container;
    
    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }
    
    public function getName(): string {
        return 'Install MyApp configuration';
    }
    
    public function run(IOutput $output): void {
        try {
            // Get configuration service
            $configService = $this->container->get(
                'OCA\OpenRegister\Service\ConfigurationService'
            );
            
            // Load configuration from bundled JSON file
            $configPath = __DIR__ . '/../Settings/configuration.json';
            $configData = json_decode(file_get_contents($configPath), true);
            
            // Import configuration
            $result = $configService->importFromApp(
                appId: 'myapp',
                data: $configData,
                version: '1.0.0',
                force: false
            );
            
            $output->info(sprintf(
                'Configuration imported: %d registers, %d schemas, %d objects',
                count($result['registers']),
                count($result['schemas']),
                count($result['objects'])
            ));
            
        } catch (\Exception $e) {
            $output->warning('Failed to import configuration: ' . $e->getMessage());
        }
    }
}
```

### Example: Version-Based Updates

Update configurations only when app version increases:

```php
// In your app's upgrade logic
$appManager = \OC::$server->getAppManager();
$currentVersion = $appManager->getAppVersion('myapp');

$configService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');

// Check if update is needed
$storedVersion = $configService->getConfiguredAppVersion('myapp');

if ($storedVersion === null || version_compare($currentVersion, $storedVersion, '>')) {
    // Load updated configuration
    $configData = $this->loadConfiguration();
    
    // Import with app version
    $result = $configService->importFromApp(
        appId: 'myapp',
        data: $configData,
        version: $currentVersion,
        force: false
    );
    
    // Configuration is now at the current app version
}
```

### Configuration Data Format

Your configuration data should follow the OpenAPI 3.0 specification with OpenRegister-specific metadata stored in the `x-openregister` extension field following the [OpenAPI Extensions specification](https://swagger.io/docs/specification/v3_0/openapi-extensions/).

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "MyApp Configuration",
    "description": "Default configuration for MyApp",
    "version": "1.0.0"
  },
  "x-openregister": {
    "type": "app",
    "app": "myapp",
    "sourceType": "local",
    "sourceUrl": "apps-extra/myapp/lib/Settings/myapp_config.json",
    "github": {
      "repo": null,
      "branch": null,
      "path": null
    }
  },
  "components": {
    "registers": {
      "myregister": {
        "title": "My Register",
        "slug": "myregister",
        "description": "Application data register",
        "version": "1.0.0",
        "schemas": ["myschema"]
      }
    },
    "schemas": {
      "myschema": {
        "title": "My Schema",
        "slug": "myschema",
        "version": "1.0.0",
        "type": "object",
        "properties": {
          "name": {
            "type": "string",
            "title": "Name"
          },
          "description": {
            "type": "string",
            "title": "Description"
          }
        },
        "required": ["name"]
      }
    }
  }
}
```

#### x-openregister Extension

The `x-openregister` extension contains OpenRegister-specific metadata following the OpenAPI Extensions specification. This extension is used to store configuration properties that are not part of the standard OpenAPI specification.

**Standard OAS Properties (in info section):**
- `title`: Configuration title
- `description`: Configuration description
- `version`: Configuration version

**OpenRegister-specific Properties (in x-openregister extension):**

- `type`: Type of configuration (e.g., 'app', 'imported', 'manual')
- `app`: Application identifier
- `sourceType`: Source type ('local', 'github', 'gitlab', 'url', 'manual')
- `sourceUrl`: File path or URL to configuration source (relative to Nextcloud root for local files)
- `github`: Object containing GitHub-specific properties:
  - `repo`: GitHub repository (format: 'owner/repo')
  - `branch`: GitHub branch name (e.g., 'main', 'develop')
  - `path`: Path within GitHub repository (e.g., 'configs/schema.json')

**Properties excluded from export/import:**

The following internal properties are managed automatically by the system and are NOT included in configuration exports or processed during imports:

**System-managed metadata:**
- `id`: Internal database ID
- `uuid`: Internal UUID
- `created`: Creation timestamp
- `updated`: Last update timestamp
- `lastChecked`: Last version check timestamp
- `remoteVersion`: Remote version (auto-detected)
- `localVersion`: Local version (auto-updated)

**Instance-specific settings:**
- `autoUpdate`: Whether to automatically update (instance-specific preference)
- `notificationGroups`: Groups to notify on updates (instance-specific)
- `owner`: Owner user ID (instance-specific)
- `organisation`: Organisation UUID (instance-specific, may not exist in target instance)

**Entity references (tracked automatically during import):**
- `registers`: Array of register IDs (built from components.registers)
- `schemas`: Array of schema IDs (built from components.schemas)
- `objects`: Array of object IDs (built from components.objects)
- `views`: Array of view IDs (tracked automatically)
- `agents`: Array of agent IDs (tracked automatically)
- `sources`: Array of source IDs (tracked automatically)
- `applications`: Array of application IDs (tracked automatically)

**Minimal Configuration Example:**

For simple use cases, you only need to include the essential fields:

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "MyApp Configuration",
    "version": "1.0.0"
  },
  "x-openregister": {
    "type": "app",
    "app": "myapp",
    "version": "1.0.0"
  },
  "components": {
    "registers": {},
    "schemas": {}
  }
}
```

### Best Practices

#### Configuration Files

- Store configuration in `Settings/` or `Config/` directory within your app
- Use semantic versioning for your configuration
- Include metadata (appId, version, title, description) in the JSON

#### Version Management

- Set version to match your app version
- Increment configuration version with app updates
- Use `force: false` to respect version checks
- Only use `force: true` during development or explicit reinstalls

#### Error Handling

- Wrap imports in try-catch blocks
- Log errors appropriately
- Provide meaningful error messages to admins
- Consider fallback behavior if import fails

#### Testing

- Test configuration imports in development environment first
- Verify all registers, schemas, and objects are created correctly
- Test upgrade scenarios (version updates)
- Test fresh install scenarios

## Configuration API

### List Configurations

```http
GET /index.php/apps/openregister/api/configurations
```

### Get Single Configuration

```http
GET /index.php/apps/openregister/api/configurations/{id}
```

### Create Configuration

```http
POST /index.php/apps/openregister/api/configurations
Content-Type: application/json

{
  "title": "My Application",
  "description": "Core application configuration",
  "type": "application",
  "registers": [1, 2, 3],
  "schemas": [4, 5, 6]
}
```

### Update Configuration

```http
PUT /index.php/apps/openregister/api/configurations/{id}
Content-Type: application/json

{
  "title": "Updated Application",
  "description": "Updated description",
  "registers": [1, 2, 3, 7],
  "schemas": [4, 5, 6, 8]
}
```

### Delete Configuration

```http
DELETE /index.php/apps/openregister/api/configurations/{id}
```

## Data Structure

### Configuration Entity

```typescript
{
  id: string
  title: string
  description: string | null
  type: string
  owner: string
  registers: number[]  // Array of register IDs
  schemas: number[]    // Array of schema IDs
  objects: number[]    // Array of object IDs (optional)
  created: string      // ISO 8601 timestamp
  updated: string      // ISO 8601 timestamp
}
```

### Database Schema

The configuration is stored in the 'oc_openregister_configurations' table with the following fields:

- 'id': Primary key (integer)
- 'title': Configuration name (string)
- 'description': Detailed description (text, nullable)
- 'type': Configuration type (string)
- 'app': Owner/app identifier (string)
- 'version': Configuration version (string)
- 'registers': JSON array of register IDs
- 'schemas': JSON array of schema IDs
- 'objects': JSON array of object IDs
- 'created': Creation timestamp (datetime)
- 'updated': Last update timestamp (datetime)

## Best Practices

### Naming Conventions

- Use descriptive titles that clearly indicate the configuration's purpose
- Include the application or tenant name in the title when applicable
- Use consistent type values across your system (e.g., always use 'application', not 'app' or 'Application')

### Organization

- Create configurations for logical groupings of functionality
- Keep configurations focused - don't mix unrelated registers and schemas
- Use configurations to enforce multi-tenancy boundaries
- Document the purpose of each configuration in the description field

### Version Management

- The system automatically increments the patch version on each update
- Major and minor version changes should be managed manually when needed
- Export configurations before making major structural changes

### Multi-Tenancy

- Create separate configurations for each tenant
- Never share registers or schemas between tenant configurations
- Use the owner/app field to identify the tenant
- Export/import configurations to provision new tenants

## Troubleshooting

### Cannot Save Configuration

**Problem**: Save button is disabled

**Solution**: Ensure both title and type fields are filled in. These are required fields.

### Registers/Schemas Not Showing in Dropdown

**Problem**: No items appear in the register or schema selector

**Solution**: 
- Ensure you have created registers and schemas in the system
- Refresh the page to reload the data
- Check that you have the necessary permissions to view registers and schemas

### Changes Not Persisting

**Problem**: Selected registers or schemas are not saved

**Solution**:
- Verify that you clicked the Save button (not just closed the modal)
- Check browser console for any API errors
- Ensure you have write permissions for configurations
- Try refreshing the page and editing again

### Import Fails

**Problem**: Configuration import returns an error

**Solution**:
- Verify the JSON file format is correct
- Ensure referenced registers and schemas exist or will be created
- Check for ID conflicts if importing into a system with existing data
- Review server logs for detailed error messages

## Related Features

- [Registers](registers.md) - Learn about register management
- [Schemas](schemas.md) - Learn about schema definitions
- [Objects](objects.md) - Learn about object management
- [Import/Export](../technical/import-export.md) - Detailed import/export documentation
- [Multi-Tenancy](multi-tenancy.md) - Multi-tenancy implementation details

## Configuration Management System

The Configuration Management System provides advanced features for managing configurations from remote sources, tracking versions, and keeping configurations synchronized.

### Overview

The configuration management system allows you to:

- Link configurations to remote sources (GitHub, GitLab, URL)
- Track local and remote versions automatically
- Preview changes before importing
- Selectively import only the changes you want
- Set up automatic updates when new versions are detected
- Receive Nextcloud notifications when updates are available
- Push local configurations to GitHub repositories

```mermaid
graph TD
    A[Remote Config Source] -->|Check Version| B[Configuration Entity]
    B -->|Preview Changes| C[Preview Modal]
    C -->|User Approval| D[Selective Import]
    D -->|Update Entities| E[Local Registers/Schemas]
    
    F[Cron Job] -->|Hourly Check| B
    B -->|Update Available| G[Notification]
    G -->|Notify| H[Admins & Groups]
    
    style A fill:#e3f2fd
    style B fill:#fff3e0
    style C fill:#ffe0b2
    style D fill:#c8e6c9
    style E fill:#bbdefb
    style F fill:#f3e5f5
    style G fill:#ffecb3
    style H fill:#c8e6c9
```

### Source Types

Configurations can be managed from different source types:

#### Local Source

A locally managed configuration that is not synchronized with any remote source. This is the default type and is suitable for:

- Custom configurations specific to your installation
- Configurations used for exporting to other systems
- Development and testing purposes

#### GitHub Source

Configuration synchronized with a GitHub repository. Requires:

- A GitHub Personal Access Token (configured in user settings)
- Repository name in format 'owner/repo'
- Branch name (e.g., 'main', 'develop')
- File path within the repository

#### GitLab Source

Configuration synchronized with a GitLab repository. Works similarly to GitHub with GitLab-specific authentication.

#### URL Source

Configuration fetched from any publicly accessible URL. The URL should point to a raw JSON or YAML configuration file.

### Version Tracking

The system tracks two version numbers for each configuration:

#### Local Version

The version currently installed in your system. This is updated automatically when you:

- Import a configuration
- Make manual changes to managed entities
- Apply updates from a remote source

#### Remote Version

The latest version available from the remote source. The system:

- Checks this automatically via the cron job
- Compares it with the local version using semantic versioning
- Shows an update notification if a newer version is available

```mermaid
sequenceDiagram
    participant Cron as Cron Job
    participant Service as ConfigurationService
    participant Remote as Remote Source
    participant DB as Database
    participant Notif as Notification System
    
    Cron->>Service: Run version check
    Service->>DB: Load configurations
    DB-->>Service: Configurations with remote sources
    
    loop For each configuration
        Service->>Remote: Fetch remote config
        Remote-->>Service: Remote configuration data
        Service->>Service: Extract version info
        Service->>Service: Compare versions
        
        alt Update available
            Service->>DB: Update remoteVersion field
            Service->>Notif: Send notification
            Notif->>Notif: Notify admins & groups
        end
    end
```

### Previewing Configuration Changes

Before importing or updating a configuration, you can preview all changes that will be applied:

#### Preview Modal Features

The preview modal shows:

1. **Summary Information**
   - Configuration title and description
   - Local and remote version numbers
   - Total number of changes

2. **Change Details by Entity Type**
   - Registers: What will be created or updated
   - Schemas: What will be created or updated
   - Objects: What will be created or updated

3. **Action Indicators**
   - **Create**: New entity will be created
   - **Update**: Existing entity will be modified
   - **Skip**: No changes detected

4. **Version Comparison**
   - Current version of each entity
   - Proposed version from remote configuration

5. **Field-Level Differences**
   - Shows exactly which fields will change
   - Displays old and new values
   - Highlights additions and deletions

#### Selective Import

You can choose which changes to apply:

- **Select All** button: Selects all proposed changes
- **Deselect All** button: Clears all selections
- **Individual Selection**: Check/uncheck specific entities
- **Import Selected** button: Applies only the selected changes

```mermaid
graph LR
    A[Configuration] -->|Preview| B[Change Detection]
    B --> C[Register Changes]
    B --> D[Schema Changes]
    B --> E[Object Changes]
    
    C --> F[User Selection]
    D --> F
    E --> F
    
    F -->|Import Selected| G[Apply Changes]
    G --> H[Update Local Entities]
    
    style A fill:#e3f2fd
    style B fill:#fff3e0
    style F fill:#ffe0b2
    style G fill:#c8e6c9
    style H fill:#bbdefb
```

### Auto-Update Functionality

Configurations can be set to automatically update when new versions are detected:

#### Enabling Auto-Update

1. Edit the configuration
2. Navigate to the **Management** tab
3. Check **Enable Auto-Update**
4. Save the configuration

When auto-update is enabled:

- The cron job checks for updates hourly
- If a newer version is found, it is automatically imported
- All changes are applied without requiring user approval
- No preview or selection is performed
- Admins are notified after the update completes

**Important**: Use auto-update only for trusted sources where you want all changes applied automatically.

### Notification System

The system can notify users when configuration updates are available:

#### Notification Groups

Configure which groups receive notifications:

1. **Admin Group** (always included): System administrators
2. **Custom Groups**: Add any Nextcloud groups

#### Notification Behavior

- Notifications are sent when a newer remote version is detected
- Auto-update configurations bypass the notification (update happens automatically)
- Clicking a notification takes you to the configuration preview
- Notifications are dismissed once the update is applied

### Managed Entities

Entities (Registers, Schemas, Objects) that are created from a configuration are marked as **managed**:

#### Restrictions on Managed Entities

- Cannot be edited directly through their detail pages
- Edit buttons are disabled in the UI
- API attempts to modify them return an error
- Only the configuration that manages them can update them

#### Identifying Managed Entities

Managed entities display a pill badge showing:

- **Configuration Name**: Which configuration manages the entity
- **Version**: The current version from that configuration

Entities without a configuration show a **Manual** badge, indicating they can be edited freely.

```mermaid
graph TD
    A[Entity] --> B{Is Managed?}
    
    B -->|Yes| C[Check Configuration]
    C --> D[Show Config Badge]
    D --> E[Disable Edit]
    
    B -->|No| F[Show Manual Badge]
    F --> G[Allow Edit]
    
    style A fill:#e3f2fd
    style C fill:#fff3e0
    style D fill:#ffe0b2
    style E fill:#ffcdd2
    style F fill:#c8e6c9
    style G fill:#a5d6a7
```

### GitHub Integration

The system provides deep integration with GitHub for configuration management:

#### Push to GitHub

Export your configuration directly to a GitHub repository:

1. Navigate to the configuration detail page
2. Click **Actions** → **Push to GitHub**
3. The configuration is committed to the configured repository

Requirements:

- GitHub Personal Access Token set in user settings
- Repository, branch, and path configured in the configuration
- Write access to the target repository

#### Create Pull Request

Create a pull request for your configuration changes:

1. Make changes to a configuration
2. Click **Actions** → **Create Pull Request**
3. A new PR is created in the configured repository

This allows for review workflows before changes are merged.

### Configuration Management Tab

The configuration edit modal includes a **Management** tab with these fields:

#### Source Settings

- **Source Type**: Choose from Local, GitHub, GitLab, or URL
- **Source URL**: The URL to the remote configuration file (for remote types)

#### Version Settings

- **Local Version**: The currently installed version (editable)
- **Remote Version**: Latest version from remote source (read-only, auto-detected)

#### Update Settings

- **Enable Auto-Update**: Checkbox to enable automatic updates
- **Notification Groups**: Select which groups receive update notifications

#### GitHub Settings (when Source Type is GitHub)

- **GitHub Repository**: Format 'owner/repo'
- **GitHub Branch**: The branch to use (default: 'main')
- **GitHub Path**: Path to the configuration file within the repository

### API Endpoints

#### Check Remote Version

```http
GET /index.php/apps/openregister/api/configurations/{id}/check-version
```

Checks the remote source for a newer version and updates the 'remoteVersion' field.

Response:

```json
{
  'localVersion': '1.0.0',
  'remoteVersion': '1.1.0',
  'hasUpdate': true,
  'lastChecked': '2025-01-15T10:30:00Z'
}
```

#### Preview Configuration Changes

```http
GET /index.php/apps/openregister/api/configurations/{id}/preview
```

Generates a preview of changes that would be applied if the remote configuration were imported.

Response:

```json
{
  'metadata': {
    'localVersion': '1.0.0',
    'remoteVersion': '1.1.0',
    'totalChanges': 5
  },
  'registers': [
    {
      'slug': 'myregister',
      'title': 'My Register',
      'action': 'update',
      'current': { 'version': '1.0.0' },
      'proposed': { 'version': '1.1.0' },
      'changes': [
        {
          'field': 'description',
          'current': 'Old description',
          'proposed': 'New description'
        }
      ]
    }
  ],
  'schemas': [...],
  'objects': [...]
}
```

#### Import with Selection

```http
POST /index.php/apps/openregister/api/configurations/{id}/import
Content-Type: application/json

{
  'selection': {
    'registers': ['register1', 'register2'],
    'schemas': ['schema1'],
    'objects': ['register1:schema1:object1']
  }
}
```

Imports only the selected entities from the remote configuration.

Response:

```json
{
  'registersCount': 2,
  'schemasCount': 1,
  'objectsCount': 1,
  'localVersion': '1.1.0'
}
```

#### Export Configuration

```http
GET /index.php/apps/openregister/api/configurations/{id}/export?format=json
```

Exports the configuration with all managed entities following the OpenAPI 3.0 specification with OpenRegister metadata in the `x-openregister` extension.

Query Parameters:

- 'format': 'json' or 'yaml'
- 'includeObjects': 'true' or 'false' (default: false)

Response Structure:

```json
{
  "openapi": "3.0.0",
  "info": {
    "title": "Configuration Title",
    "description": "Configuration Description",
    "version": "1.0.0"
  },
  "x-openregister": {
    "title": "Configuration Title",
    "description": "Configuration Description",
    "type": "app",
    "app": "myapp",
    "version": "1.0.0",
    "sourceType": "local",
    "sourceUrl": null,
    "github": {
      "repo": null,
      "branch": null,
      "path": null
    }
  },
  "components": {
    "registers": {},
    "schemas": {},
    "objects": []
  }
}
```

**Note**: The exported configuration follows the [OpenAPI Extensions specification](https://swagger.io/docs/specification/v3_0/openapi-extensions/) by storing OpenRegister-specific metadata in the `x-openregister` extension field. 

**Excluded properties**: 
- **From Configuration**: System-managed metadata (id, uuid, created, updated, lastChecked, remoteVersion, localVersion), instance-specific settings (autoUpdate, notificationGroups, owner, organisation), and entity reference arrays (registers, schemas, objects, views, agents, sources, applications)
- **From Registers/Schemas/Objects**: id, uuid, organisation (all instance-specific)
- Entity references are tracked automatically during import from the `components` section

### Background Jobs

The system includes a cron job that runs hourly (configurable):

#### Configuration Check Job

Purpose: Check all remote configurations for updates

Behavior:

1. Loads all configurations with remote sources
2. For each configuration, fetches the remote file
3. Compares remote version with local version
4. If auto-update is enabled: Automatically imports the new version
5. If auto-update is disabled: Sends notification to configured groups
6. Updates the 'lastChecked' timestamp

Configuration:

Set the check interval in Nextcloud admin settings (in seconds, 0 to disable):

```php
'openregister.configuration.check_interval' => 3600  // 1 hour
```

### Best Practices

#### Version Management

- Use semantic versioning (MAJOR.MINOR.PATCH)
- Increment PATCH for bug fixes and minor changes
- Increment MINOR for new features (backward compatible)
- Increment MAJOR for breaking changes
- Always update the version in your remote configuration when making changes

#### Source Selection

- Use **Local** for configurations you manage entirely within Nextcloud
- Use **GitHub** when collaborating with others or tracking changes in version control
- Use **URL** for read-only configurations from external sources
- Use **GitLab** if your organization uses GitLab

#### Auto-Update Usage

- Enable for trusted, stable configuration sources
- Disable for production systems where you want to review changes first
- Test configurations in a staging environment before enabling auto-update in production
- Always keep backups before enabling auto-update

#### Notification Strategy

- Add your DevOps team to notification groups for all configurations
- Create specific groups for different configuration types
- Review and apply updates promptly to stay current with remote sources

### Troubleshooting

#### Remote Version Not Updating

**Problem**: The remote version field shows '-' or an old version

**Solution**:

- Click **Actions** → **Check Version** to manually trigger a check
- Verify the source URL is correct and accessible
- Check that the remote file has a 'version' or 'info.version' field
- Review server logs for connection errors

#### Preview Shows No Changes

**Problem**: Preview modal shows no changes even though you expect updates

**Solution**:

- Verify the remote version is actually newer than the local version
- Check that entity slugs match between local and remote configurations
- Ensure the remote configuration uses the same structure

#### Import Fails with Selection

**Problem**: Importing selected entities returns an error

**Solution**:

- Check that all referenced schemas and registers exist
- Verify you have write permissions for the configuration
- Review the preview for any validation errors
- Check server logs for detailed error messages

#### Managed Entity Cannot Be Edited

**Problem**: Edit button is disabled on a schema or register

**Solution**:

- This is expected behavior for managed entities
- To edit, modify the source configuration and re-import
- Alternatively, remove the entity from the configuration to make it editable
- For local configurations, edit through the configuration itself

## Changelog

### Version 0.2.10 (2025-01-16)

- **OAS Compliance**: Moved standard OpenAPI properties (title, description, version) from x-openregister to info section
  - Follows OpenAPI 3.0 specification more closely
  - x-openregister now contains only OpenRegister-specific properties (type, app, sourceType, sourceUrl, github)
  - Backward compatible: import still checks x-openregister if info properties are missing
- **New Method: importFromFilePath()**: Added method for apps to import configurations from file paths
  - OpenRegister handles file reading and JSON parsing
  - Automatically sets sourceUrl for cron job tracking
  - Recommended method for apps with configuration files
- **Configuration Uniqueness**: Configurations are now unique by sourceUrl
  - Added ConfigurationMapper::findBySourceUrl() method
  - Import checks by sourceUrl first (if provided), then by appId
  - Prevents duplicate configurations from the same source
- **File Path Handling**: sourceUrl now stores relative paths from Nextcloud root
  - Example: 'apps-extra/opencatalogi/lib/Settings/publication_register.json'
  - Enables cron job to locate and check configuration files for updates
- **OpenCatalogi Integration**: Updated OpenCatalogi SettingsService to use importFromFilePath()
  - Delegates file reading responsibility to OpenRegister
  - Cleaner separation of concerns between app and OpenRegister
- **Frontend Fix**: Fixed export dialog showing 'No configuration selected for export' error
- Made error message reactive using computed property instead of created hook
- Export dialog now properly detects configuration selection from Actions menu
- Fixed race condition where modal was checking configuration before store update completed
- **Critical Import Fix**: Fixed organisation filter error by passing Register/Schema objects instead of IDs to saveObject()
- This prevents find() lookups that would fail due to organisation filtering in multi-tenancy
- Fixed object import by replacing string slugs with integer IDs in objectData['@self'] before calling saveObject()
- Updated SoftwareCatalog SettingsService to use `importFromApp()` method
- **API Fix**: Changed export route from POST to GET method (was causing 405 errors)
- Updated OpenCatalogi publication_register.json to include x-openregister extension
- **Structure Improvement**: Reorganized x-openregister structure - GitHub properties now nested in 'github' object
- Maintains backward compatibility with flat structure during import (githubRepo, githubBranch, githubPath)

### Version 0.2.9 (2025-01-16)

- **OpenAPI Extensions Support**: Configuration export/import now follows [OpenAPI Extensions specification](https://swagger.io/docs/specification/v3_0/openapi-extensions/)
- Added `x-openregister` extension field for OpenRegister-specific metadata
- **Excluded from export/import**:
  - **Configuration**: System-managed metadata (id, uuid, created, updated, lastChecked, remoteVersion, localVersion), instance-specific settings (autoUpdate, notificationGroups, owner, organisation), entity reference arrays (registers, schemas, objects, views, agents, sources, applications)
  - **Registers/Schemas/Objects**: id, uuid, organisation (all instance-specific)
- **Critical Fix**: Removed organisation field from all exported entities (Configuration, Registers, Schemas, Objects) to prevent cross-instance UUID conflicts
- **Import Resilience**: Import now gracefully handles organisation filtering - when registers/schemas aren't found due to organisation context, they're created as new entities
- Each imported configuration automatically gets the current instance's organisation context
- Cross-instance imports work seamlessly without organisation UUID conflicts
- Backward compatibility maintained: import still supports legacy format without `x-openregister`
- Added `importFromApp()` convenience method for programmatic configuration imports
- Enhanced documentation with OpenAPI extension examples and clear property exclusion list

### Version 0.2.8 (2025-01-15)

- Added Configuration Management System
- Source type support (Local, GitHub, GitLab, URL)
- Version tracking (local and remote versions)
- Configuration preview with change detection
- Selective import functionality
- Auto-update feature with configurable behavior
- Notification system for update alerts
- GitHub integration (push, create PR)
- Managed entity enforcement
- Cron job for automated version checking
- Enhanced configuration modal with Management tab
- API endpoints for version checking, preview, and import
- Full integration tests and unit tests

### Version 0.2.7 (2025-01-01)

- Added register selection in configuration modal
- Added schema selection in configuration modal
- Multi-select dropdowns for easy association management
- Display count of selected items
- Enhanced configuration entity to support arrays of IDs
- Improved configuration editing workflow

