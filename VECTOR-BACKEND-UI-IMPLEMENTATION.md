# Vector Search Backend Selection - UI Implementation

**Date**: November 12, 2025  
**Status**: ✅ Complete  
**Location**: LLM Configuration Modal

## What Was Added

### New Section in LLM Configuration Modal

Added **"Vector Search Backend"** section that appears **before** the AI Features checkboxes.

### UI Components

```
┌──────────────────────────────────────────────────────────┐
│ Vector Search Backend                                    │
├──────────────────────────────────────────────────────────┤
│ Choose how vector similarity calculations are performed  │
│                                                           │
│ Search Method: [Dropdown ▼]                              │
│   • PHP Cosine Similarity                    🐌 Slow     │
│     Always available, but slow for >500 vectors          │
│                                                           │
│   • MariaDB + pgvector                                   │
│     PostgreSQL with pgvector extension required          │
│     ⚠️ Not available                                     │
│                                                           │
│   • Solr 9+ Dense Vector                     🚀 Very Fast│
│     Very fast distributed vector search                  │
│     ⚠️ Not yet implemented. Coming soon!                 │
│                                                           │
│ (If Solr selected, shows collection & field inputs)      │
└──────────────────────────────────────────────────────────┘
```

## Features Implemented

### 1. **Backend Detection**

- ✅ Automatically detects available backends on modal open
- ✅ Calls `/api/settings/database` to check PostgreSQL + pgvector
- ✅ Shows availability status for each backend
- ✅ Grays out unavailable options

### 2. **Backend Options**

Three backends with visual indicators:

| Backend | Badge | Availability |
|---------|-------|--------------|
| **PHP Cosine** | 🐌 Slow (orange) | Always available |
| **Database** | ⚡ Fast (green) | If PostgreSQL + pgvector detected |
| **Solr 9+** | 🚀 Very Fast (blue) | Coming soon (currently disabled) |

### 3. **Dynamic Configuration**

- **Solr-specific fields** appear when Solr backend selected:
  - Collection selector dropdown
  - Vector field name input (default: `embedding_vector`)
- Fields hidden for other backends

### 4. **Persistence**

- ✅ Loads current backend from LLM settings
- ✅ Saves selected backend with "Save Configuration" button
- ✅ Includes in settings payload:
  - `vectorSearchBackend`: 'php' | 'database' | 'solr'
  - `solrVectorCollection`: collection name
  - `solrVectorField`: field name

### 5. **User Feedback**

- Shows performance note for each backend
- Warns when backend not available
- Visual badges for performance level
- Help text below dropdown

## Code Changes

### Files Modified

**`src/modals/settings/LLMConfigModal.vue`**

1. **Template** (lines 291-349):
   - Added Vector Search Backend section
   - Dropdown with custom option template
   - Conditional Solr configuration inputs

2. **Data Properties** (lines 557-564):
   ```javascript
   loadingBackends: false,
   selectedVectorBackend: null,
   vectorBackendOptions: [],
   solrVectorCollection: null,
   solrCollectionOptions: [],
   solrVectorField: 'embedding_vector',
   ```

3. **Mounted Hook** (line 629):
   ```javascript
   this.loadAvailableBackends()
   ```

4. **Save Configuration** (lines 850-852):
   ```javascript
   vectorSearchBackend: this.selectedVectorBackend?.id || 'php',
   solrVectorCollection: this.solrVectorCollection?.id || this.solrVectorCollection,
   solrVectorField: this.solrVectorField || 'embedding_vector',
   ```

5. **New Method** (lines 927-1001):
   ```javascript
   async loadAvailableBackends()
   ```
   - Fetches database info
   - Builds backend options array
   - Loads current settings
   - Handles errors gracefully

6. **Styles** (lines 1122-1184):
   - `.backend-option` - Option display
   - `.badge` - Performance indicators
   - `.solr-config` - Solr-specific section
   - Responsive and accessible

## How It Works

### On Modal Open

1. **loadAvailableBackends()** is called
2. Fetches `/api/settings/database` for DB info
3. Builds array of 3 backends:
   - PHP (always available)
   - Database (available if pgvector detected)
   - Solr (currently marked as unavailable)
4. Loads current selection from LLM settings
5. Populates dropdown

### User Selects Backend

1. User opens dropdown
2. Sees 3 options with badges and descriptions
3. Unavailable options are grayed out but visible
4. Clicks available option
5. (If Solr) Additional fields appear

### On Save

1. User clicks "Save Configuration"
2. Payload includes vector backend settings
3. Backend endpoint stores settings
4. Success notification shown

## Current State

### What Works Now

- ✅ UI displays in modal
- ✅ PHP backend always available
- ✅ Database backend auto-detected
- ✅ Settings persist
- ✅ Performance badges show
- ✅ Availability checking

### What's Coming Next

- ⏳ Solr 9+ detection implementation
- ⏳ Solr collection listing
- ⏳ Backend switching logic in VectorEmbeddingService
- ⏳ Performance comparison testing

## Testing

### To Test the UI

1. Open Nextcloud
2. Go to Settings → OpenRegister
3. Click "LLM Configuration" button
4. **Scroll down** past AI Features
5. See "Vector Search Backend" section

### Expected Behavior

**Current Setup (MariaDB):**
- ✅ PHP Cosine Similarity - Available, selected by default
- ⚠️ MariaDB + pgvector - Not available (grayed)
- ⚠️ Solr 9+ - Not available (coming soon)

**If you had PostgreSQL + pgvector:**
- ✅ PHP Cosine Similarity - Available
- ✅ PostgreSQL + pgvector - Available, recommended
- ⚠️ Solr 9+ - Not available

## User Benefits

1. **Visibility**: Users see why chat is slow (PHP backend)
2. **Guidance**: Clear path to improve (migrate to PostgreSQL)
3. **Flexibility**: Choose backend based on infrastructure
4. **Transparency**: See what's available vs. what's needed
5. **Future-proof**: Solr option ready when implemented

## Next Steps

To complete the feature:

1. ✅ Add backend selector UI (DONE)
2. ⏳ Implement Solr 9+ detection
3. ⏳ Add collection listing for Solr
4. ⏳ Update VectorEmbeddingService to use selected backend
5. ⏳ Add backend switching logic
6. ⏳ Add migration commands
7. ⏳ Write user documentation

---

**The UI is complete and ready to use!** Users can now see and select vector search backends in the LLM Configuration modal.

