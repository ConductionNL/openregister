# Enhanced Text Extraction Implementation Plan

## Overview

This document outlines the implementation plan for adding entity extraction, language detection, and language level assessment to OpenRegister's text extraction system, including GDPR entity tracking capabilities.

## Documentation Created

1. **[Enhanced Text Extraction & GDPR Entity Tracking](website/docs/features/text-extraction-enhanced.md)**
   - Complete feature documentation
   - Processing methods (local, external services, LLM, hybrid)
   - GDPR entity register design
   - Language detection and assessment
   - Preparing for anonymization
   - API endpoints

2. **[Text Extraction Sources: Files vs Objects](website/docs/features/text-extraction-sources.md)**
   - Visual separation of file and object processing paths
   - Detailed flow diagrams for each source type
   - Comparison and combined use cases
   - Configuration options

3. **[Text Extraction Database Entities](website/docs/technical/text-extraction-entities.md)**
   - Complete database schema
   - Entity relationship diagrams
   - PHP entity classes
   - Migration strategy
   - Performance considerations

## Key Features Added to Documentation

### 1. Two Processing Paths

#### File Path
```
File Upload → Text Extraction (LLPhant/Dolphin) → Complete Text → Chunks
```

#### Object Path
```
Object Creation → Property Values → Text Blob → Chunks
```

Both paths converge at chunks, which can then undergo:
- Text search indexing (Solr)
- Vector embeddings (RAG)
- Entity extraction (GDPR)
- Language detection
- Language level assessment

### 2. GDPR Entity Register

**Two new entities**:

1. **Entity**: Stores unique entities (persons, emails, organizations)
   - UUID, type, value, category
   - Detection timestamp and metadata
   - Supports deduplication

2. **EntityRelation**: Links entities to chunk positions
   - Entity ID + Chunk ID
   - Precise character positions
   - Confidence score and detection method
   - Anonymization tracking
   - Context for verification

**Prepared for anonymization**:
- Precise position tracking
- Consistent replacement values
- Reversible anonymization
- Metadata preservation

### 3. Language Detection & Assessment

**Chunk entity enhancements**:
- `language` field: ISO 639-1 codes (e.g., 'en', 'nl', 'de')
- `language_level` field: Reading level (e.g., 'B2', 'Grade 8', '65')
- `language_confidence` field: Detection confidence (0.0-1.0)
- `detection_method` field: How it was detected

**Use cases**:
- Multi-language content management
- Accessibility compliance (plain language)
- Content routing by language
- Readability assessment

### 4. Multiple Processing Methods

All enhancements support three methods:

1. **Local Algorithms**: Fast, privacy-friendly, no external deps
2. **External Services**: Specialized APIs (Presidio, NLDocs, Dolphin)
3. **LLM Processing**: Context-aware, handles ambiguity
4. **Hybrid (Recommended)**: Multiple methods with confidence scoring

### 5. Extended Chunking Support

**Email chunking**:
- Segment by headers, body, signature, attachments
- Preserve sender/recipient as entities
- Link chunks to email threads

**Chat message chunking**:
- Process individual messages with context
- Track conversation participants as entities
- Maintain threading
- Include previous messages for coherent search

## Database Changes Required

### New Tables

1. **oc_openregister_object_texts**: Text blobs from objects
2. **oc_openregister_chunks**: Individual chunks (migrated from chunks_json)
3. **oc_openregister_entities**: GDPR entity register
4. **oc_openregister_entity_relations**: Entity-to-chunk mappings

### Updated Tables

**oc_openregister_file_texts**: No changes required (already has chunks_json)

Future migration will move chunks from JSON to dedicated table for better querying.

## Implementation Phases

### Phase 1: Database Schema (Week 1)

**Tasks**:
1. Create migration for new tables
2. Create PHP entity classes
3. Create mapper classes
4. Add unit tests for entities

