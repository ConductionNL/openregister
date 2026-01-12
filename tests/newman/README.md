# OpenRegister Agent & CMS Tool Testing

This directory contains Newman/Postman test collections for testing OpenRegister agents with OpenCatalogi CMS Tool integration.

## Prerequisites

- Docker containers running (Nextcloud, Ollama, Database)
- Newman installed: `npm install -g newman`
- OpenRegister and OpenCatalogi apps enabled in Nextcloud

## Running Tests

### Using Newman

```bash
# From the openregister directory
newman run tests/newman/agent-cms-testing.postman_collection.json
```

### Using Docker (if Newman not installed locally)

```bash
docker run -t --network host postman/newman run \
  https://raw.githubusercontent.com/.../agent-cms-testing.postman_collection.json
```

### Using Postman GUI

1. Import `agent-cms-testing.postman_collection.json` into Postman
2. Set environment variables if needed:
   - `baseUrl`: http://localhost
   - `username`: admin
   - `password`: admin
3. Run the collection

## Test Collection Structure

### 01 - Setup
- **Check Ollama Status**: Verifies Ollama is running and has models available

### 02 - Agent Tests
- **Create CMS Agent**: Creates an agent with CMS Tool enabled
- **List Agents**: Lists all agents to verify creation
- **Get Agent Details**: Retrieves agent details and verifies CMS Tool is configured

### 03 - Endpoint Tests
- **Create Agent Endpoint**: Creates an endpoint that routes to the agent
- **List Endpoints**: Lists all endpoints to verify creation

### 04 - CMS Operations
- **Create Menu via Agent**: Sends natural language request to create a menu
- **Create Menu Item via Agent**: Sends natural language request to create a menu item
- **List Menu Items**: Verifies menu items were created in the database

### 05 - Cleanup
- **Delete Agent**: Removes the test agent
- **Delete Endpoint**: Removes the test endpoint

## Test Variables

The collection uses the following variables (automatically managed):

- `baseUrl`: Base URL for the Nextcloud instance
- `username`: Admin username for authentication
- `password`: Admin password for authentication
- `agentUuid`: UUID of the created agent (set automatically)
- `endpointUuid`: UUID of the created endpoint (set automatically)
- `menuUuid`: UUID of the created menu (set automatically)

## Expected Results

All tests should pass with:
- ✅ Ollama accessible with models loaded
- ✅ Agent created with CMS Tool enabled
- ✅ Endpoint created and routing to agent
- ✅ Menu created via natural language
- ✅ Menu items created and verified in database
- ✅ Cleanup successful

## Troubleshooting

### Ollama Not Accessible
```bash
# Check Ollama container status
docker ps | grep ollama

# Check Ollama API
curl http://localhost:11434/api/tags
```

### Apps Not Enabled
```bash
# Enable OpenRegister
docker exec -u 33 master-nextcloud-1 php occ app:enable openregister

# Enable OpenCatalogi
docker exec -u 33 master-nextcloud-1 php occ app:enable opencatalogi
```

### Database Tables Missing
```bash
# Check if tables exist
docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud \
  -e "SHOW TABLES LIKE 'oc_openregister%';"
```

## Manual Testing

If API endpoints return 404 errors, you can test directly using PHP scripts:

```bash
# Create agent
docker exec -u 33 master-nextcloud-1 php \
  /var/www/html/apps-extra/openregister/test-agent-creation.php

# Create endpoint
docker exec -u 33 master-nextcloud-1 php \
  /var/www/html/apps-extra/openregister/test-endpoint-creation.php

# Test CMS functionality
docker exec -u 33 master-nextcloud-1 php \
  /var/www/html/apps-extra/openregister/test-cms-functionality.php
```

## Notes

- These tests assume a development environment with default credentials
- For production testing, update the collection variables accordingly
- The CMS Tool integrates with OpenRegister\'s ObjectService for data persistence
- All operations respect RBAC and multi-tenancy boundaries

