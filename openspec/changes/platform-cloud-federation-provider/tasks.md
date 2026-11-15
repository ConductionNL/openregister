# Tasks: platform-cloud-federation-provider

- [x] 1.1 Outbound send on the federation provider, beside the existing `shareReceived()` (D-4).
      `OcmShareSender::send()`: a share whose `sharedWith` is a federated cloud id is sent over OCM
      as the `openregister` resource type when it is created, its secret the share token and its
      protocol options exactly what `shareReceived()` reads (this instance's address, token, scope,
      target, permissions, crosses). A sent share waits as `pending` (still served); a share the
      other instance could not take is revoked and answered 502. A token share sends nothing.
- [x] 1.2 Accept, decline and revoke on both sides, with far-side changes reflected here (D-3).
      A far-side accept or decline of a share sent from here, and a withdrawal of a share received
      here, are reflected (`RemoteShareNotification`, called from `notificationReceived()`,
      authenticated by the share secret and the share id). This side now sends them too
      (`OcmShareSender::notify()` from `FederationShareService::setStatus()`): revoking a sent share
      withdraws it there (`SHARE_UNSHARED`), and a received share is answered with
      `POST /api/federation/shares/{id}/accept` or `/decline` (`SHARE_ACCEPTED`, `SHARE_DECLINED`;
      revoking a received share is a decline). A failed notification is logged and does not undo
      the change here.
- [x] 1.3 A federated recipient evaluated by the permission layer as a principal (D-1).
      The share is the grant, already bounded by its creator's rights (FSE-005). The recipient is
      a principal the same evaluator binds: `PermissionHandler::remoteRecipientDenial()` resolves the
      cascade (schema or register, then the object's own block) and runs the same deny pass, with the
      recipient reached as `remote:<cloud id>` and every federated recipient as `remote:*` (a token
      share only as `remote:*`), never as `public` or `authenticated`, and never exempt as an
      administrator. The deny enforcement switch applies as for a local caller (`staging` records
      and admits). `RemoteRecipientGate` asks it on every serve (`read`, in
      `applyShareVisibility()`, so also before any write) and on update and delete (their verbs,
      403). A schema that cannot be read, or a cascade that cannot be resolved, is not served.
      Not covered: a create through a share is judged by the scope and the creator only, because
      there is no object yet for an object-level deny.
- [x] 1.4 A per-share declaration of what crosses, defaulting to the narrowest (D-2).
      `crosses` on the share (column added by `Version1Date20261114130000`), a subset of `data`,
      `files`, `timeline`, set from the `crosses` parameter of `POST /api/federation/shares`.
      `ShareCrosses::declared()` refuses an unknown part when the share is made; nothing declared
      is `['data']`, and so is a share made before the column existed. `FederationController`
      serves every object through `ShareCrosses::shape()`: without `data` only `@self` is sent,
      without `files` or `timeline` those keys leave `@self`. Timeline entries are not served
      by any endpoint yet, so `timeline` only matters once 1.1 sends them.
- [x] 1.5 A schema declaration of whether its objects may be federated, defaulting to not.
      `configuration.federatable: true` (`Schema::isFederatable()`, kept by `ConfigurationEntryRules`)
      is the declaration; `ShareGrantor::federationRefusal()` refuses creating a schema, object or
      query share of a schema that does not declare it, and a whole-register share unless every
      schema in the register declares it (`ShareTargets::registerSchemas()`; a register or schema
      that does not resolve is refused too). Existing shares keep serving.
- [ ] 2.1 `tests/e2e/ci/platform-federation.spec.ts`: share to a second instance, accept, open, revoke, fail to open.
- [x] 2.2 Unit tests: the payload for each declaration, the undeclared schema refusal, an unchanged inbound path. —
      the undeclared schema refusal (`ShareGrantorTest`, `ShareCreationRightsChainTest`,
      `FederationShareServiceTest`); the payload for each declaration (`ShareCrossesTest`,
      `FederationControllerCrossesTest`, `FederationShareServiceTest`). The unchanged inbound
      path: `shareReceived()` is untouched by this change and `CloudFederationShareReceivedGoldenTest`
      still passes unmodified. The remote principal: `RemoteRecipientGateTest` (8, over the real
      evaluator), `FederationControllerRemoteRecipientTest` (2).
- [ ] 2.3 `openspec validate platform-cloud-federation-provider --strict`.
- [ ] 3.1 Hand over to the dossiq lane: declare the federatable schemas and the default payload, register no provider.