**Deliverables**:
- `lib/Migration/Version1DateXXXXXXXX.php`
- `lib/Db/ObjectText.php`
- `lib/Db/Chunk.php`
- `lib/Db/GdprEntity.php`
- `lib/Db/EntityRelation.php`
- Corresponding mapper classes

### Phase 2: Object Text Extraction (Week 2)

**Tasks**:
1. Create ObjectTextExtractionService
2. Integrate with SaveObject event
3. Property value concatenation logic
4. Chunking for objects
5. Add configuration settings

**Deliverables**:
- `lib/Service/ObjectTextExtractionService.php`
- Integration with existing SaveObject flow
- Settings UI for object extraction
- Unit tests

### Phase 3: Chunk Migration (Week 3)

**Tasks**:
1. Create ChunkService
2. Migrate FileText chunks_json to Chunk table
3. Background job for migration
4. Update services to use Chunk entity
5. Maintain backward compatibility

**Deliverables**:
- `lib/Service/ChunkService.php`
- `lib/BackgroundJob/MigrateChunksJob.php`
- Updated TextExtractionService
- Migration status tracking

### Phase 4: Language Detection (Week 4)

**Tasks**:
1. Create LanguageDetectionService
2. Implement local algorithm (lingua or similar)
3. Implement API integration (optional)
4. Implement LLM integration (optional)
5. Add background job for batch processing
6. Add configuration UI

**Deliverables**:
- `lib/Service/LanguageDetectionService.php`
- `lib/BackgroundJob/DetectLanguageJob.php`
- Settings UI for language detection
- Unit tests

### Phase 5: Language Level Assessment (Week 5)

**Tasks**:
1. Create LanguageLevelService
2. Implement readability formulas (Flesch-Kincaid, etc.)
3. Implement API integration (optional)
4. Implement LLM integration (optional)
5. Add background job
6. Add configuration UI

**Deliverables**:
- `lib/Service/LanguageLevelService.php`
- `lib/BackgroundJob/AssessLanguageLevelJob.php`
- Settings UI for level assessment
- Unit tests

### Phase 6: Entity Extraction (Week 6-7)

**Tasks**:
1. Create EntityExtractionService
2. Implement regex patterns (local)
3. Implement Presidio integration (optional)
4. Implement LLM integration (optional)
5. Entity deduplication logic
6. EntityRelation creation
7. Background job for batch processing
8. Add configuration UI

**Deliverables**:
- `lib/Service/EntityExtractionService.php`
- `lib/BackgroundJob/ExtractEntitiesJob.php`
- Settings UI for entity extraction
- Unit tests

### Phase 7: GDPR Register UI (Week 8)

**Tasks**:
1. Create EntityController
2. Create Vue components for entity list
3. Entity details view
4. Occurrence list
5. GDPR report generation
6. Export functionality
7. Search and filtering

**Deliverables**:
- `lib/Controller/EntityController.php`
- `src/views/gdpr/EntitiesIndex.vue`
- `src/views/gdpr/EntityDetails.vue`
- `src/modals/gdpr/GdprReportModal.vue`
- API endpoints

### Phase 8: Email & Chat Chunking (Week 9)

**Tasks**:
1. Create EmailChunkingService
2. Create ChatChunkingService
3. Integration with Mail app (if available)
4. Integration with Talk app (if available)
5. Special handling for email metadata
6. Conversation threading

**Deliverables**:
- `lib/Service/EmailChunkingService.php`
- `lib/Service/ChatChunkingService.php`
- Event listeners for Mail/Talk
- Unit tests

### Phase 9: Testing & Documentation (Week 10)

**Tasks**:
1. Integration tests for all services
2. Performance testing
3. API documentation updates
4. User documentation updates
5. Admin guide for GDPR features
6. Video tutorials (optional)

**Deliverables**:
- Full test coverage
- Updated API documentation
- User guides
- Admin documentation

### Phase 10: Deployment & Monitoring (Week 11)

