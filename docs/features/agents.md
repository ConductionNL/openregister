# Agents

## Overview

Agents in OpenRegister provide conversational AI capabilities that can interact with your data using natural language. Agents can be configured with different LLM providers, custom prompts, and access to specific data sources through views.

## Architecture

```mermaid
graph TB
    User[User] -->|creates/manages| Agent[Agent]
    User -->|starts| Conversation[Conversation]
    Conversation -->|uses| Agent
    Conversation -->|contains| Message[Messages]
    Agent -->|filters data via| View[Data Views]
    Agent -->|searches in| Files[Files]
    Agent -->|searches in| Objects[Objects]
    Agent -->|uses| Tools[Function Tools]
    Tools -->|RegisterTool| Registers[Registers]
    Tools -->|SchemaTool| Schemas[Schemas]
    Tools -->|ObjectsTool| Objects
    Agent -->|belongs to| Organisation[Organisation]
    Agent -->|accessible by| Groups[Groups]
    Agent -->|can invite| InvitedUsers[Invited Users]
    Conversation -->|belongs to| Organisation
    Conversation -->|owned by| User
    Message -->|role: user/assistant| LLM[LLM Provider]
    Message -->|includes| Sources[RAG Sources]
    
    style Agent fill:#e1f5ff
    style Conversation fill:#fff4e1
    style Message fill:#f0e1ff
    style Organisation fill:#e1ffe1
    style Tools fill:#ffe1ff
```

## Creating an Agent

### Step 1: Basic Settings

Access the Agents page from the main navigation and click **Add Agent**.

#### Required Fields

- **Name**: A unique identifier for your agent (e.g., "Customer Support Assistant")
- **Description**: Optional description of the agent's purpose and capabilities

#### Configuration Options

**Agent Type**
- `chat`: Conversational AI assistant (default)
- `automation`: Automated task execution
- `analysis`: Data analysis and insights
- `assistant`: General purpose assistant

**LLM Provider**
Select the Large Language Model provider for your agent:
- `openai`: OpenAI GPT-4, GPT-3.5 Turbo (excellent function calling)
- `ollama`: Local LLM execution (free, private, requires compatible models)
- `fireworks`: Fast, optimized inference (function calling not yet supported)
- `azure`: Azure OpenAI (Enterprise-grade)

**Model**
Specify the model to use. **For tool-oriented agents, model selection is critical:**

**Recommended for Tools (Function Calling):**
- `mistral-nemo` (Ollama) - ⭐ **BEST for function calling**
- `gpt-4o-mini` (OpenAI) - Excellent but requires API key
- `llama3.1:8b` (Ollama) - Good alternative
- `llama3.2:3b` (Ollama) - Lightweight option

**NOT Recommended for Tools:**
- `mistral:7b` - Unreliable function calling
- `qwen2:0.5b` - Minimal function support  
- `phi3:mini` - Limited tool support

See [Function Calling Documentation](./function-calling.md) for detailed model comparison.

**System Prompt**
Define the agent's behavior and persona. Example:
```
You are a helpful assistant that helps users find information in their document management system. 
Be concise and cite your sources.
```

**Temperature** (0.0 - 2.0)
- Lower values (0.0 - 0.5): More focused and deterministic responses
- Medium values (0.5 - 1.0): Balanced creativity
- Higher values (1.0 - 2.0): More creative and diverse responses
- Default: 0.7

**Max Tokens**
Maximum number of tokens the agent can generate per response. Default: 1000

### Step 2: RAG Configuration

**Enable RAG** (Retrieval-Augmented Generation)
Toggle this to allow the agent to search and retrieve context from your data.

When RAG is enabled, configure:

**Search Mode**
- `hybrid`: Combined keyword + semantic search (recommended)
- `semantic`: AI-powered semantic search only
- `keyword`: Traditional keyword search only

**Number of Sources** (1-20)
How many relevant documents/objects to retrieve as context. Default: 5

