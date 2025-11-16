# Archiving and Metadata Classification - Feature Summary

## Overview

Complete feature documentation created for an **Archiving and Metadata Classification** system that builds on top of the chunk-based text extraction pipeline.

**Status**: 📝 Documentation Complete - **NOT YET IMPLEMENTED**

## What is This Feature?

An intelligent classification and metadata extraction system for all content types (documents, objects, emails, chats) that:

1. **Classifies content** using two approaches:
   - **Constructive**: User selects from curated taxonomy lists
   - **Suggestive**: AI proposes new categories/themes

2. **Extracts metadata** automatically:
   - Keywords and search terms
   - Themes and topics
   - Document properties
   - Temporal information

## Documentation Location

📄 **[Archiving and Metadata Classification](website/docs/features/archiving-and-metadata.md)**

## Key Concepts

### Two Classification Approaches

#### 1. Constructive Classification (Controlled Vocabulary)

```
User Action → Select Taxonomy → Select Category → Apply
```

**Characteristics**:
- Predefined categories
- Controlled vocabulary
- Consistent organization
- Manual or AI-assisted

**Example Taxonomies**:
- Document Types (Contracts, Policies, Reports)
- Content Themes (Technology, Business, HR)
- Records Management (Retention schedules)

#### 2. Suggestive Classification (AI-Powered)

```
AI Analysis → Generate Suggestions → User Review → Approve/Reject
```

**Characteristics**:
- AI-discovered themes
- Dynamic categories
- Requires approval
- Can be promoted to taxonomy

**Example Suggestions**:
- "API Integration" (confidence: 89%)
- "Cloud Security" (confidence: 76%)
- "Performance Optimization" (confidence: 82%)

### Metadata Extraction

Automatic extraction of:

1. **Keywords**: Important terms (TF-IDF, NER, LLM)
2. **Themes**: High-level topics (Topic modeling, clustering)
3. **Search Terms**: How users might search for this content
4. **Properties**: Structured metadata (dates, authors, versions)

## Database Schema

### 4 New Tables

1. **oc_openregister_classifications**
   - Links chunks to taxonomy categories
   - Stores confidence and method
   - Multi-tenant (owner, organisation)

2. **oc_openregister_taxonomies**
   - Stores taxonomy definitions
   - Hierarchical structures
   - Global or organization-specific

3. **oc_openregister_suggestions**
   - AI-generated classification suggestions
   - Pending user review
   - Confidence scores

4. **oc_openregister_metadata**
   - Extracted metadata (keywords, themes, etc.)
   - Linked to chunks and sources
   - Method and confidence tracking

All tables include **multi-tenancy fields** (owner, organisation).

## Integration with Existing Features

### 1. Text Extraction Pipeline

```
File/Object → Chunks → [NEW] Classification + Metadata Extraction
```

Applied after chunking, reuses existing chunk infrastructure.

### 2. GDPR Entity Tracking

Entities can become metadata:
- Person names → Keywords
- Organizations → Themes
- Locations → Properties

### 3. Search Enhancement

Classifications and metadata improve search:
- Filter by category
- Boost by theme relevance
- Faceted navigation
- Related content suggestions

### 4. Vector Search (RAG)

Metadata enhances AI:
- Filter vectors by classification
- Include metadata in context
- Theme-based retrieval

## User Interface Components

### 1. Classification Panel
- Display current classifications
- Add/remove classifications
- Bulk classification
- History tracking

### 2. Suggestion Review Panel
- View pending AI suggestions
- Approve/reject with one click
- Promote to taxonomy
- Bulk actions

### 3. Metadata Display
- Show extracted keywords, themes
- Edit metadata manually
- View confidence scores
- Method transparency

### 4. Taxonomy Manager
- Create/edit taxonomies
- Hierarchical editor
- Import/export
- Global vs organization scope

## API Endpoints

### Classifications
```
GET    /api/classifications
POST   /api/classifications
DELETE /api/classifications/{id}
POST   /api/classifications/bulk
```

### Suggestions
```
GET  /api/suggestions?status=pending
POST /api/suggestions/{id}/review
POST /api/suggestions/bulk-approve
```

### Taxonomies
```
GET    /api/taxonomies
POST   /api/taxonomies
PUT    /api/taxonomies/{id}
DELETE /api/taxonomies/{id}
GET    /api/taxonomies/{id}/export
```

