# Tasks: enforce-organisation-special-rights

## 1. Find where each right applies
- [x] 1.1 `agent_use`: chat with an agent, MCP session as an agent (design.md)
- [x] 1.2 `llm_use`: every chat message (design.md)
- [x] 1.3 `object_publish`: no object publish action exists; asked as a question
- [x] 1.4 `dashboard_view`: the dashboard API is administrator only; asked as a question

## 2. The reader
- [x] 2.1 `OrganisationSpecialRights::mayUse()`: administrators pass, `*`, missing key, no organisation, load failure
- [x] 2.2 `OrganisationSpecialRights::openToEveryoneInEveryOrganisation()`, refusing `grant_manage`
- [x] 2.3 `Organisation::getDefaultAuthorization()` opens the four rights, not `grant_manage`

## 3. Enforcement
- [x] 3.1 `ChatService::processMessage()` checks `llm_use` and `agent_use` before storing or calling a model
- [x] 3.2 `ChatStreamController::stream()` checks before the first heartbeat and emits `forbidden`
- [x] 3.3 `McpAgentScope::loadAgent()` checks `agent_use` on initialize and every later call

## 4. Who changes special rights
- [x] 4.1 `OrganisationController::update()` refuses a change to any of the five from a non-owner, non-administrator (403)
- [x] 4.2 The Special Rights tab is read-only for them, and offers "Everyone"

## 5. Upgrade
- [x] 5.1 Repair step `OpenSpecialRightsToEveryone`, post-migration, once
- [x] 5.2 Upgrade notes in proposal.md and docs/Features/organisations.md

## 6. Docs
- [x] 6.1 Remove the non-existent `hasSpecialRight()` from docs/Features/organisations.md

## 7. Tests
- [x] 7.1 Per right: holder allowed, non-holder refused with a real status, admin allowed
- [x] 7.2 The upgrade step, found through info.xml, keeps every current user able
- [x] 7.3 A member is refused with 403 for each special right; owner and administrator are not

## 8. Open
- [ ] 8.1 Ruben: what `object_publish` should guard (design.md Q1)
- [ ] 8.2 Ruben: what `dashboard_view` should guard (design.md Q2)
- [ ] 8.3 Live check on a running instance: a narrowed `llm_use` refuses a chat from the widget
