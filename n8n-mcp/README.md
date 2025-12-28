# n8n MCP Integration for OpenRegister

This directory contains the n8n Model Context Protocol (MCP) integration, which enables AI agents (like Cursor, Claude Desktop, etc.) to programmatically control n8n workflows.

## What is MCP?

The Model Context Protocol (MCP) is an open standard that enables AI assistants to connect to external tools and data sources. With n8n MCP, AI agents can:

- ✅ List available workflows
- ✅ Execute workflows programmatically
- ✅ Create and modify workflows
- ✅ Debug workflow executions
- ✅ Access n8n node documentation

## Installation

The n8n-mcp module is automatically available when you start the n8n container with the `--profile n8n` flag:

```bash
docker-compose --profile n8n up -d
```

## Configuration

### For Cursor IDE

Add this to your Cursor MCP settings (`~/.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "n8n": {
      "command": "npx",
      "args": [
        "-y",
        "n8n-mcp@latest",
        "--n8n-url",
        "http://localhost:5678",
        "--n8n-username",
        "admin",
        "--n8n-password",
        "admin"
      ]
    }
  }
}
```

**Note:** Replace `admin` / `admin` with your actual n8n credentials.

### For Claude Desktop

Add this to your Claude Desktop config (`claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "n8n": {
      "command": "npx",
      "args": [
        "-y",
        "n8n-mcp@latest",
        "--n8n-url",
        "http://localhost:5678",
        "--n8n-username",
        "admin",
        "--n8n-password",
        "admin"
      ]
    }
  }
}
```

## Usage

Once configured, AI agents can interact with n8n workflows using natural language:

- "List my n8n workflows"
- "Execute the PHPQA auto-fixer workflow"
- "Show me the execution history for workflow X"
- "Create a new workflow that triggers on webhook"

## Security Notes

⚠️ **Important:**

- The default credentials (`admin` / `admin`) are for development only.
- In production, use strong credentials and consider OAuth2 authentication.
- The MCP server connects to n8n via its REST API.
- Never expose n8n credentials in public repositories.

## Troubleshooting

### MCP server not connecting

1. Verify n8n is running: `curl http://localhost:5678/healthz`
2. Check n8n credentials are correct.
3. Restart your AI agent (Cursor, Claude, etc.).
4. Check logs: `docker logs openregister-n8n`

### Workflows not executing via MCP

- See the full troubleshooting guide in `website/docs/technical/n8n-mcp/troubleshooting.md`

## References

- [n8n MCP Package](https://www.npmjs.com/package/n8n-mcp)
- [Model Context Protocol Specification](https://modelcontextprotocol.io/)
- [n8n API Documentation](https://docs.n8n.io/api/)
- [OpenRegister n8n Setup Guide](../website/docs/technical/n8n-mcp/setup.md)

