# Workflow Integration

## Problem
Integrate BPMN-style workflow automation with register operations via n8n (primary) and other pluggable workflow engines (Windmill, future). Register events (create, update, delete, status change) MUST trigger configurable workflows for process automation, enrichment, validation, escalation, approval chains, and scheduled tasks. The integration MUST support zero-coding workflow configuration for functional administrators and provide full observability into workflow executions via logging, status tracking, and audit trails.
**Tender demand**: 38% of analyzed government tenders require workflow/process automation capabilities.

## Proposed Solution
Implement Workflow Integration following the detailed specification. Key requirements include:
- Requirement: n8n SHALL be the primary workflow engine
- Requirement: Register events MUST trigger workflow executions
- Requirement: Schema hooks MUST support configurable workflow triggers
- Requirement: Workflows MUST use the Workflow Execution API
- Requirement: Workflow execution status MUST be tracked and logged

## Scope
This change covers all requirements defined in the workflow-integration specification.

## Success Criteria
- n8n is auto-discovered when installed as ExApp
- n8n adapter routes through ExApp proxy
- n8n MCP integration for AI-assisted workflow creation
- Multiple engines active simultaneously
- Trigger workflow on object creation
