---
sidebar_position: 7
sidebar_label: Tool Testing Guide
title: Tool Registration Testing Guide
description: Comprehensive testing guide for OpenRegister agent tools
keywords: [agents, tools, testing, function calling, quality assurance]
---

# Tool Registration Testing Guide

This guide explains how to test the tool registration system for OpenRegister agents.

## Prerequisites

- OpenRegister app installed and enabled
- At least one other app with a registered tool (e.g., OpenCatalogi)
- Admin or user access to create agents
- Docker environment running (if applicable)

## Testing the Built-in Tools

### Step 1: Verify OpenRegister Tools are Available

1. Navigate to **OpenRegister** app
2. Go to **Agents** section
3. Click **Create Agent** or edit an existing agent
4. Navigate to the **Tools** tab

You should see three built-in tools:
- **Register Tool** - Manage registers
- **Schema Tool** - Manage schemas  
- **Objects Tool** - Manage objects

Each tool should display:
- Icon
- Name
- Description
- App badge ('openregister')

### Step 2: Enable Tools for an Agent

1. Create a test agent with the following settings:
   - **Name**: 'Test Tool Agent'
   - **Type**: 'Chat'
   - **Prompt**: 'You are a helpful assistant that can manage data'
   - **Enable RAG**: false (for simplicity)

2. Go to the **Tools** tab
3. Enable all three built-in tools
4. Save the agent

### Step 3: Test Tool Function Calling

1. Start a chat with your test agent
2. Ask it to perform actions:

**Test Register Tool:**
```
Create a new register called 'Test Register' with description 'A test register'
```

Expected: The agent should use the `register_create` function and return success.

**Test Schema Tool:**
```
List all schemas in the system
```

Expected: The agent should use the `schema_list` function and return a list of schemas.

**Test Objects Tool:**
```
Search for objects with title containing 'test'
```

Expected: The agent should use the `object_search` function and return matching objects.

### Step 4: Verify RBAC and Organization Limits

1. Create two users in different organizations
2. Create an agent in Organization A
3. Try to access Organization B's data through the agent

Expected: The agent should only have access to Organization A's data.

## Testing External App Tools (OpenCatalogi Example)

### Step 1: Install and Enable OpenCatalogi

```bash
docker exec -u 33 master-nextcloud-1 php occ app:enable opencatalogi
```

### Step 2: Verify CMS Tool is Registered

1. Navigate to **OpenRegister** > **Agents**
2. Edit an agent and go to **Tools** tab
3. You should now see **CMS Tool** with:
   - Icon: 'icon-category-office'
   - Name: 'CMS Tool'
   - Description: 'Manage website content: create pages, menus, and menu items'
   - App badge: 'opencatalogi'

### Step 3: Enable CMS Tool

1. Enable the **CMS Tool** for your test agent
2. Save the agent

### Step 4: Test CMS Functions

Start a chat and test the following:

**Create a Page:**
```
Create a new page titled 'Welcome' with content 'This is a welcome page'
```

Expected: Agent uses `cms_create_page` and returns page UUID.

**List Pages:**
```
Show me all pages
```

Expected: Agent uses `cms_list_pages` and displays all pages.

**Create a Menu:**
```
Create a menu called 'Main Menu'
```

Expected: Agent uses `cms_create_menu` and returns menu UUID.

**Add Menu Item:**
```
Add a menu item to the Main Menu that links to the Welcome page
```

Expected: Agent uses `cms_add_menu_item` and creates the link.

## API Testing

### Test Tool Registry API

```bash
# Get all available tools
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  http://localhost/apps/openregister/api/agents/tools

# Expected response:
{
  "results": {
    "openregister.register": {
      "name": "Register Tool",
      "description": "Manage registers...",
      "icon": "icon-category-office",
      "app": "openregister"
    },
    "openregister.schema": { ... },
    "openregister.objects": { ... },
    "opencatalogi.cms": { ... }
  }
}
```

### Test Agent with Tools

```bash
# Create agent with tools enabled
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  -X POST http://localhost/apps/openregister/api/agents \
  -d '{
    "name": "API Test Agent",
    "type": "chat",
    "tools": ["openregister.objects", "opencatalogi.cms"]
  }'

# Start a conversation and test tool calls
# ... (similar to chat testing above)
```

## Debugging

### Check Tool Registration