**Tasks**:
1. Beta deployment
2. Monitor background jobs
3. Performance tuning
4. Bug fixes
5. Collect user feedback
6. Production deployment

## Configuration Structure

### Settings → OpenRegister → Text Analysis

```
┌─ Text Extraction ─────────────────────────────┐
│ ☑ Enable Object Text Extraction              │
│ ☑ Enable File Text Extraction                │
│                                                │
│ Chunking Strategy: [Recursive ▼]              │
│ Chunk Size: [1000] characters                 │
│ Chunk Overlap: [200] characters               │
└────────────────────────────────────────────────┘

┌─ Language Detection ──────────────────────────┐
│ ☑ Enable Language Detection                   │
│                                                │
│ Detection Method: [Hybrid ▼]                  │
│   • Local Algorithm                           │
│   • External API (optional)                   │
│   • LLM (optional)                            │
│                                                │
│ Confidence Threshold: [0.70] (0.0-1.0)        │
└────────────────────────────────────────────────┘

┌─ Language Level Assessment ───────────────────┐
│ ☑ Enable Language Level Assessment            │
│                                                │
│ Assessment Method: [Formula ▼]                │
│ Scale: [CEFR ▼]                               │
└────────────────────────────────────────────────┘

┌─ Entity Extraction (GDPR) ────────────────────┐
│ ☑ Enable Entity Extraction                    │
│                                                │
│ Extraction Method: [Hybrid ▼]                 │
│   • Local Patterns: ☑ Enabled                 │
│   • Presidio API: ☐ Enabled (API key req.)    │
│   • LLM: ☑ Enabled                            │
│                                                │
│ Entity Types to Detect:                       │
│   ☑ Persons                                   │
│   ☑ Email Addresses                           │
│   ☑ Phone Numbers                             │
│   ☑ Organizations                             │
│   ☑ Locations                                 │
│   ☑ Dates of Birth                            │
│   ☐ ID Numbers                                │
│   ☐ Bank Accounts                             │
│   ☐ IP Addresses                              │
│                                                │
│ Confidence Threshold: [0.80] (0.0-1.0)        │
│ Context Window: [100] characters              │
│                                                │
│ [View GDPR Register] [Generate Report]        │
└────────────────────────────────────────────────┘

┌─ Vector Embeddings (RAG) ─────────────────────┐
│ ☑ Enable Vectorization                        │
│                                                │
│ Embedding Model: [OpenAI text-embedding-3 ▼]  │
│ Vector Backend: [Solr ▼]                      │
└────────────────────────────────────────────────┘

┌─ Processing ──────────────────────────────────┐
│ Background Job Interval: [5] minutes          │
│ Batch Size: [100] chunks per job              │
│                                                │
│ [Process Pending Chunks Now]                  │
│ [Reprocess All Chunks]                        │
└────────────────────────────────────────────────┘

┌─ Statistics ──────────────────────────────────┐
│ Total Chunks: 145,782                         │
│ Languages Detected: 8                         │
│ Entities Found: 2,341                         │
│ Pending Processing: 234                       │
│                                                │
│ Top Languages:                                │
│   • English: 98,452 chunks (67.5%)            │
│   • Dutch: 42,119 chunks (28.9%)              │
│   • German: 5,211 chunks (3.6%)               │
└────────────────────────────────────────────────┘
```

## API Endpoints

### Chunks

```
GET  /api/chunks
GET  /api/chunks/{id}
POST /api/chunks/{id}/analyze
GET  /api/chunks/languages
GET  /api/chunks/levels
POST /api/chunks/batch-analyze
```

### Entities (GDPR)

```
GET  /api/entities
GET  /api/entities/{id}
GET  /api/entities/{id}/occurrences
POST /api/entities/{id}/anonymize
GET  /api/gdpr/report
POST /api/gdpr/export
```

### Object Text

```
GET  /api/object-texts
GET  /api/object-texts/{id}
POST /api/objects/{id}/extract-text
```

## Service Architecture

