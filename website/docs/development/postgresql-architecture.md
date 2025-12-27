---
id: postgresql-architecture
title: PostgreSQL Architecture Diagram
sidebar_label: Architecture
---

# OpenRegister PostgreSQL Architecture

## System Overview

```mermaid
graph TB
    subgraph "Client Layer"
        A[Web Browser]
        B[API Client]
        C[Mobile App]
    end
    
    subgraph "Application Layer"
        D[Nextcloud + OpenRegister]
        E[Search Service]
        F[Object Service]
        G[AI Service]
    end
    
    subgraph "PostgreSQL Database"
        H[(PostgreSQL 16)]
        I[pgvector Extension]
        J[pg_trgm Extension]
        K[Objects Table]
        L[File Chunks Table]
        M[Vector Indexes]
        N[Trigram Indexes]
    end
    
    subgraph "AI/ML Services"
        O[Ollama/OpenAI]
        P[Presidio Analyzer]
    end
    
    A --> D
    B --> D
    C --> D
    
    D --> E
    D --> F
    D --> G
    
    E --> H
    F --> H
    G --> O
    G --> P
    
    H --> I
    H --> J
    H --> K
    H --> L
    
    I --> M
    J --> N
    
    K --> M
    L --> M
    K --> N
    
    O -.Generate Embeddings.-> G
    G -.Store Vectors.-> K
    G -.Store Vectors.-> L
    
    style H fill:#d4edda
    style I fill:#e1f5ff
    style J fill:#e1f5ff
    style M fill:#fff3cd
    style N fill:#fff3cd
```

## Search Flow

```mermaid
sequenceDiagram
    participant User
    participant API
    participant SearchService
    participant PostgreSQL
    participant pgvector
    participant pg_trgm
    participant AI
    
    User->>API: Search Query
    
    alt Semantic Search
        API->>AI: Generate Embedding
        AI-->>API: Vector [1536 dims]
        API->>SearchService: Vector Search Request
        SearchService->>PostgreSQL: SELECT ... ORDER BY embedding <=> vector
        PostgreSQL->>pgvector: Calculate Cosine Distances
        pgvector-->>PostgreSQL: Sorted Results
        PostgreSQL-->>SearchService: Top Matches
    else Text Search
        API->>SearchService: Text Search Request
        SearchService->>PostgreSQL: SELECT ... WHERE text % query
        PostgreSQL->>pg_trgm: Calculate Similarities
        pg_trgm-->>PostgreSQL: Scored Results
        PostgreSQL-->>SearchService: Top Matches
    else Hybrid Search
        par Vector Search
            API->>AI: Generate Embedding
            AI-->>API: Vector
            SearchService->>pgvector: Vector Search
            pgvector-->>SearchService: Vector Results
        and Text Search
            SearchService->>pg_trgm: Text Search
            pg_trgm-->>SearchService: Text Results
        end
        SearchService->>SearchService: Merge & Re-rank
    end
    
    SearchService-->>API: Final Results
    API-->>User: Search Results
```

## Data Indexing Flow

```mermaid
flowchart TB
    A[Object Created/Updated] --> B{Needs Vectorization?}
    B -->|Yes| C[Extract Text Content]
    B -->|No| D[Save to Database]
    
    C --> E[Generate Embedding]
    E --> F[AI Service]
    F --> G{Model Type}
    
    G -->|OpenAI| H[OpenAI API]
    G -->|Ollama| I[Local Ollama]
    G -->|Fireworks| J[Fireworks API]
    
    H --> K[Vector 1536 dims]
    I --> K
    J --> K
    
    K --> L[Store in PostgreSQL]
    L --> M[pgvector Column]
    
    D --> N[PostgreSQL Objects Table]
    M --> N
    
    N --> O[Create/Update Indexes]
    O --> P[IVFFlat Vector Index]
    O --> Q[GIN Trigram Index]
    
    style M fill:#e1f5ff
    style P fill:#fff3cd
    style Q fill:#fff3cd
```

## Database Schema

```mermaid
erDiagram
    OBJECTS ||--o{ FILE_CHUNKS : contains
    OBJECTS {
        uuid id PK
        string title
        text description
        json properties
        vector_1536 embedding "pgvector"
        timestamp created
        timestamp updated
        uuid schema_id FK
    }
    
    FILE_CHUNKS {
        uuid id PK
        uuid file_id FK
        text content
        vector_1536 embedding "pgvector"
        int chunk_index
        int chunk_size
        timestamp created
    }
    
    SCHEMAS ||--o{ OBJECTS : defines
    SCHEMAS {
        uuid id PK
        string name
        json schema
        bool auto_vectorize
        timestamp created
    }
    
    FILES ||--o{ FILE_CHUNKS : split_into
    FILES {
        uuid id PK
        string filename
        string mime_type
        text extracted_text
        bool vectorized
        timestamp created
    }
```

## Index Types and Usage