**Search Scope**
- `searchFiles`: Include files in the search (toggleable)
- `searchObjects`: Include database objects in the search (toggleable)

**Views** (Data Scope)
Select which views the agent can query. This restricts the agent to specific data sets based on view filters.

### Step 3: Privacy & Sharing

**Privacy Settings**

**Is Private**
- When enabled, only you and invited users can access this agent
- When disabled, all users in your organisation can use the agent

**Invited Users**
Add specific Nextcloud users who should have access to this private agent. Type usernames to search and select.

### Step 4: Resource Quotas

Set usage limits for your agent:

**Request Quota** (per day)
Maximum number of requests the agent can handle per day. Use 0 for unlimited.

**Token Quota** (per request)
Maximum tokens the agent can consume per request. Use 0 for unlimited.

### Step 5: Security (Advanced)

**Group Access Control**
Select Nextcloud groups that have access to this agent. Leave empty to allow all users in your organisation.

## Agent Access Control (RBAC)

Agents follow a hierarchical access model:

1. **Organisation Level**: Agents are tied to the organisation where they were created
2. **Privacy Level**:
   - **Public agents** (`isPrivate = false`): Accessible to all users in the organisation
   - **Private agents** (`isPrivate = true`): Only accessible to the owner and invited users
3. **Group Level**: Additional restriction by Nextcloud group membership

### Access Rules

A user can access an agent if:
- The agent belongs to their current organisation, AND
- The agent is public OR (the agent is private AND the user is the owner OR the user is invited), AND
- No group restrictions exist OR the user belongs to one of the specified groups

### Modification Rights

Only the agent owner can:
- Modify agent settings
- Change privacy settings
- Manage invited users
- Delete the agent

## Function Tools

### Overview

Function tools allow agents to perform actions beyond just providing information. Through function calling (powered by LLM function calling), agents can interact directly with your OpenRegister data and even functionality from other installed Nextcloud apps. This enables agents to:

