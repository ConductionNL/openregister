# Design: configuration-as-a-deployment

## D-1. A draft is a value beside the live one, not a copy of the instance

Staging by cloning the configuration produces two truths and a merge
nobody wants. A draft is one pending value against one live value, keyed
the same way, so the deployment is an apply and the rollback is an apply
of what was there before.

## D-2. Deployment is all or nothing

A deployment that applies six of nine values leaves an instance in a state
nobody designed and nobody can describe. It applies in one transaction, or
it applies nothing and names the value that refused.

## D-3. Rollback is a new deployment, never a deletion

Rewriting history to undo a change means the record of the weekend the
instance was broken disappears with the fix. A rollback is a deployment
that restores earlier values and says which deployment it restores.

## D-4. The explainer answers value, layer and deployment

"Which value is in effect" is one third of the question. The other two are
which layer set it, instance, register, bundle or subject, and which
deployment last moved it. All three come from the same read, because a
support conversation needs all three.

## D-5. A bundle is a binding, not a template

A template is copied and then drifts, which is the two hundred zaaktypen
problem restated. A bundle stays bound: changing it changes every subject
bound to it, and a subject that needs to differ records an override, which
is then visible as an exception rather than invisible as a copy.

## D-6. A copied matrix lands as a draft

Copying six roles across twenty-one schemas is the act most likely to be
wrong, and it is exactly the act nobody reviews today. Landing it as a
draft makes the review possible without making the copy harder.

## D-7. kind

Code, in OpenRegister.