```bash
# View OpenRegister logs
docker logs master-nextcloud-1 | grep '\[ToolRegistry\]'

# Expected output:
# [ToolRegistry] Loading tools from all apps
# [ToolRegistry] Tool registered: openregister.register
# [ToolRegistry] Tool registered: openregister.schema
# [ToolRegistry] Tool registered: openregister.objects
# [ToolRegistry] Tool registered: opencatalogi.cms
# [ToolRegistry] Loaded tools: count=4
```

### Check Tool Execution

```bash
# View ChatService logs
docker logs master-nextcloud-1 | grep '\[ChatService\]'

# Expected output when agent uses a tool:
# [ChatService] Loaded tool: openregister.objects
# [ChatService] Function call requested: object_search
# [ChatService] Function call result: success
```

### Check Tool-Specific Logs

```bash
# View CMSTool logs
docker logs master-nextcloud-1 | grep '\[CMSTool\]'

# Expected output:
# [CMSTool] Executing function: cms_create_page
# [CMSTool] Function execution completed
```

## Common Issues

### Tool Not Appearing in Agent Editor

**Problem**: Tool doesn't show up in the Tools tab.

**Solutions**:
1. Check app is enabled: `php occ app:enable myapp`
2. Verify event listener is registered in `Application.php`
3. Check logs for registration errors
4. Clear Nextcloud cache: `php occ maintenance:repair`

### Tool Function Not Being Called

**Problem**: Agent doesn't use the tool when asked.

**Solutions**:
1. Check function descriptions are clear and detailed
2. Verify agent has the tool enabled
3. Try explicit instructions: 'Use the CMS tool to create a page'
4. Check LLM temperature (lower = more predictable)

### Tool Function Fails

**Problem**: Function executes but returns an error.

**Solutions**:
1. Check user permissions
2. Verify organization boundaries
3. Check required parameters are provided
4. Review tool logs for exceptions

### Agent Uses Wrong Tool

**Problem**: Agent uses a different tool than expected.

**Solutions**:
1. Improve function descriptions
2. Make function names more specific
3. Reduce number of enabled tools
4. Provide more context in the prompt

## Performance Testing

### Load Testing Tool Registry

```bash
# Test registry performance with multiple concurrent requests
for i in {1..100}; do
  docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
    http://localhost/apps/openregister/api/agents/tools &
done
wait
```

Expected: All requests should complete in < 100ms.

### Load Testing Tool Execution

```bash
# Test tool execution under load
# Create 10 agents with tools enabled
# Send 10 concurrent chat messages that trigger tool calls
# Monitor response times and error rates
```

Expected: Response times should remain reasonable (< 5s for simple operations).

## Security Testing

### Test RBAC Enforcement

1. Create user A in Org 1
2. Create user B in Org 2
3. Create agent in Org 1 with tools enabled
4. User B should NOT be able to:
   - Access the agent
   - See Org 1's data through any tool

### Test Input Validation

Try these malicious inputs:

```bash
# SQL Injection attempt
"Create a page with title: '; DROP TABLE pages; --"

# XSS attempt  
"Create a page with content: <script>alert('xss')</script>"

# Path traversal
"Create a page with slug: ../../../../etc/passwd"
```

Expected: All should be safely handled/sanitized.

### Test Rate Limiting

Configure agent with rate limits:
- Request quota: 10 per hour
- Token quota: 1000 per hour

Send 15 requests rapidly.

Expected: Requests 11-15 should be rejected with rate limit error.

## Automated Tests

### Unit Tests

Run PHPUnit tests for tool components:

```bash
cd openregister
vendor/bin/phpunit tests/Unit/ToolRegistryTest.php
vendor/bin/phpunit tests/Unit/CMSToolTest.php
```

### Integration Tests

Run integration tests that verify end-to-end tool execution:

```bash
vendor/bin/phpunit tests/Integration/ToolExecutionTest.php
```

## Success Criteria

All tests should pass with:
- ✅ Tools appear in agent editor
- ✅ Tools can be enabled/disabled
- ✅ Agent correctly calls tool functions
- ✅ RBAC is enforced
- ✅ Organization boundaries are respected
- ✅ Input validation works
- ✅ Error handling is graceful
- ✅ Logging provides adequate debugging info
- ✅ Performance is acceptable under load
- ✅ Security vulnerabilities are not present

## Reporting Issues

If you find issues during testing:

1. Check logs for detailed error messages
2. Note exact reproduction steps
3. Include agent configuration (tools, prompt, settings)
4. Include sample input that caused the issue
5. Report to the development team with all details

## Next Steps

After successful testing:
1. Enable tools for production agents
2. Monitor usage and performance
3. Create additional tools as needed
4. Update documentation based on user feedback

