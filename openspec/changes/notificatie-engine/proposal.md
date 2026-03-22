# Notificatie Engine

## Problem
Extend OpenRegister's existing CloudEvent-based event system with user-facing notification delivery. This is NOT a standalone engine — it builds on the event-driven-architecture spec's events and the webhook-payload-mapping spec's delivery infrastructure, adding Nextcloud INotificationManager integration, user preferences, and delivery channels.

## Proposed Solution
Extend the existing implementation with 14 additional requirements.
