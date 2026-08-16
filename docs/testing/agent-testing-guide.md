# Agent Testing Guide

This guide documents the testing process for OpenRegister agents with tool integration, specifically focusing on the CMS Tool from OpenCatalogi.

## Overview

OpenRegister provides an AI agent framework that allows agents to interact with Nextcloud apps through tools. This guide covers:

- Setting up the testing environment
- Creating and configuring agents
- Testing tool integration
- Verifying agent operations
- Troubleshooting common issues

## Architecture

The agent system consists of several components:

```
┌─────────────┐      ┌──────────────┐      ┌─────────────┐
│   Ollama    │◄─────│  OpenRegister│◄─────│ OpenCatalogi│
│  (LLM)      │      │   (Agents)   │      │ (CMS Tool)  │
└─────────────┘      └──────────────┘      └─────────────┘
       │                     │                      │
       │                     │                      │
       ▼                     ▼                      ▼
┌─────────────────────────────────────────────────────────┐
│              Nextcloud with MySQL Database              │
└─────────────────────────────────────────────────────────┘
```

### Components

1. **Ollama**: Local LLM inference server providing AI capabilities
2. **OpenRegister**: Agent management and execution framework
3. **OpenCatalogi**: CMS functionality with Tool interface
4. **Tools**: Interfaces that allow agents to interact with app functionality
5. **Endpoints**: API routes that invoke agents with natural language input

## Prerequisites

### Container Setup

Ensure the following containers are running:

```bash
# Check container status
docker ps | grep -E 'nextcloud|ollama|database'
```

Expected containers:
- `master-nextcloud-1` - Nextcloud application server
- `openregister-ollama` or `master-ollama-1` - Ollama LLM server
- `master-database-mysql-1` - MySQL database

### App Status

```bash
# Verify apps are enabled
docker exec -u 33 master-nextcloud-1 php occ app:list | grep -E 'openregister|opencatalogi'
```

Expected output:
```
  - opencatalogi: 0.7.2
  - openregister: 0.2.7
```

### Ollama Configuration

```bash
# Check Ollama API
curl http://localhost:11434/api/tags

# Pull required model (if not present)
docker exec openregister-ollama ollama pull llama3.2:latest
```

## Database Setup

OpenRegister requires several database tables. If API routes return 404 errors, tables may need to be created manually:

### Required Tables

```sql
-- Agents table
CREATE TABLE IF NOT EXISTS oc_openregister_agents (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid varchar(255) NOT NULL,
  name varchar(255) NOT NULL,
  description text,
  provider varchar(50),
  model varchar(255),
  prompt text,
  temperature decimal(3,2),
  tools text,
  active tinyint(1) NOT NULL DEFAULT 1,
  owner varchar(255),
  created datetime NOT NULL,
  updated datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY agents_uuid_index (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Endpoints table
CREATE TABLE IF NOT EXISTS oc_openregister_endpoints (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid varchar(255) NOT NULL,
  name varchar(255) NOT NULL,
  description text,
  endpoint varchar(1024) NOT NULL,
  method varchar(10) DEFAULT 'GET',
  target_type varchar(50),
  target_id varchar(255),
  created datetime NOT NULL,
  updated datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY endpoints_uuid_index (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Objects table (for CMS data)
CREATE TABLE IF NOT EXISTS oc_openregister_objects (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid varchar(255) NOT NULL,
  schema varchar(255),
  register varchar(255),
  title varchar(500),
  summary text,
  description text,
  object longtext,
  created datetime NOT NULL,
  updated datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY objects_uuid_index (uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Testing Process

### 1. Create Test Agent

Agents can be created via API or directly in the database.

#### Via PHP Script (Recommended for testing)

```bash
docker exec -u 33 master-nextcloud-1 php \
  /var/www/html/apps-extra/openregister/test-agent-creation.php