- **Execute CRUD operations** on registers, schemas, and objects
- **Integrate with other apps** (e.g., OpenCatalogi's CMS tool for managing pages and menus)
- **Automate workflows** by combining multiple tool calls
- **Maintain security** with automatic RBAC and permission checks

:::info For Developers
Want to create custom tools for your app? See the comprehensive developer guides:
- **[Tool Registration Guide](../development/tool-registration.md)** - Step-by-step guide to creating and registering tools
- **[Tool Metadata Architecture](../development/tool-metadata-architecture.md)** - Understanding how tool metadata works
- **[Tool Registration Testing](../development/tool-registration-testing.md)** - Testing your tools
:::

### Built-in Tools (OpenRegister)

**RegisterTool** (`openregister.register`)
- List all accessible registers
- Get details about a specific register
- Create new registers
- Update register properties
- Delete registers

**SchemaTool** (`openregister.schema`)
- List all accessible schemas
- Get schema details including properties
- Create new schemas with JSON Schema definitions
- Update existing schemas
- Delete schemas

**ObjectsTool** (`openregister.objects`)
- Search for objects with filters
- Get object details
- Create new objects conforming to schemas
- Update existing objects
- Delete objects

### Tools from Other Apps

Tools are automatically discovered from installed apps. For example:

**CMSTool** (`opencatalogi.cms`) - _When OpenCatalogi is installed_
- Create and manage pages
- Create and manage menus
- Add menu items linking to pages or URLs

Other apps can register their own tools to extend agent capabilities!

### Enabling Tools

To enable tools for an agent:

1. Open the Edit Agent modal
2. Navigate to the **Tools** tab
3. Toggle the switches for the tools you want to enable
4. Optionally set a **Default User** for cron/background scenarios
5. Save the agent

**Tool Selection Interface:**
- Tools are displayed with **64px icons** for easy identification
- Each tool shows its **name**, **description**, and **app badge**
- **Toggle switches** on the right allow easy enable/disable
- Tools are grouped by app for better organization
- Hover effects provide visual feedback

### Security & Permissions

**Tool execution follows these rules:**

- Tools run with the current user's session permissions (automatic RBAC)
- If no session exists (cron jobs), tools use the agent's configured user
- Tools respect the agent's configured views for data filtering
- All operations are subject to organization boundaries
- Tool usage counts against the agent's request quota

**Example:**
If a user asks an agent to 'create a new person schema', the agent will:
1. Check if SchemaTool is enabled
2. Parse the request parameters
3. Call the tool's `create_schema` function
4. Execute with the user's permissions
5. Return the result in natural language

### Use Cases

**Data Management Assistant**
Enable all three tools to create an agent that can help users manage their data through natural language:
- 'Create a new register called Customers'
- 'Show me all schemas in the Products register'
- 'Find all objects with status=active'

**Schema Designer**
Enable SchemaTool to help users design and modify schemas:
- 'Create a Person schema with name, email, and phone properties'
- 'Add an address property to the Person schema'

**Read-Only Query Agent**
Enable only the list/get functions for a safe, read-only assistant:
- Disable creation, update, and deletion
- Agent can only retrieve and display information

### Configuration Example

```json
{
  'name': 'Data Management Assistant',
  'tools': ['register', 'schema', 'objects'],
  'user': 'admin',
  'views': ['view-uuid-1', 'view-uuid-2'],
  'isPrivate': false
}
```

### Default User Configuration

The **Default User** setting allows you to specify which user's context and permissions should be used when the agent runs without an active user session (e.g., scheduled tasks, background jobs, cron executions).

#### Location

The Default User field is located in the **Settings tab** of the agent configuration, alongside other core agent settings like name, type, and prompt.

#### User Selection

Instead of manually typing a username, you can now:

- **Search and select** from a dropdown list of available users
- **See display names** instead of just user IDs
- **Filter by organisation** - only users from the active organisation are shown
- **Autocomplete** - type to filter matching users
- **Validation** - only existing, valid users can be selected

#### Organisation Filtering

The user picker automatically filters users based on the active organisation:

- If the organisation has specific users configured → Only those users are shown
- If the organisation has no user list → All Nextcloud users are shown (fallback)

#### When Default User is Used

The default user's context is used when:
- Agent runs via scheduled tasks (cron jobs)
- Background jobs execute agent functions
- No active user session exists
- API calls are made without user authentication

#### Benefits

- **No typos**: Can't misspell usernames
- **Better security**: Can't assign non-existent users
- **Organisation-aware**: Respects user boundaries
- **Easier selection**: Click instead of type
- **Better visibility**: See full names, not just IDs

### Best Practices

1. **Least Privilege**: Only enable tools the agent actually needs
2. **Set Default User**: Always configure a default user for background scenarios
3. **Use Views**: Restrict data access using views when possible
4. **Monitor Usage**: Track tool usage through request quotas
5. **Test Thoroughly**: Test agents in a development environment first

## Using Agents in Conversations

### Starting a Conversation

1. Navigate to the AI Assistant page
2. Click **New Conversation**
3. Select an agent from the available agents
4. Click **Start Conversation**

### Agent-Specific Context

When you chat with an agent, it:
- Uses the configured LLM provider and model
- Applies the system prompt to shape responses
- Retrieves context from sources within its configured views
- Respects the `searchFiles` and `searchObjects` settings
- Adheres to token and request quotas

### Conversation Features

- **Automatic Title Generation**: The agent generates a concise title for your conversation based on the first message
- **Context Summarization**: When the conversation grows large, the agent automatically creates a summary to maintain context within token limits
- **Source Citations**: Responses include references to the files and objects used to generate the answer
- **Conversation History**: All messages are persisted and can be reopened at any time

## API Usage

### Listing Agents

```http
GET /index.php/apps/openregister/api/agents
```

Returns all agents accessible to the current user based on RBAC rules.

### Getting a Single Agent

```http
GET /index.php/apps/openregister/api/agents/{id}
```

Returns agent details if the user has access.

### Creating an Agent

```http
POST /index.php/apps/openregister/api/agents
Content-Type: application/json

{
  "name": "Support Assistant",
  "description": "Helps users with common questions",
  "type": "chat",
  "provider": "openai",
  "model": "gpt-4o-mini",
  "prompt": "You are a helpful support assistant...",
  "temperature": 0.7,
  "maxTokens": 1000,
  "enableRag": true,
  "searchFiles": true,
  "searchObjects": true,
  "views": ["uuid-of-view-1", "uuid-of-view-2"],
  "tools": ["objects", "schema"],
  "user": "admin",
  "isPrivate": false,
  "invitedUsers": []
}
```

The agent is automatically assigned to the current user's active organisation.

### Updating an Agent

```http
PUT /index.php/apps/openregister/api/agents/{id}
Content-Type: application/json

{
  "name": "Updated Name",
  "temperature": 0.8
}
```

Only the owner can update an agent.

### Deleting an Agent

```http
DELETE /index.php/apps/openregister/api/agents/{id}
```

Only the owner can delete an agent.

## Best Practices

### Prompt Engineering

1. **Be Specific**: Clearly define the agent's role and constraints
2. **Set Tone**: Specify the desired communication style (professional, friendly, technical)
3. **Define Behavior**: Explain how the agent should handle edge cases
4. **Cite Sources**: Instruct the agent to always reference where information came from

Example:
```
You are a technical documentation assistant for a software company. 
Your role is to help developers find information in the API documentation.

Guidelines:
- Always provide code examples when relevant
- Cite the specific documentation page or section
- If you don't know something, say so - don't make up information
- Use technical language appropriate for developers
- Be concise but thorough
```

### View Configuration

- Limit agents to specific views to reduce context and improve accuracy
- Create focused views for different agent purposes (e.g., "Customer Data", "Product Catalog")
- Use views to implement data access policies

### Temperature Tuning

- **Customer Support** (0.3-0.5): Consistent, factual responses
- **Content Creation** (0.7-0.9): Creative but controlled
- **Brainstorming** (1.0-1.5): Highly creative and diverse ideas

### Resource Management

- Set request quotas for production agents to control costs
- Use token limits to prevent runaway generation
- Monitor agent usage through audit trails

## Troubleshooting

### Agent Not Appearing in List

- Check that you're in the correct organisation
- Verify the agent isn't private (unless you're the owner or invited)
- Ensure your user is in the allowed groups (if group restrictions are set)