```mermaid
graph LR
    subgraph "Vector Indexes (IVFFlat)"
        A[Objects.embedding]
        B[File_Chunks.embedding]
    end
    
    subgraph "Trigram Indexes (GIN)"
        C[Objects.title]
        D[Objects.description]
        E[File_Chunks.content]
    end
    
    subgraph "Full-Text Indexes (GIN)"
        F[Objects Composite Text]
        G[Files Full Content]
    end
    
    subgraph "Query Types"
        H[Semantic Search]
        I[Fuzzy Search]
        J[Exact Match]
        K[Autocomplete]
    end
    
    H --> A
    H --> B
    I --> C
    I --> D
    I --> E
    J --> F
    J --> G
    K --> C
    K --> D
    
    style A fill:#e1f5ff
    style B fill:#e1f5ff
    style C fill:#fff3cd
    style D fill:#fff3cd
    style E fill:#fff3cd
    style F fill:#d4edda
    style G fill:#d4edda
```

## Performance Characteristics

| Operation | Index Type | Complexity | Performance |
|-----------|-----------|------------|-------------|
| Vector Similarity (IVFFlat) | IVFFlat | O(√n) | < 100ms for 100K vectors |
| Vector Similarity (HNSW) | HNSW | O(log n) | < 50ms for 1M vectors |
| Trigram Similarity | GIN | O(log n) | < 50ms for 100K rows |
| Full-Text Search | GIN | O(log n) | < 100ms for 1M rows |
| Exact Match | B-tree | O(log n) | < 10ms |
| Pattern Match | GIN Trigram | O(log n) | < 50ms |

## Comparison: Before vs After

### Before (MySQL + Solr)

```mermaid
graph TB
    A[Application] --> B[MySQL Database]
    A --> C[Solr Server]
    C --> D[ZooKeeper]
    C --> E[Java Heap Memory]
    B --> F[Data Storage]
    C --> G[Search Indexes]
    
    H[Sync Job] -.Periodic Sync.-> B
    H -.Periodic Sync.-> C
    
    style C fill:#ffe6e6
    style D fill:#ffe6e6
    style E fill:#ffe6e6
    style G fill:#ffe6e6
```

**Issues:**
- Separate infrastructure for search
- Data synchronization overhead
- Higher resource usage (JVM)
- Complex deployment
- Eventual consistency

### After (PostgreSQL Only)

```mermaid
graph TB
    A[Application] --> B[PostgreSQL 16]
    B --> C[pgvector Extension]
    B --> D[pg_trgm Extension]
    B --> E[Data + Vectors]
    B --> F[Trigram Indexes]
    
    C --> E
    D --> F
    
    style B fill:#d4edda
    style C fill:#e1f5ff
    style D fill:#e1f5ff
    style E fill:#fff3cd
    style F fill:#fff3cd
```

**Benefits:**
- Single database for everything
- No synchronization needed
- Lower resource usage
- Simple deployment
- ACID consistency

## Extension Capabilities

```mermaid
mindmap
    root((PostgreSQL Extensions))
        pgvector
            Vector Storage
                Up to 16,000 dimensions
                Efficient storage format
            Distance Operators
                Cosine Similarity
                L2 Distance
                Inner Product
            Index Types
                IVFFlat for speed
                HNSW for accuracy
            Use Cases
                Semantic Search
                Similar Items
                RAG Systems
        pg_trgm
            Trigram Matching
                Split text into 3-char sequences
                Compare trigram sets
            Similarity Scoring
                0 to 1 scale
                Configurable threshold
            Operators
                % similarity operator
                ILIKE optimization
            Use Cases
                Fuzzy Search
                Autocomplete
                Typo Tolerance
        btree_gin
            Optimized Indexing
                Better GIN performance
                Multi-column indexes
        btree_gist
            Advanced Indexing
                Range queries
                Custom operators
```

## Deployment Architecture

```mermaid
graph TB
    subgraph "Docker Compose Stack"
        A[Nextcloud Container]
        B[PostgreSQL Container]
        C[Ollama Container]
        D[Presidio Container]
        E[n8n Container]
    end
    
    subgraph "PostgreSQL Container"
        F[PostgreSQL 16]
        G[pgvector Extension]
        H[pg_trgm Extension]
        I[Init Script]
    end
    
    A --> B
    A --> C
    A --> D
    A --> E
    
    B --> F
    F --> G
    F --> H
    I -.Initialize.-> F
    
    J[Volume: Database] --> B
    K[Volume: Nextcloud] --> A
    L[Volume: Ollama Models] --> C
    
    style B fill:#d4edda
    style G fill:#e1f5ff
    style H fill:#e1f5ff
```

## Summary

OpenRegister now uses a unified PostgreSQL-based architecture that:

1. **Eliminates External Dependencies**: No Solr or Elasticsearch needed
2. **Provides Advanced Search**: Vector similarity + full-text search
3. **Simplifies Deployment**: Single database container
4. **Reduces Resource Usage**: Lower memory and CPU requirements
5. **Ensures Consistency**: ACID-compliant operations
6. **Enables AI Features**: Native vector storage for embeddings
7. **Improves Performance**: Optimized indexes and queries

All search functionality is now native to PostgreSQL, making the system simpler, faster, and more maintainable.