### Metadata
```
GET  /api/metadata?source_id=123
PUT  /api/metadata/{id}
POST /api/metadata/extract
```

## Use Cases

### 1. Legal Document Management
- Classify contracts by type
- Extract parties, dates, jurisdictions
- Apply retention schedules
- Compliance tracking

### 2. Knowledge Base Organization
- AI discovers documentation themes
- Automatic categorization
- Improved search
- Dynamic taxonomy evolution

### 3. Email Archiving
- Classify emails (Business, HR, Legal, IT)
- Extract sender, recipient, subject
- Apply retention policies
- GDPR compliance

### 4. Multi-Language Content
- Language-aware classification
- Localized taxonomies
- Cross-language themes
- Better UX per language

### 5. Research Document Analysis
- Discover research themes
- Extract concepts and keywords
- Cluster similar papers
- Knowledge graph generation

## Multi-Tenancy

**All entities fully support multi-tenancy**:

- `owner` field: User ID
- `organisation` field: Organisation UUID
- Inherited from source content
- Automatic filtering by access rights
- Organization-level taxonomies
- Data isolation guaranteed

## Configuration

Settings panel includes:

### Classification Settings
- Enable constructive/suggestive/both
- Confidence thresholds
- Auto-approve settings
- Suggestion methods

### Metadata Extraction Settings
- Enable/disable by type
- Extraction methods
- Algorithm parameters
- Min confidence scores

### Processing Settings
- On upload vs background
- Batch sizes
- Job intervals
- Manual triggers

## Performance Characteristics

- **Keyword extraction**: 50-200ms per chunk
- **Theme extraction**: 500-2000ms per document (LLM)
- **Classification suggestion**: 200-1000ms per chunk
- **Metadata extraction**: 100-500ms per chunk

### Storage (10,000 documents)
- Classifications: ~6 MB
- Suggestions: ~10 MB
- Metadata: ~10 MB
- Taxonomies: ~250 KB
- **Total**: ~26 MB

## AI/LLM Integration

### Methods Supported

1. **Topic Modeling** (Unsupervised)
   - LDA, NMF algorithms
   - Probability distribution over topics

2. **LLM-Based Analysis** (Supervised)
   - Prompt-based theme extraction
   - Structured output with confidence

3. **Clustering** (Unsupervised)
   - Vector similarity clustering
   - Content grouping

4. **Hybrid** (Recommended)
   - Combine multiple methods
   - Confidence voting
   - Best accuracy

## Future Enhancements

1. **Auto-Classification**: Classify based on similar content
2. **Smart Suggestions**: Learn from user feedback
3. **Cross-Reference**: Link classifications across documents
4. **Visualization**: Knowledge graphs, theme evolution
5. **Export/Import**: Share taxonomies
6. **Templates**: Pre-built taxonomies
7. **Validation Rules**: Ensure consistency
8. **Bulk Operations**: Reclassify multiple items

## Diagrams Included

The documentation includes **7 Mermaid diagrams**:

1. Sources → Classification & Metadata flow (TB)
2. Classification schema class diagram
3. Suggestion workflow sequence diagram
4. Complete processing pipeline flowchart
5. UI mockups (4 panels: Classification, Suggestions, Metadata, Taxonomy Manager)

All fully editable in markdown source.

## Implementation Considerations

### Phase 1: Database Schema
- Create 4 new tables
- Add multi-tenancy fields
- Create indexes

### Phase 2: Classification Service
- Constructive classification logic
- Taxonomy management
- Category assignment

### Phase 3: Suggestion Engine
- AI integration (LLM/clustering)
- Confidence scoring
- Deduplication

### Phase 4: Metadata Extraction
- Keyword extraction (TF-IDF, NER)
- Theme extraction (topic modeling, LLM)
- Search term generation
- Property extraction

### Phase 5: User Interface
- Classification panel
- Suggestion review
- Metadata display
- Taxonomy manager

### Phase 6: API
- All CRUD endpoints
- Bulk operations
- Export/import

### Phase 7: Integration
- Connect to chunk pipeline
- Search enhancement
- RAG context enrichment

### Phase 8: Testing & Deployment
- Unit tests
- Integration tests
- Performance testing
- User acceptance testing

## Security & Compliance

