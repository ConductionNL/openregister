# Views: Reusable Query Filters

## Overview

Views in OpenRegister are **reusable query filters** that define search constraints. They allow you to save query parameters (registers, schemas, filters) and reuse them as search filters. Views are designed to be the foundation for exposing filtered data access through API endpoints.

## What is a View?

A View is a **query filter definition** that includes:

- **Registers and Schemas**: Which registers and schemas to search in (supports multiple) - defines the data scope
- **Search Terms**: Default search keywords
- **Facet Filters**: Applied facet filters (category, status, etc.)
- **Enabled Facets**: Which facets to make available
- **Data Source**: Whether to search in database, SOLR index, or auto-select

## What Views Are NOT

Views do **NOT** store UI preferences like:
- ❌ Column visibility and order
- ❌ Pagination settings  
- ❌ Sorting preferences
- ❌ Search results

These are session-specific and should be configured each time you use a view.

## Key Concept: Views as Constraints

Views define **boundaries** for data access. When you or others query a view:

- ✅ **Can** query with fewer registers/schemas than the view defines
- ❌ **Cannot** query registers/schemas outside the view's definition
- ✅ **Can** add additional search terms
- ✅ **Can** apply additional facet filters

**Example:** A view defines registers `[1, 2, 3]`
- ✅ User can query register `[1]` or `[1, 2]`
- ❌ User cannot query register `[4]`

This makes views perfect for **controlled data access** in API endpoints.

## Creating a View

### From the Search Sidebar

1. Configure your **query constraints**:
   - Select one or more registers (defines data scope)
   - Select one or more schemas (defines object types)
   - Add search terms (optional default filters)
   - Apply facet filters (optional default filters)
   - Choose data source (database/index/auto)

2. Navigate to the **Search** tab in the sidebar

3. Click **'Save current search as view'** button

4. Enter:
   - **Name**: A descriptive name for your view (required)

5. Click **'Save'**

The view is now saved and appears in the **Views** tab.

### Example Use Cases

**Publication Search View**
- Name: 'Published Articles 2024'
- Registers: Publications Register
- Schemas: Article, Report
- Filters: published=true, year=2024
- Description: 'All published articles and reports from 2024'

**Multi-Register Inventory View**
- Name: 'Complete Inventory'
- Registers: Products, Assets, Resources
- Schemas: Physical Item, Digital Asset
- Description: 'Cross-register view of all inventory items'

## Loading a View

1. Open the search sidebar
2. Navigate to the **Views** tab
3. Browse or search for a view
4. Click the **magnify icon** (🔍) to load the view
5. The query constraints will be automatically applied in the Search tab

Views are displayed with:
- **View Name and Description**
- **Register and Schema counts**
- **Star icon** for favorites (at the top of the list)
- **Action icons** for load, edit, and delete

### Visual Feedback for Active Views

When a view is active (currently loaded), you will see clear visual indicators:
- **Green border**: The view card gets a 2px green border, similar to how active organizations are displayed
- **Primary magnify button**: The magnify icon button becomes highlighted in blue to indicate the view is currently loaded
- **Light green background**: The entire view card has a subtle green tint

Additionally, when a view is favorited:
- **Primary star button**: The star button becomes highlighted in blue and shows a filled star icon

These visual cues make it easy to see at a glance which view is currently active and which views are favorited.

## Favoriting Views

Mark frequently-used views as favorites for quick access:

1. Navigate to the **Views** tab
2. Find the view you want to favorite
3. Click the **star icon** (⭐)
4. The view moves to the top of the list

**Favorited views** appear first in the Views tab, making your most-used filters easily accessible.

## Managing Views

### Updating a View

1. Navigate to the **Views** tab
2. Find the view you want to update
3. Click the **pencil icon** (✏️) to edit
4. Update the name and/or description
5. Click **'Save'**

**Note:** You can only update views you own. To change query parameters, save a new view with the updated constraints.

### Deleting a View

1. Navigate to the **Views** tab
2. Find the view you want to delete
3. Click the **delete icon** (🗑️)
4. Confirm the deletion

**Note**: You can only delete views you own.

## Sharing Views

### Public Views

When you mark a view as public, other users in your organization can:
- See the view in their 'Public Views' list
- Load and use the view configuration
- **Cannot** edit or delete your view

### Private Views

Private views (default) are only visible to you.

## View Configuration Details

### What Gets Saved in a View

✅ **Query Parameters (Saved):**
- **registers**: Array of register IDs to search within
- **schemas**: Array of schema IDs to filter by
- **searchTerms**: Array of default search keywords
- **facetFilters**: Object of applied facet filters (e.g., `{category: ['articles'], status: ['published']}`)
- **enabledFacets**: Object of which facets to enable (e.g., `{category: true, status: true}`)
- **source**: Data source ('auto', 'database', or 'index')

