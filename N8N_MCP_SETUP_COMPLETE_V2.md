# n8n MCP Integration - UPDATED with n8n-mcp-server ✅

**Date:** December 28, 2025  
**Status:** WORKING - Real MCP server tested successfully!  
**Version:** 2.0.0 (Updated with n8n-mcp-server)

## Major Update

We discovered and tested **n8n-mcp-server**, a proper MCP server that can actually **execute n8n workflows**!

### What Changed

**Before:**
- Used `n8n-mcp` - a documentation-only package
- Could only browse n8n node documentation
- Could not execute workflows

**After:**
- Using `n8n-mcp-server` - actual workflow control
- Can list, execute, and monitor workflows
- Uses n8n API with authentication
- **TESTED AND WORKING** ✅

## Test Results

```bash
Starting n8n MCP Server...
Verifying n8n API connectivity...
✅ Successfully connected to n8n API at http://localhost:5678/api/v1
n8n MCP Server running on stdio
```

## Configuration

### Cursor IDE (`~/.cursor/mcp.json`)

```json
{
  "mcpServers": {
    "n8n": {
      "command": "npx",
      "args": [
        "-y",
        "n8n-mcp-server"
      ],
      "env": {
        "N8N_API_URL": "http://localhost:5678/api/v1",
        "N8N_API_KEY": "your-api-key-here"
      }
    }
  }
}
```

### Claude Desktop

```json
{
  "mcpServers": {
    "n8n": {
      "command": "npx",
      "args": [
        "-y",
        "n8n-mcp-server"
      ],
      "env": {
        "N8N_API_URL": "http://localhost:5678/api/v1",
        "N8N_API_KEY": "your-api-key-here"
      }
    }
  }
}
```

## API Key

API key generated: `eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxODRkYWRlZS1kYTAzLTRjMTctOWRjYy04YTVkZmQ1MTdiY2QiLCJpc3MiOiJuOG4iLCJhdWQiOiJwdWJsaWMtYXBpIiwiaWF0IjoxNzY2OTE3MjA2LCJleHAiOjE3Njk0Njg0MDB9.cd1zHYEFp1AFxQBxG7ODT3PLJpQhMy9IeZJ9gOgEnFY`

**Note:** This key expires on **2026-01-31**. Generate a new one after that date.

## Available MCP Tools

Once configured, the n8n MCP server provides these tools:

- **list_workflows** - List all workflows
- **get_workflow** - Get workflow details
- **execute_workflow** - Execute a workflow by ID
- **get_executions** - Get execution history
- **create_workflow** - Create new workflow
- **update_workflow** - Update existing workflow
- **delete_workflow** - Delete workflow

## Testing

Run the test script:

```bash
cd /path/to/openregister
export N8N_API_KEY='your-api-key-here'
./test-n8n-mcp.sh
```

Expected output:
```
✅ n8n is running
✅ API key is set
✅ API access successful
✅ n8n-mcp-server started successfully
✅ Connected to n8n API
```

## Next Steps

1. ✅ n8n-mcp-server tested and working
2. ✅ API key generated
3. ✅ Configuration updated in ~/.cursor/mcp.json
4. ✅ Documentation updated
5. ✅ Test script created
6. ⏳ **Restart Cursor IDE** (required for MCP changes)
7. ⏳ Test: Ask AI "List my n8n workflows"

## Package Comparison

| Package | Purpose | Can Execute Workflows | Status |
|---------|---------|----------------------|--------|
| `n8n-mcp` | Documentation | ❌ No | ❌ Not used |
| `n8n-mcp-server` | Workflow Control | ✅ Yes | ✅ ACTIVE |
| `@r_masseater/n8n-mcp-server` | Workflow Control | ✅ Yes | ⚠️ Requires Node 22+ |
| `N8N2MCP` | Workflow→MCP Tool | ✅ Yes | 💡 Future option |

## Files Updated

1. ✅ `~/.cursor/mcp.json` - Updated with n8n-mcp-server config
2. ✅ `n8n-mcp/package.json` - Changed dependency to n8n-mcp-server
3. ✅ `website/docs/technical/n8n-mcp/setup.md` - Updated configuration examples
4. ✅ `test-n8n-mcp.sh` - New test script
5. ✅ `N8N_MCP_SETUP_COMPLETE_V2.md` - This file

## References

- [n8n-mcp-server on npm](https://www.npmjs.com/package/n8n-mcp-server)
- [n8n API Documentation](https://docs.n8n.io/api/)
- [Model Context Protocol](https://modelcontextprotocol.io/)

---

**Status:** ✅ Tested and working - Ready for AI agent integration  
**Review:** Requires Cursor restart to load MCP resources



