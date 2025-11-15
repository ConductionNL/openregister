# Database Status Tile Feature

**Date**: November 12, 2025  
**Feature**: Added database status tile to LLM settings page  
**Purpose**: Display database type and vector search capability to help users optimize performance

## What Was Added

### 1. Backend Endpoint

**File**: `lib/Controller/SettingsController.php`

Added new endpoint: `GET /api/settings/database`

```php
public function getDatabaseInfo(): JSONResponse
```

**Capabilities**:
- Detects database type (MariaDB, MySQL, PostgreSQL, SQLite)
- Checks database version
- Detects if pgvector extension is installed (PostgreSQL)
- Provides performance recommendations
- Shows whether native vector operations are supported

**Response Example** (MariaDB):
```json
{
  "success": true,
  "database": {
    "type": "MariaDB",
    "version": "10.6.23",
    "platform": "mysql",
    "vectorSupport": false,
    "recommendedPlugin": "pgvector for PostgreSQL",
    "performanceNote": "Current: Similarity calculated in PHP (slow). Recommended: Migrate to PostgreSQL + pgvector for 10-100x speedup."
  }
}
```

**Response Example** (PostgreSQL with pgvector):
```json
{
  "success": true,
  "database": {
    "type": "PostgreSQL",
    "version": "16.1",
    "platform": "postgres",
    "vectorSupport": true,
    "recommendedPlugin": "pgvector (installed ✓)",
    "performanceNote": "Optimal: Using database-level vector operations for fast semantic search."
  }
}
```

### 2. Route Configuration

**File**: `appinfo/routes.php`

Added route:
```php
['name' => 'settings#getDatabaseInfo', 'url' => '/api/settings/database', 'verb' => 'GET'],
```

### 3. Frontend Component

**File**: `src/views/settings/sections/LlmConfiguration.vue`

#### Data Properties
```javascript
databaseInfo: {
  type: 'Unknown',
  version: 'Unknown',
  vectorSupport: false,
  recommendedPlugin: null,
  performanceNote: null,
}
```

#### Methods
```javascript
async loadDatabaseInfo() {
  // Fetches database info from API
  // Called automatically on component mount
}
```

#### UI Changes
- **Added third card** to provider-info-grid
- Shows database type and version
- Displays recommended plugin (pgvector)
- Shows performance note with tooltip
- **Warning styling** when vector support is missing

#### Template Structure
```vue
<div class="provider-info-grid">
  <!-- Card 1: Embedding Provider -->
  <!-- Card 2: Chat Provider (RAG) -->
  <!-- Card 3: Database Service (NEW) -->
</div>
```

### 4. Styling

**Changes**:
- Grid changed from 2 columns to 3 columns
- Added `.warning-card` class with orange border/background
- Added `.warning-text` class (orange color)
- Added `.success-text` class (green color)
- Added `.plugin-info` and `.performance-note` styles
- Responsive: 3 cols desktop, 2 cols tablet, 1 col mobile

## User Experience

### What Users See

**When using MariaDB/MySQL** (current state):
```
┌────────────────────┬────────────────────┬────────────────────┐
│ Embedding Provider │ Chat Provider (RAG)│ Database Service   │
├────────────────────┼────────────────────┼────────────────────┤
│ Ollama             │ Ollama             │ MariaDB ⚠️         │
│ mistral:7b         │ mistral:7b         │ 10.6.23            │
│                    │                    │ pgvector for       │
│                    │                    │ PostgreSQL         │
│                    │                    │ Current: PHP (slow)│
└────────────────────┴────────────────────┴────────────────────┘
```

- Database card has **orange border** (warning)
- Plugin text is **orange** (warning)
- Performance note truncated with ellipsis
- Hover over performance note shows full text

**When using PostgreSQL + pgvector**:
```
┌────────────────────┬────────────────────┬────────────────────┐
│ Embedding Provider │ Chat Provider (RAG)│ Database Service   │
├────────────────────┼────────────────────┼────────────────────┤
│ Ollama             │ Ollama             │ PostgreSQL ✓       │
│ phi3:mini          │ phi3:mini          │ 16.1               │
│                    │                    │ pgvector (installed│
│                    │                    │ ✓)                 │
│                    │                    │ Optimal: Database  │
│                    │                    │ vector ops         │
└────────────────────┴────────────────────┴────────────────────┘
```

- Database card has **normal styling**
- Plugin text is **green** (success)
- Performance note shows "Optimal"

## Benefits

1. **Visibility**: Users immediately see if they have optimal vector search setup
2. **Guidance**: Clear recommendation to migrate to PostgreSQL + pgvector
3. **Performance Awareness**: Links slow performance to database limitation
4. **Actionable**: Users know what to do (install pgvector or migrate)
5. **Status At-A-Glance**: See full AI stack configuration in one view

## Technical Details

### Database Detection Logic

1. **MariaDB**: Checks `VERSION()` for "MariaDB" string
2. **MySQL**: Checks `VERSION()` without "MariaDB"
3. **PostgreSQL**: Checks platform name + queries `pg_extension`
4. **SQLite**: Checks platform name

### Vector Support Check

- **MariaDB/MySQL**: Always `false` (no native vector support)
- **PostgreSQL**: Queries `pg_extension` table for `vector` extension
- **SQLite**: Always `false` (not recommended)

### Performance Notes

| Database | Vector Support | Performance Note |
|----------|----------------|------------------|
| **MariaDB** | ✗ | PHP similarity (slow) → migrate to PostgreSQL |
| **MySQL** | ✗ | PHP similarity (slow) → migrate to PostgreSQL |
| **PostgreSQL (no pgvector)** | ✗ | Install: `CREATE EXTENSION vector;` |
| **PostgreSQL (with pgvector)** | ✓ | Optimal: Database-level vector ops |
| **SQLite** | ✗ | Not recommended for production |

## Related Documentation

- **Performance Analysis**: `VECTOR-SEARCH-PERFORMANCE.md`
- **Ollama GPU Setup**: `GPU-SETUP-SUMMARY.md`
- **PostgreSQL Migration Guide**: (TODO - to be created)

## Testing

### Manual Testing

1. **View the tile**:
   ```
   Navigate to Settings → OpenRegister → LLM Configuration
   ```

2. **Check API response**:
   ```bash
   curl -u admin:admin \
     http://nextcloud.local/index.php/apps/openregister/api/settings/database
   ```

3. **Test with different databases**:
   - MariaDB: Should show warning
   - PostgreSQL without pgvector: Should show install instruction
   - PostgreSQL with pgvector: Should show success

### Expected Behavior

- ✅ Tile appears next to Embedding and Chat tiles
- ✅ Shows correct database type and version
- ✅ Warning styling for MariaDB/MySQL
- ✅ Success styling for PostgreSQL + pgvector
- ✅ Performance note truncates with ellipsis
- ✅ Tooltip shows full performance note on hover
- ✅ Responsive: adapts to mobile/tablet/desktop

## Future Enhancements

1. **Migration Wizard**: Add button to start PostgreSQL migration
2. **Performance Metrics**: Show actual query times comparison
3. **Auto-Detection**: Suggest migration when vector count > 1000
4. **Installation Helper**: Guide users through pgvector installation
5. **Health Score**: Overall AI stack health indicator

---

**Status**: ✅ **Complete and Ready for Testing**

The database status tile provides immediate visibility into vector search performance limitations and guides users toward optimal configuration.

