# Event-Driven Architecture

## Problem
OpenRegister implements a comprehensive event-driven architecture built on Nextcloud's `IEventDispatcher` (OCP\EventDispatcher\IEventDispatcher) that enables loose coupling between internal components and external systems. Every mutation across all entity types -- Objects, Registers, Schemas, Sources, Configurations, Views, Agents, Applications, Conversations, and Organisations -- dispatches a typed PHP event that can be consumed by any Nextcloud app, delivered to external systems via webhooks in CloudEvents v1.0 format, or pushed to real-time subscribers via GraphQL SSE. The architecture distinguishes between pre-mutation events (ObjectCreatingEvent, ObjectUpdatingEvent, ObjectDeletingEvent) that implement `StoppableEventInterface` to allow hooks to reject or modify operations, and post-mutation events (ObjectCreatedEvent, ObjectUpdatedEvent, ObjectDeletedEvent) that notify downstream systems after persistence is complete.
**Source**: Gap identified in cross-platform analysis; four platforms implement event-driven architectures. Core implementation exists with 39+ typed event classes in `lib/Event/`, 8 event listeners in `lib/Listener/`, and webhook delivery infrastructure.

## Proposed Solution
Implement Event-Driven Architecture following the detailed specification. Key requirements include:
- Requirement: All entity mutations MUST dispatch typed PHP events via IEventDispatcher
- Requirement: Pre-mutation events MUST support rejection and data modification via StoppableEventInterface
- Requirement: Event listeners MUST be registered in Application.php via registerEventListener
- Requirement: WebhookEventListener MUST extract structured payloads from all event types
- Requirement: Webhook delivery MUST support CloudEvents v1.0 format with configurable payload strategies

## Scope
This change covers all requirements defined in the event-driven-architecture specification.

## Success Criteria
- Object creation dispatches ObjectCreatingEvent then ObjectCreatedEvent
- Object update dispatches ObjectUpdatingEvent then ObjectUpdatedEvent with old and new state
- Object deletion dispatches ObjectDeletingEvent then ObjectDeletedEvent
- Non-object entity mutations dispatch corresponding typed events
- Lock and revert operations dispatch specialized events
