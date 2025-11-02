# AI Agents Feature - Implementation TODO

## Current State
✅ Frontend route exists (`/agents`)  
✅ AgentsController renders the page  
❌ No backend logic implemented  
❌ No database schema  
❌ No entity/mapper/service layer  
❌ No API endpoints  

## What Needs to Be Implemented

### 1. Database Layer
- [ ] Create `Agent` entity (`lib/Db/Agent.php`)
- [ ] Create `AgentMapper` (`lib/Db/AgentMapper.php`)
- [ ] Create database migration for `oc_openregister_agents` table
- [ ] Define schema:
  - id, uuid, name, description
  - type (e.g., 'assistant', 'analyzer', 'automation')
  - configuration (JSON)
  - owner, is_public
  - created, updated
  - status (active/inactive)

### 2. Service Layer
- [ ] Create `AgentService` (`lib/Service/AgentService.php`)
  - CRUD operations
  - Agent execution logic
  - Configuration management
  - Access control

### 3. API Layer
- [ ] Update `AgentsController` with API methods:
  - `index()` - List agents
  - `show($id)` - Get agent details
  - `create()` - Create new agent
  - `update($id)` - Update agent
  - `destroy($id)` - Delete agent
  - `execute($id)` - Run agent task
- [ ] Add routes to `appinfo/routes.php`

### 4. Frontend
- [ ] Create agent management UI
- [ ] Agent configuration forms
- [ ] Agent execution interface
- [ ] Results display
- [ ] Integration with objects/registers

### 5. Business Logic
- [ ] Define agent types and capabilities
- [ ] Implement execution engine
- [ ] Add logging and monitoring
- [ ] Error handling
- [ ] Queue system for long-running tasks

## Priority
This is a future feature. Complete current work on Views system first.