```
TextExtractionService (existing)
  ├─ FileTextExtractionService (existing)
  └─ ObjectTextExtractionService (new)

ChunkService (new)
  ├─ createChunksFromFile()
  ├─ createChunksFromObject()
  ├─ migrateFromJson()
  └─ getChunksBySource()

EnhancementService (new)
  ├─ LanguageDetectionService
  │   ├─ detectLocal()
  │   ├─ detectApi()
  │   └─ detectLlm()
  ├─ LanguageLevelService
  │   ├─ assessFormula()
  │   ├─ assessApi()
  │   └─ assessLlm()
  └─ EntityExtractionService
      ├─ extractLocal()
      ├─ extractPresidio()
      ├─ extractLlm()
      └─ createEntityRelations()

GdprService (new)
  ├─ generateReport()
  ├─ findEntityOccurrences()
  ├─ anonymizeEntity()
  └─ exportGdprData()
```

## Background Jobs

1. **MigrateChunksJob**: Migrate chunks from JSON to table
2. **ProcessChunksJob**: Apply enhancements to pending chunks
3. **DetectLanguageJob**: Batch language detection
4. **AssessLanguageLevelJob**: Batch level assessment
5. **ExtractEntitiesJob**: Batch entity extraction
6. **UpdateEntityStatsJob**: Update entity occurrence counts

## Performance Targets

- **Object text extraction**: <100ms per object
- **Chunk creation**: <50ms per 100KB text
- **Language detection (local)**: <10ms per chunk
- **Language level (formula)**: <20ms per chunk
- **Entity extraction (local)**: <100ms per chunk
- **GDPR report generation**: <5s for 10,000 entities

## Testing Strategy

1. **Unit Tests**: All services and entities
2. **Integration Tests**: End-to-end flows
3. **Performance Tests**: Background job processing
4. **Load Tests**: 10,000+ files and objects
5. **API Tests**: All endpoints
6. **UI Tests**: GDPR register interface

## Security Considerations

1. **Access Control**: GDPR register admin-only
2. **Encryption**: Entities encrypted at rest
3. **Audit Trail**: Log all entity access
4. **Data Minimization**: Only extract necessary entities
5. **Retention**: Configurable entity retention periods
6. **Export**: Secure GDPR data export

## Compliance

- **GDPR**: Complete entity tracking for data subject requests
- **Right to Erasure**: Prepared for anonymization
- **Data Mapping**: Know where all PII exists
- **Audit Trail**: Complete access logging
- **Retention**: Configurable data retention

## Next Steps

1. Review this implementation plan with stakeholders
2. Prioritize phases based on business needs
3. Allocate resources (developers, QA, etc.)
4. Set up development environment
5. Create feature branch
6. Begin Phase 1 implementation

## Questions for Stakeholders

1. Which entity types are most important for initial release?
2. Should we integrate with external services (Presidio, etc.) or start with local only?
3. What is the target timeline for GDPR compliance?
4. Are there specific languages to prioritize for detection?
5. Should email/chat chunking be in first release or later?
6. What is the performance budget for background job processing?
7. Are there existing GDPR workflows to integrate with?

## Success Metrics

- **Coverage**: 100% of files and objects chunked
- **Accuracy**: >90% entity detection accuracy
- **Performance**: <5min to process 1000 files
- **Adoption**: GDPR register used for data subject requests
- **Compliance**: Pass GDPR audit
- **User Satisfaction**: Positive feedback on search quality

## Conclusion

This enhanced text extraction system provides OpenRegister with:

✅ Unified processing for files and objects  
✅ GDPR compliance with entity tracking  
✅ Language detection and assessment  
✅ Prepared for anonymization  
✅ Extended support for emails and chats  
✅ Flexible processing methods (local, API, LLM)  
✅ Comprehensive documentation  
✅ Clear implementation roadmap  

The system is designed to be implemented incrementally, with each phase delivering value independently while building toward the complete feature set.