```

#### Agent Configuration

```json
{
  "name": "CMS Test Agent",
  "description": "Agent for testing CMS operations with OpenCatalogi",
  "provider": "ollama",
  "model": "llama3.2:latest",
  "prompt": "You are a helpful assistant that manages website content. You can create menus and menu items for websites using the CMS tools available to you.",
  "tools": ["CMS Tool"],
  "temperature": 0.7,
  "active": true
}
```

#### Verify Agent Creation

```sql
-- Check agent in database
SELECT id, uuid, name, provider, model, tools, active 
FROM oc_openregister_agents 
WHERE name = 'CMS Test Agent';
```

### 2. Create Endpoint

Endpoints route API requests to agents.

```bash
docker exec -u 33 master-nextcloud-1 php \
  /var/www/html/apps-extra/openregister/test-endpoint-creation.php
```

#### Endpoint Configuration

```json
{
  "name": "CMS Agent Endpoint",
  "description": "Endpoint for testing CMS operations via agent",
  "endpoint": "/test/cms/agent",
  "method": "POST",
  "targetType": "agent",
  "targetId": "{agent-uuid}"
}
```

### 3. Test CMS Functionality

Test creating menus and menu items:

```bash
docker exec -u 33 master-nextcloud-1 php \
  /var/www/html/apps-extra/openregister/test-cms-functionality.php
```

This test:
1. Creates a menu ('Main Navigation')
2. Creates 4 menu items (Home, About, Services, Contact)
3. Verifies data in the database
4. Displays menu structure

#### Expected Output

```
🍔 Testing CMS Functionality (Menus & Menu Items)
=============================================================

📋 Step 1: Creating a test menu...
✅ Menu created successfully!
   ID: 1
   UUID: 426bbe23-bcf0-49a9-a52a-b441f6fd96b8
   Title: Main Navigation

🔗 Step 2: Creating menu items...
   ✓ Created: Home -> /home (ID: 2)
   ✓ Created: About -> /about (ID: 3)
   ✓ Created: Services -> /services (ID: 4)
   ✓ Created: Contact -> /contact (ID: 5)

📊 Step 4: Testing data retrieval...
Menu: Main Navigation
  • Home → /home (Order: 1)
  • About → /about (Order: 2)
  • Services → /services (Order: 3)
  • Contact → /contact (Order: 4)

✅ CMS Functionality Test Complete!
```

### 4. Verify Database

```sql
-- List all menus
SELECT id, uuid, title, schema 
FROM oc_openregister_objects 
WHERE schema = 'menu';

-- List all menu items
SELECT id, uuid, title, schema, object 
FROM oc_openregister_objects 
WHERE schema = 'menuItem';
```

## Newman/Postman Tests

Automated API tests are available in the Newman test collection.

### Running Tests

```bash
# Install Newman
npm install -g newman

# Run tests from openregister directory
newman run tests/newman/agent-cms-testing.postman_collection.json
```

### Test Coverage

The Newman collection tests:
1. Ollama connectivity and model availability
2. Agent creation with CMS Tool
3. Agent listing and retrieval
4. Endpoint creation and routing
5. Menu creation via natural language
6. Menu item creation
7. Data verification
8. Cleanup operations

See `tests/newman/README.md` for detailed documentation.

## CMS Tool Integration

The CMS Tool (`OCA\\OpenCatalogi\\Tool\\CMSTool`) provides functions for managing CMS content.

### Available Functions

```php
// Menu operations
cms_create_menu(string $title, string $description): string
cms_list_menus(): array

// Menu item operations
cms_add_menu_item(string $menuUuid, string $name, string $link, ?int $order): string
cms_list_menu_items(string $menuUuid): array

// Page operations
cms_create_page(string $title, string $content): string
cms_list_pages(): array
cms_update_page(string $uuid, array $updates): bool
cms_delete_page(string $uuid): bool
```

### Function Calling Flow

```
User Request
     │
     ▼
Endpoint (POST /test/cms/agent)
     │
     ▼
Agent (CMS Test Agent)
     │
     ▼
LLM (Ollama llama3.2)
     │
     ▼
Function Call Decision
     │
     ▼
CMS Tool (cms_create_menu)
     │
     ▼
ObjectService (Data persistence)
     │
     ▼