### Poor Quality Responses

- Refine the system prompt to be more specific
- Adjust the temperature (lower for more focused responses)
- Increase the number of RAG sources
- Verify the agent has access to relevant views
- Check that `searchFiles` and `searchObjects` are enabled appropriately

### "No context found" Errors

- Ensure RAG is enabled
- Verify the agent has views configured
- Check that the views contain relevant data
- Confirm files and objects are published (only published items are searchable)

### Access Denied Errors

- Verify you're the owner or have been invited (for private agents)
- Check group membership (if group restrictions are set)
- Ensure you're in the correct organisation
- Confirm the agent hasn't been deleted

## Advanced Topics

### Multi-Tenancy

Agents are organisation-scoped. When a user switches organisations:
- They see only agents belonging to the new organisation
- Conversations are also organisation-specific
- Previous conversations become inaccessible until switching back

### Integration with Views

Agents leverage the View system to scope their data access. When configuring an agent:
1. Create views that filter data to what the agent needs
2. Assign these views to the agent
3. The agent will only retrieve context from objects matching these view filters

This provides:
- **Security**: Agents can't access data outside their views
- **Relevance**: Context is limited to pertinent information
- **Performance**: Smaller search space improves response times

### Conversation Architecture

```
User → Conversation → Agent → LLM Provider
         ↓
      Messages
         ↓
      RAG Context (Files + Objects filtered by Views)
```

Each conversation:
- Is tied to a single agent
- Belongs to a specific user and organisation
- Contains a history of messages with sources
- Generates a title automatically
- Can be archived (soft deleted) and restored

