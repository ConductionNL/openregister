# Tasks: property-explicit-reveal

- [x] 1.1 Tests (red on the first version): ExplicitRevealTest (11), ExplicitRevealRoutesTest (4).
- [x] 1.2 Masker at the render boundary for every reader; GraphQL, access links, MCP, export, history.
- [x] 1.3 `_reveal` only on show, per object; filter/sort refused; no free text; no facets.
- [x] 1.4 Restore on single and bulk saves.
- [x] 1.4b Re-review: related-row refusal, rendered revert, authorization form only, uuid grants, admin trails (ExplicitRevealReReviewTest, 5, red on 1415a03c3).
- [ ] 1.5 dossiq: brpPerson.citizenServiceNumber `audit: true, reveal: "explicit"`; server code that needs the BSN uses `trustedDuring()`.
- [ ] 1.6 Live: one property.revealed row per Tonen, none per page view; a list or export shows the mask.
