# OpenRegister Service Architecture

## Overview

OpenRegister follows a **service-oriented architecture** with clear separation of concerns. This document describes the responsibilities of each service and how they interact.

## Core Principles

1. **Thin Controllers**: Controllers validate HTTP requests and delegate to services
2. **Service Responsibility**: Services contain business logic and return structured data
3. **No Cross-Dependencies**: Services should not depend on each other circularly
4. **Independent Testing**: Each service can test its own functionality
5. **Clear Boundaries**: SOLR, LLM, and data persistence are separate concerns

## Service Hierarchy

```
Controllers (HTTP Layer)
    ↓
Services (Business Logic)
    ↓
Mappers/External APIs (Data/Integration Layer)
```

## Services Documentation

### VectorEmbeddingService

**Location**: `lib/Service/VectorEmbeddingService.php`

**Purpose**: Handles all vector embedding operations using LLM providers.

**Responsibilities**:
- Generate embeddings for text using multiple LLM providers
- Store embeddings in database (`oc_openregister_vectors` table)
- Perform semantic similarity searches using cosine similarity
- Test embedding configurations without saving settings
- Manage embedding generators for different providers

**Provider Support**:
- OpenAI: `text-embedding-ada-002`, `text-embedding-3-small`, `text-embedding-3-large`
- Fireworks AI: Custom OpenAI-compatible API with various models
- Ollama: Local models with custom configurations

**Key Methods**:
- `generateEmbedding(string $text, ?string $provider): array` - Generate embedding with saved settings
- `generateEmbeddingWithCustomConfig(string $text, array $config): array` - Generate with custom config
- `testEmbedding(string $provider, array $config, string $testText): array` - Test configuration
- `semanticSearch(string $query, int $limit, array $filters): array` - Semantic search
- `storeVector(...)` - Store embedding in database

**Dependencies**:
- LLPhant library for embedding generation
- Database connection for vector storage
- SettingsService for reading configuration (not for testing)

**Independence**:
- ✅ **Independent of SOLR** - Can operate without search infrastructure
- ✅ **No chat dependencies** - Only handles embeddings
- ✅ **Standalone testing** - Tests don't require settings storage

---

### ChatService

**Location**: `lib/Service/ChatService.php`

**Purpose**: Handles all LLM chat operations with RAG (Retrieval Augmented Generation).

**Responsibilities**:
- Process chat messages with context retrieval
- Generate AI responses using configured LLM providers
- Manage conversation history and summarization
- Generate conversation titles automatically
- Test LLM chat configurations without saving settings
- Handle agent-based context retrieval and filtering

**Provider Support**:
- OpenAI: GPT-4, GPT-4o-mini, and other chat models
- Fireworks AI: Llama, Mistral, and other open models
- Ollama: Local LLM deployments

**RAG Capabilities**:
- Semantic search using VectorEmbeddingService
- Keyword search using GuzzleSolrService (optional)
- Hybrid search combining both approaches
- Agent-based filtering and context retrieval
- View-based filtering for multi-tenancy

**Key Methods**:
- `processMessage(int $conversationId, string $userId, string $userMessage): array` - Process chat with RAG
- `testChat(string $provider, array $config, string $testMessage): array` - Test configuration
- `generateConversationTitle(string $firstMessage): string` - Generate title
- `ensureUniqueTitle(string $baseTitle, string $userId, int $agentId): string` - Ensure uniqueness

**Dependencies**:
- LLPhant library for LLM interactions
- VectorEmbeddingService for semantic search
- GuzzleSolrService for keyword search (optional)
- ConversationMapper, MessageMapper, AgentMapper for data persistence
- SettingsService for reading configuration (not for testing)

**Independence**:
- ✅ **Independent testing** - Tests don't require settings storage
- ✅ **Optional SOLR** - Can work without keyword search
- ✅ **Flexible RAG** - Can use semantic-only, keyword-only, or hybrid search

---

### SettingsService

**Location**: `lib/Service/SettingsService.php`

**Purpose**: Store and retrieve application settings ONLY. Does NOT contain business logic.

**Responsibilities**:
- Store and retrieve settings from Nextcloud's `IAppConfig`
- Provide default values for unconfigured settings
- Manage settings categories: RBAC, Multitenancy, Retention, SOLR, LLM, Files, Objects
- Get available options (groups, users, tenants) for settings UI
- Rebase operations (apply default owners/tenants to existing objects)
- Cache management statistics and operations