- **Access Control**: User/organization-based
- **Data Isolation**: Multi-tenant safe
- **Audit Trail**: All classification changes logged
- **GDPR**: Metadata can include entity references
- **Approval Workflow**: Admin review for suggestions

## Dependencies

### Existing Features Required
- ✅ Chunk system (text extraction)
- ✅ Multi-tenancy infrastructure
- ✅ Background job system

### New Dependencies
- Topic modeling library (e.g., Gensim)
- TF-IDF implementation
- LLM API access (OpenAI, etc.)
- Clustering algorithms (scikit-learn)

### Optional Integrations
- External taxonomy services
- Knowledge graph systems
- Visualization libraries

## Benefits

### For Users
- ✅ Better content organization
- ✅ Easier discovery
- ✅ Automatic categorization
- ✅ Improved search results

### For Administrators
- ✅ Centralized taxonomy management
- ✅ AI-assisted classification
- ✅ Compliance tracking
- ✅ Usage analytics

### For Organizations
- ✅ Knowledge management
- ✅ Information governance
- ✅ Regulatory compliance
- ✅ Operational efficiency

## Comparison with Entity Tracking

| Feature | Entity Tracking (GDPR) | Classification & Metadata |
|---------|------------------------|---------------------------|
| **Purpose** | Find PII for compliance | Organize and discover content |
| **Focus** | Persons, emails, phones | Categories, themes, keywords |
| **Approach** | Detection (what exists) | Assignment (what it means) |
| **User Input** | Minimal (review) | Active (select categories) |
| **AI Role** | Detection assistant | Suggestion engine |
| **Compliance** | GDPR, privacy laws | Records management |
| **Output** | Entity register | Taxonomy, metadata |

**Complementary**: Both work together for complete content intelligence.

## Documentation Quality

The feature documentation includes:

✅ Complete concept explanation  
✅ Two classification approaches detailed  
✅ Database schema with SQL  
✅ 7 Mermaid diagrams  
✅ UI mockups in ASCII  
✅ Complete API specification  
✅ 5 detailed use cases  
✅ Multi-tenancy fully covered  
✅ Performance characteristics  
✅ Integration points identified  
✅ Implementation phases outlined  
✅ Security and compliance addressed  

## Next Steps

### Before Implementation

1. **Review with Stakeholders**
   - Validate classification approaches
   - Confirm taxonomy requirements
   - Agree on AI methods

2. **Prioritize Features**
   - Constructive vs suggestive first?
   - Which metadata types first?
   - UI vs API priority?

3. **Technical Decisions**
   - LLM provider selection
   - Topic modeling approach
   - Taxonomy storage format

4. **Design Decisions**
   - Default taxonomies to include
   - UI placement and flow
   - Admin vs user capabilities

### Implementation Order

**Recommended**: Implement after text extraction and entity tracking are stable.

**Reason**: Builds on chunk infrastructure, complements entity tracking.

**Timeline**: 8-10 weeks after text extraction completion.

## Questions for Stakeholders

1. Should we prioritize constructive or suggestive classification?
2. What taxonomies are most important (legal, technical, business)?
3. Do you have existing taxonomy standards to import?
4. What LLM provider should we use for suggestions?
5. Should taxonomies be managed centrally or by organization?
6. What metadata is most valuable for your use case?
7. Should we auto-apply high-confidence suggestions (>85%)?
8. How should we handle multi-language taxonomies?

## Conclusion

**Complete feature documentation** has been created for an intelligent archiving and metadata classification system that:

✅ Provides flexible classification (constructive + suggestive)  
✅ Extracts rich metadata automatically  
✅ Fully multi-tenant and secure  
✅ Integrates with existing chunk pipeline  
✅ Enhances search and discovery  
✅ Complements GDPR entity tracking  
✅ Includes complete database schema  
✅ Defines all API endpoints  
✅ Specifies UI components  
✅ Identifies implementation phases  

**Status**: Ready for stakeholder review and prioritization.

**Do NOT implement yet** - this is documentation only for planning purposes.

---

**Related Documentation**:
- [Enhanced Text Extraction](website/docs/features/text-extraction-enhanced.md)
- [Text Extraction Sources](website/docs/features/text-extraction-sources.md)
- [Database Entities](website/docs/technical/text-extraction-entities.md)
- [Implementation Plan](ENHANCED_TEXT_EXTRACTION_IMPLEMENTATION_PLAN.md)



