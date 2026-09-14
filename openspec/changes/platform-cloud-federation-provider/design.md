# Design: platform-cloud-federation-provider

## D-1. A remote principal is a principal

Treating federation as a parallel system gives the fleet two access
models, and the one that is checked second decides. A federated recipient
is a principal the permission layer evaluates like any other, which is the
conclusion `object-level-sharing-and-private-scope` already reached.

## D-2. What crosses is declared, not assumed

Sharing a case with the omgevingsdienst should not hand over the internal
notes by default. Each share declares what crosses: data, files, public
entries, or a subset. The default is the narrowest of those.

## D-3. The lifecycle is two-sided

A share that can be sent and not revoked is a share nobody will send. Send,
accept, decline and revoke all exist on both sides, and a state change on
the far side is reflected here rather than inferred.

## D-4. Receiving stays exactly as it is

The inbound path works today. This change adds around it and does not
rewrite it, because a federation regression is invisible until somebody at
another organisation cannot open something.

## D-5. kind

Code, in OpenRegister.