**What This Service Does NOT Do**:
- ❌ Test LLM connections (use VectorEmbeddingService or ChatService)
- ❌ Test SOLR connections (use GuzzleSolrService)
- ❌ Generate embeddings (use VectorEmbeddingService)
- ❌ Execute chat operations (use ChatService)
- ❌ Perform searches (use appropriate search services)

**Settings Categories**:
- **Version**: Application name and version information
- **RBAC**: Role-based access control configuration
- **Multitenancy**: Tenant isolation and default tenant settings
- **Retention**: Data retention policies for objects, logs, trails
- **SOLR**: Search engine configuration and connection details
- **LLM**: Language model provider configuration (OpenAI, Fireworks, Ollama)
- **Files**: File processing and vectorization settings
- **Objects**: Object vectorization and metadata settings
- **Organisation**: Default organisation and auto-creation settings

**Key Methods**:
- `getSettings(): array` - Get all settings with defaults
- `updateSettings(array $data): array` - Update all settings
- `getLLMSettingsOnly(): array` - Get LLM settings
- `updateLLMSettingsOnly(array $data): array` - Update LLM settings
- `getSolrSettingsOnly(): array` - Get SOLR settings
- `updateSolrSettingsOnly(array $data): array` - Update SOLR settings
- (Similar methods for other setting categories)

**Dependencies**:
- IAppConfig for settings storage
- IConfig for system configuration
- Various mappers for available options (groups, users, tenants)

**Architecture Pattern**:
- ✅ **Persistence layer only** - No business logic
- ✅ **Default values** - Always returns valid configuration
- ✅ **Validation** - Ensures settings have correct types and structures

---

### SettingsController

**Location**: `lib/Controller/SettingsController.php`

**Purpose**: Thin HTTP layer that validates requests and delegates to services.

**Responsibilities**:
- Validate HTTP request parameters
- Delegate settings CRUD operations to SettingsService
- Delegate LLM testing to VectorEmbeddingService and ChatService
- Delegate SOLR testing to GuzzleSolrService
- Return appropriate JSONResponse with correct HTTP status codes
- Handle HTTP-level concerns (authentication, CSRF)

**Architecture Pattern**:
- ✅ **Thin controller** - Minimal logic, delegates to services
- ✅ **Validation only** - Checks required parameters exist
- ✅ **Service delegation** - Business logic in services
- ✅ **Error handling** - Catches service errors and returns HTTP responses

**Example Delegation**:

```php
// OLD (INCORRECT - Controller contained business logic)
public function testEmbedding(): JSONResponse {
    // Save test config to settings
    $this->settingsService->updateLLMSettings($testConfig);
    
    // Generate embedding
    $embedding = $vectorService->generateEmbedding($text);
    
    // Restore original config
    $this->settingsService->updateLLMSettings($originalConfig);
    
    return new JSONResponse($result);
}

// NEW (CORRECT - Delegates to service)
public function testEmbedding(): JSONResponse {
    $provider = $this->request->getParam('provider');
    $config = $this->request->getParam('config');
    $testText = $this->request->getParam('testText');
    
    // Validate
    if (empty($provider)) {
        return new JSONResponse(['error' => 'Missing provider'], 400);
    }
    
    // Delegate to service (business logic)
    $vectorService = $this->container->get(VectorEmbeddingService::class);
    $result = $vectorService->testEmbedding($provider, $config, $testText);
    
    // Return HTTP response
    return new JSONResponse($result, $result['success'] ? 200 : 400);
}
```

---

## Service Interactions

### Testing LLM Embeddings

```
HTTP Request
    ↓
SettingsController::testEmbedding()
    ↓ (validates parameters)
VectorEmbeddingService::testEmbedding()
    ↓ (business logic)
VectorEmbeddingService::generateEmbeddingWithCustomConfig()
    ↓ (creates generator)
LLPhant EmbeddingGenerator
    ↓ (API call)
OpenAI/Fireworks/Ollama API
```

### Testing LLM Chat

```
HTTP Request
    ↓
SettingsController::testChat()
    ↓ (validates parameters)
ChatService::testChat()
    ↓ (business logic)
LLPhant OpenAIChat
    ↓ (API call)
OpenAI/Fireworks/Ollama API
```

### Processing Chat with RAG

```
HTTP Request
    ↓
ChatController::processMessage()
    ↓
ChatService::processMessage()
    ↓ (retrieves context)
VectorEmbeddingService::semanticSearch()
    ↓ (combines with keywords if needed)
GuzzleSolrService::search() [optional]
    ↓ (generates response)
LLPhant OpenAIChat
```

### Storing Settings