Database (oc_openregister_objects)
```

### Example: Creating a Menu

**Request:**
```json
POST /apps/openregister/api/endpoints/{endpoint-id}/execute
{
  "message": "Create a menu called Main Navigation with description Primary navigation menu"
}
```

**Agent Processing:**
1. LLM receives message and system prompt
2. LLM decides to call `cms_create_menu` function
3. CMS Tool executes function
4. ObjectService stores menu in database
5. Agent returns result with UUID

**Response:**
```json
{
  "result": {
    "menuUuid": "426bbe23-bcf0-49a9-a52a-b441f6fd96b8",
    "message": "Menu 'Main Navigation' created successfully"
  }
}
```

## Troubleshooting

### Issue: API Returns 404

**Symptoms:**
```html
<!DOCTYPE html>
<html>...
<h2>Page not found</h2>
```

**Causes:**
- App not properly enabled
- Database tables missing
- Routing not initialized

**Solutions:**
1. Verify app is enabled: `php occ app:enable openregister`
2. Check database tables exist (see Database Setup)
3. Use PHP test scripts instead of API calls
4. Check Nextcloud logs: `docker logs master-nextcloud-1`

### Issue: Ollama Not Accessible

**Symptoms:**
```
Error connecting to Ollama
```

**Solutions:**
```bash
# Check Ollama container
docker ps | grep ollama

# Check Ollama API
curl http://localhost:11434/api/tags

# Restart Ollama if needed
docker restart openregister-ollama
```

### Issue: CMS Tool Not Found

**Symptoms:**
```
Class 'OCA\\OpenCatalogi\\Tool\\CMSTool' not found
```

**Solutions:**
1. Verify OpenCatalogi is enabled: `php occ app:enable opencatalogi`
2. Check file exists: `apps-extra/opencatalogi/lib/Tool/CMSTool.php`
3. Ensure proper autoloading in Application.php

### Issue: Database Connection Errors

**Symptoms:**
```
SQLSTATE[HY000] [2002] Connection refused
```

**Solutions:**
```bash
# Check database container
docker ps | grep database

# Test connection
docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud -e "SELECT 1"
```

## Test Results Summary

### Successful Test Execution

✅ **Ollama Setup**
- Container running and accessible
- llama3.2:latest model loaded
- API responding on port 11434

✅ **Agent Creation**
- Agent created in database
- UUID: `6ce3f820-b470-4ed2-bb0c-6834e3dc91f1`
- Tools: `["CMS Tool"]`
- Provider: ollama, Model: llama3.2:latest

✅ **Endpoint Creation**
- Endpoint created in database
- UUID: `24569c62-ac9d-4f43-9c1f-d7daa4ebe6ea`
- Path: `/test/cms/agent`
- Target: agent (CMS Test Agent)

✅ **CMS Operations**
- Menu created successfully
- 4 menu items created (Home, About, Services, Contact)
- All data verified in database
- Data structure matches CMS Tool expectations

### Known Limitations

⚠️ **API Routes**
- Some API endpoints return 404 in master Nextcloud setup
- Database migrations may not run automatically
- Solution: Use PHP test scripts for direct testing

⚠️ **App Loading**
- Complex service dependencies may not autoload properly
- ToolInterface and other classes may need manual loading
- Solution: Use OpenRegister's own Docker environment for full integration

## Next Steps

1. **Full Integration Test**: Test agent execution with Ollama LLM for natural language processing
2. **API Endpoint Fix**: Investigate and fix 404 errors on API routes
3. **Tool Registry**: Verify CMS Tool is properly registered in ToolRegistry
4. **Performance Testing**: Test agent response times with various LLM models
5. **Security Testing**: Verify RBAC and multi-tenancy boundaries

## References

- [OpenRegister Documentation](../index.md)
- [CMS Tool Source Code](../../lib/Tool/CMSTool.php)
- [Agent Mapper](../../lib/Db/AgentMapper.php)
- [Endpoint Service](../../lib/Service/EndpointService.php)
- [Newman Tests](../../tests/newman/README.md)