❌ **UI Preferences (Not Saved):**
- Pagination settings (page, limit)
- Sort configuration (sort field, order)
- Column visibility and order
- Current search results
- Session-specific data

**Why this separation?** Views are designed to be query filters that can be exposed as API endpoints. UI preferences are personal and session-specific.

### Data Sources

Views can specify which data source to use:

- **🤖 Auto (Intelligent)**: Automatically selects the best source based on your search
- **Database**: Search directly in the MySQL database (exact matching)
- **SOLR Index**: Search in the SOLR search engine (full-text, facets, fast)

## Advanced Features

### Cross-Register Searching

Views enable powerful cross-register searches:

```
Example: 'Customer Orders View'
Registers: [Customers, Orders, Products]
Schemas: [Customer, Order, Product Details]
```

This allows you to define a query scope across multiple registers, perfect for creating unified API endpoints.

### Multiple Schema Selection

Select multiple schemas within or across registers:

```
Example: 'All Publications View'
Register: Publications
Schemas: [Article, Report, Book, Thesis, Conference Paper]
```

### Future: Views as API Endpoints

**Coming Soon:** Views will be exposable as REST API endpoints, allowing external applications to query your data within the view's constraints. This makes views perfect for:

- **Partner integrations** - give partners access to specific data subsets
- **Public APIs** - expose read-only data to the public
- **Microservices** - let other services query predefined data scopes
- **Mobile apps** - provide filtered endpoints for mobile applications

Example future usage:
```bash
GET /api/views/{view-uuid}/query?search=keyword&page=1
```

This endpoint would respect the view's register/schema constraints while allowing users to add search terms and filters within those bounds.

## Tips and Best Practices

### 1. Use Descriptive Names
- ✅ Good: 'Q4 2024 Sales Reports'
- ❌ Bad: 'View 1'

### 2. Add Descriptions
Help yourself and others understand what the view is for:
- What data it shows
- Why it was created
- When to use it

### 3. Create Role-Based Views
Create and share views for specific roles:
- 'Manager Dashboard' (public)
- 'Data Entry Queue' (public)
- 'My Drafts' (private)

### 4. Keep Views Focused
Create multiple specific views rather than one overly complex view:
- 'Published Articles' (specific)
- 'Draft Articles' (specific)
- Instead of: 'All Articles' (too broad)

### 5. Regular Maintenance
- Delete views you no longer use
- Update view descriptions when usage changes
- Review public views periodically

## Limitations

1. **Performance**: Searching across many registers/schemas may be slower
2. **Permissions**: Views respect object-level permissions (users may see different results)
3. **Schema Changes**: If a schema is deleted, views using it will show an error
4. **Register Changes**: If a register is deleted, views using it will show an error

## Troubleshooting

### 'View Not Found' Error
- The view may have been deleted by its owner
- You may have lost access permissions
- Try refreshing the views list

### 'No Results' When Loading View
- The view configuration may reference deleted registers/schemas
- Check if you have permission to access the data
- Verify filters are not too restrictive

### View Won't Save
- Ensure you have entered a name
- Check that you have selected at least one register or schema
- Verify you are authenticated

### Can't See Public Views
- Public views may not be available in your organization
- Check with your administrator about view sharing settings

## API Usage

For developers integrating with the Views API:

### List Views
```bash
GET /api/views
```

Returns all views accessible to the authenticated user (owned + public).

### Get Specific View
```bash
GET /api/views/{id}
```

Returns a single view by ID or UUID.

### Create View
```bash
POST /api/views
Content-Type: application/json

{
  'name': 'My View',
  'description': 'Optional description',
  'isPublic': false,
  'isDefault': false,
  'query': {
    'registers': [1, 2],
    'schemas': [3, 4],
    'searchTerms': ['keyword1', 'keyword2'],
    'facetFilters': {
      'category': ['articles'],
      'status': ['published']
    },
    'enabledFacets': {
      'category': true,
      'status': true
    },
    'source': 'auto'
  }
}
```

**Note:** For backwards compatibility, you can also send 'configuration' instead of 'query'. The backend will extract only the query parameters.

### Update View
```bash
PUT /api/views/{id}
Content-Type: application/json

{
  'name': 'Updated Name',
  'description': 'Updated description',
  'isPublic': true,
  'isDefault': false,
  'query': {
    'registers': [1, 2, 3],
    'schemas': [4, 5]
  }
}
```

### Toggle Favorite
```bash
POST /api/views/{id}/favorite
Content-Type: application/json

{
  'favor': true
}
```

Set 'favor' to 'true' to add to favorites, 'false' to remove.

### Delete View
```bash
DELETE /api/views/{id}
```

Only the owner can delete a view.

## Related Documentation

- [Search Guide](../user-guide/searching.md)
- [Objects API](../api/objects.md)
- [Registers and Schemas](../concepts/registers-schemas.md)
- [Faceted Search](./faceted-search.md)

