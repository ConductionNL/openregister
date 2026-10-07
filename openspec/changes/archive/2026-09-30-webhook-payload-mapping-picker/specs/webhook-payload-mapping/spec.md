# webhook-payload-mapping Specification (delta)

## ADDED Requirements

### Requirement: REQ-WHMAP-001 A webhook payload mapping is chosen and previewed on screen

The webhook dialog SHALL let an administrator choose the payload mapping and preview the mapped payload, and the preview SHALL equal what a delivery would send.

#### Scenario: an administrator shapes the payload

- **GIVEN** a mapping `to-zgw-notification` and a webhook on object creation
- **WHEN** the administrator picks the mapping in the webhook dialog and presses preview
- **THEN** the preview shows the mapped payload, and the next delivery sends the same shape
- @e2e exclude {preview equals delivery asserted over the real services in tests/Unit/Controller/WebhookMappingPreviewTest.php; the dialog needs a live instance}
