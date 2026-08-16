---
title: AI Chat Interface
sidebar_position: 5
---

# AI Chat Interface

OpenRegister's AI chat interface provides conversational AI capabilities with RAG (Retrieval Augmented Generation), function calling, and comprehensive agent management.

## Overview

The AI chat interface enables users to:
- **Interact with AI agents** using natural language
- **Search across data** using semantic search and RAG
- **Execute actions** via function calling (tools)
- **Control context** by selecting views and tools
- **Configure search** with flexible RAG settings

## Agent Selector

The agent selector displays available AI agents in a compact card-based grid layout.

### Card Layout

Each agent card shows:
- **Agent name and description**
- **Start Conversation button** (positioned on the right)
- **Views**: Available data views the agent can access
- **Tools**: Available function calling tools
- **Model**: LLM provider and model name

### Key Features

**Compact Design:**
- Responsive grid layout
- Side-by-side views and tools display
- Expandable lists (shows first 3, expand to see all)
- Optimized for various screen sizes

**Visual Hierarchy:**
- Clear agent identification
- Immediate call-to-action visibility
- Capability overview at a glance
- Model information displayed

## Chat Settings

Access chat settings via the settings button next to the send button.

### View and Tool Selection

**Views Section:**
- Checkboxes for all available views from the agent
- Controls which data the AI can access
- All views checked by default
- Toggle items on/off during conversation

**Tools Section:**
- Checkboxes for all available tools from the agent
- Controls which actions the AI can execute
- All tools checked by default
- Dynamic tool management

### RAG Configuration

**Include Toggles:**
- **Include Objects**: Search in structured object data
- **Include Files**: Search in document files

**Source Count Controls:**
- **Object Sources**: 1-20 (default: 5)
- **File Sources**: 1-20 (default: 5)

**Performance Guidance:**
- Fewer sources (1-3): Faster responses, more focused
- More sources (10-20): Comprehensive context, slower responses
- Recommended: 5 sources for balanced speed and accuracy

## Function Calling

Function calling allows AI agents to execute actions and retrieve data.

### Available Tools

- **Application Tool**: List, create, and manage applications
- **Agent Tool**: List, create, and manage agents
- **Register Tool**: List, create, and manage registers
- **Schema Tool**: List, create, and manage schemas
- **Object Tool**: Search, create, and manage objects

### Provider Support

| Provider | Function Calling Support |
|----------|-------------------------|
| **OpenAI** | ✅ Fully supported |
| **Ollama** | ✅ Fully supported |
| **Fireworks AI** | ❌ Not yet implemented |

**Note**: To use function calling, switch to OpenAI or Ollama in LLM Configuration settings.

## RAG (Retrieval Augmented Generation)

RAG enables AI agents to search and retrieve relevant context from your data.

### How RAG Works

1. **User sends message** to AI agent
2. **System searches** vectors using semantic similarity
3. **Retrieves relevant sources** (objects and files)
4. **Builds context** from retrieved sources
5. **AI generates response** using context

### Search Modes

**Semantic Search:**
- Uses vector embeddings
- Finds semantically similar content
- Works across languages

**Hybrid Search:**
- Combines keyword and semantic search
- Uses Reciprocal Rank Fusion (RRF)
- Best accuracy and coverage

**Keyword Search:**
- Traditional text search
- Fast and reliable
- Good for exact matches

### Configuration

Configure RAG in chat settings:
- Select which views to search
- Choose object/file inclusion
- Set source count limits
- Control search behavior

## Use Cases

### Speed-Optimized Queries
- Object sources: 2
- File sources: 2
- Minimal views/tools enabled
- **Result**: Fast responses (~1-2s)

### Comprehensive Research
- Object sources: 15
- File sources: 15
- All relevant views enabled
- **Result**: Thorough analysis (~5-10s)

### Object-Only Search
- Include Objects: ✓
- Include Files: ✗
- Only data-related tools enabled
- **Result**: Structured data queries

### File-Only Search
- Include Objects: ✗
- Include Files: ✓
- Document tools only
- **Result**: Document/policy questions

## API Reference

### Send Message

**POST** `/apps/openregister/api/chat/send`

**Request:**
```json
{
    "message": "Your question here",
    "conversation": "conversation-uuid",
    "views": ["view-uuid-1", "view-uuid-2"],
    "tools": ["tool-uuid-1", "tool-uuid-2"],
    "includeObjects": true,
    "includeFiles": true,
    "numSourcesFiles": 5,
    "numSourcesObjects": 5
}
```

**Response:**
```json
{
    "message": {
        "id": 123,
        "content": "AI response",
        "sources": [...]
    },
    "conversation": "conversation-uuid",
    "title": "Conversation Title"
}
```

## Tools & RAG Implementation

### Tools Fixed

All tools now use consistent OpenAI function calling format:

```php
public function getFunctions(): array
{
    return [
        [
            'name' => 'list_applications',
            'description' => 'List all applications...',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Maximum results',
                    ],
                ],
                'required' => [],
            ],
        ],
    ];
}
```

### Function Calling Integration

Function calling is integrated into the chat service:
- Tools are loaded from agent configuration
- Converted to function definitions
- Passed to LLM via `setTools()` method
- Function calls are executed and results returned to LLM

### Provider Support

| Provider | Function Calling Support |
|----------|-------------------------|
| **OpenAI** | ✅ Fully supported |
| **Ollama** | ✅ Fully supported |
| **Fireworks AI** | ❌ Not yet implemented |

**Note**: To use function calling, switch to OpenAI or Ollama in LLM Configuration settings.

### RAG Status

**RAG is fully operational** with:
- ✅ Semantic search finds objects correctly
- ✅ Source names extracted from metadata
- ✅ Vector metadata includes `uuid`, `register`, `schema`, `file_id`, `file_path`
- ✅ Cosine similarity calculation
- ✅ Hybrid search (SOLR + vectors + RRF)
- ✅ View-based filtering
- ✅ Object and file vectorization

### Configuration

**Using Function Calling:**
1. Switch to OpenAI or Ollama in LLM Configuration
2. Enable tools for agent
3. Test with natural language commands

**Using RAG:**
1. Vectorize your data (objects and files)
2. Configure agents with appropriate views
3. Test queries to verify RAG is finding correct information
4. Monitor vector stats to track coverage

## Related Documentation

- **[Text Extraction, Vectorization & Named Entity Recognition](./text-extraction-vectorization-ner.md)** - Unified documentation for text extraction, vectorization, and NER
- [Agents](../features/agents.md) - Agent management and configuration
- [RAG Implementation](../features/rag-implementation.md) - Detailed RAG documentation
- [Function Calling](../features/function-calling.md) - Function calling guide
- [Performance Optimization](../development/performance-optimization.md) - Performance tuning