```
HTTP Request
    ↓
SettingsController::updateLLMSettings()
    ↓ (validates parameters)
SettingsService::updateLLMSettingsOnly()
    ↓ (persists to storage)
IAppConfig::setValueString()
```

---

## Key Architectural Decisions

### 1. No Temporary Config Storage

**Problem**: Testing LLM configurations required saving test config, testing, then restoring original.

**Solution**: Services accept config as parameters for testing, no settings manipulation needed.

```php
// OLD - Temporary config storage (WRONG)
$original = $settingsService->getLLMSettings();
$settingsService->updateLLMSettings($testConfig);
$result = $service->doSomething();
$settingsService->updateLLMSettings($original);

// NEW - Direct config passing (CORRECT)
$result = $service->testWithConfig($testConfig);
```

### 2. Service Independence

**Problem**: Services were reading from inconsistent config keys or environment variables.

**Solution**: Each service reads from its designated config key or accepts config as parameters.

- VectorEmbeddingService: Reads from `vector_embeddings` key OR accepts custom config
- ChatService: Reads from `llm` key OR accepts custom config
- Testing: Always uses custom config (no settings dependency)

### 3. Clear Delegation

**Problem**: Controllers contained business logic for testing.

**Solution**: Controllers validate and delegate, services contain business logic.

```
Controller Responsibilities:
- Validate HTTP parameters
- Call appropriate service method
- Return HTTP response

Service Responsibilities:
- Implement business logic
- Return structured data (arrays)
- Handle errors with try/catch
```

### 4. SOLR and LLM Separation

**Problem**: LLM services were coupled to SOLR infrastructure.

**Solution**: 
- VectorEmbeddingService is completely independent of SOLR
- ChatService optionally uses SOLR for keyword search
- Hybrid search is implemented in ChatService, not as a dependency

---

## Testing Best Practices

### Testing Service Methods

Services should be testable without HTTP layer:

```php
// Good - Service can be tested directly
$vectorService = new VectorEmbeddingService($db, $settings, $logger);
$result = $vectorService->testEmbedding('openai', [
    'apiKey' => 'test-key',
    'model' => 'text-embedding-3-small'
], 'Test text');

$this->assertTrue($result['success']);
```

### Testing Controllers

Controllers should only validate and delegate:

```php
// Good - Controller test mocks services
$mockService = $this->createMock(VectorEmbeddingService::class);
$mockService->expects($this->once())
    ->method('testEmbedding')
    ->willReturn(['success' => true]);

$controller = new SettingsController($mockService, ...);
$response = $controller->testEmbedding();

$this->assertEquals(200, $response->getStatus());
```

---

## Migration Guide

### For Existing Code

If you have code that:
1. Saves settings temporarily for testing → Use service test methods with custom config
2. Calls services without error handling → Catch exceptions and return proper responses
3. Implements business logic in controllers → Move to services

### Example Migration

**Before**:
```php
public function myEndpoint() {
    $original = $this->settingsService->getConfig();
    $this->settingsService->updateConfig($testConfig);
    
    try {
        $result = $this->someService->doWork();
        $this->settingsService->updateConfig($original);
        return new JSONResponse($result);
    } catch (Exception $e) {
        $this->settingsService->updateConfig($original);
        return new JSONResponse(['error' => $e->getMessage()], 500);
    }
}
```

**After**:
```php
public function myEndpoint() {
    $config = $this->request->getParam('config');
    
    try {
        $result = $this->someService->testWithConfig($config);
        return new JSONResponse($result, $result['success'] ? 200 : 400);
    } catch (Exception $e) {
        return new JSONResponse(['error' => $e->getMessage()], 500);
    }
}
```

---

## Summary

### Service Responsibilities

| Service | Purpose | Tests Its Own | Depends On |
|---------|---------|---------------|------------|
| **VectorEmbeddingService** | Generate and search embeddings | ✅ Yes | Database, LLPhant |
| **ChatService** | LLM chat with RAG | ✅ Yes | VectorEmbedding, optional SOLR |
| **SettingsService** | Store/retrieve settings | ❌ No (persistence only) | IAppConfig |
| **SettingsController** | HTTP validation and delegation | ❌ No (thin layer) | All services |

### Key Takeaways

1. ✅ Services contain business logic and can test themselves
2. ✅ Controllers validate and delegate, no business logic
3. ✅ SettingsService only handles persistence, not testing
4. ✅ Testing uses custom config parameters, not temporary storage
5. ✅ SOLR and LLM are independent, ChatService can optionally combine them
6. ✅ Each service has clear, documented responsibilities

