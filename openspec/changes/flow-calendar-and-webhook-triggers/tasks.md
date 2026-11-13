# Tasks: flow-calendar-and-webhook-triggers

## 1. Calendar trigger

- [ ] 1.1 `TriggerCalendarNode` (`openregister.trigger-calendar`) registered through `RegisterFlowNodesEvent`, with config validation for register, schema, date property, offset, `workingCalendar` and `runAs`. Verify: unit tests for each refused config.
- [ ] 1.2 A due-moment index per trigger and record, written on object create, update and delete, so a changed date moves the moment and a cleared date or a deleted record removes it. Verify: unit tests for move, clear and delete.
- [ ] 1.3 A background job that queues a run for every moment that has come due, once per record and date value, through `FlowRunService::queue()`. Verify: clock-controlled test that a moment fires once and only once.
- [ ] 1.4 Offsets counted on the working calendar when one is named, through the flow-business-timers calculator. Verify: test across a weekend and a holiday.
- [ ] 1.5 Enabling a trigger records the moment it was enabled; moments before it are skipped and counted on the flow. Verify: test with three past and one future record.

## 2. Incoming-webhook trigger

- [ ] 2.1 `TriggerWebhookNode` (`openregister.trigger-webhook`) with a random address token and a signing secret generated on save, the secret stored encrypted and returned once. Verify: unit test that a read of the flow never returns the secret.
- [ ] 2.2 A public route `POST /api/flows/hooks/{token}` that checks the HMAC-SHA256 signature over the raw body before anything else, then queues a run with the body as input and answers 202 with the run id. Verify: API tests for a good call, a bad signature, an unknown token and a disabled flow.
- [ ] 2.3 Idempotency on the `X-Delivery-Id` header for 24 hours, a body limit of 1 MB and Nextcloud's rate limiter on the route. Verify: API tests for a repeated delivery, an oversize body and a burst.
- [ ] 2.4 Rotating the secret from the trigger's config. Verify: API test that the old secret is refused straight after rotation.

## 3. Interface and docs

- [ ] 3.1 Both triggers in the flow editor palette with their config forms, the webhook form showing the address and the secret once. Verify: e2e that adds each trigger to a flow and saves it.
- [ ] 3.2 `docs/` section on starting a flow from a date and from another system, with a signing example.
