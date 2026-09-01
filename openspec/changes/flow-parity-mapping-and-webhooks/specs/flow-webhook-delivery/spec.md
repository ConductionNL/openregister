# flow-webhook-delivery

## ADDED Requirements

### Requirement: A webhook trigger binds the incoming request

A flow triggered by a webhook SHALL receive the incoming request in its run
context: path parameters, query parameters, headers and the decoded body. A flow
SHALL be able to look up an object by an id carried in the call.

Binding SHALL be explicit rather than ambient. The request SHALL be exposed under
a single documented key so a flow author can see what is available, and a node
SHALL NOT reach for request state through any other route — a flow that reads
hidden ambient state behaves differently under a trigger than under a manual run,
and that difference is invisible until it matters.

#### Scenario: A flow looks up by an id from the call
- **WHEN** a webhook is called with an object id in the path and the flow reads it from the bound request
- **THEN** the flow resolves that object, and the same flow run manually with the same context resolves the same object.

#### Scenario: A body is available decoded
- **WHEN** a webhook receives a JSON body
- **THEN** the flow sees the decoded structure, not a raw string requiring a parse step.

### Requirement: A webhook trigger authenticates its caller

A webhook trigger SHALL declare an authentication method. The system SHALL support
at minimum: none (explicitly chosen), a shared secret, and a signature over the
request body. An unauthenticated call to a trigger that declares authentication
SHALL be rejected before the flow is queued, and SHALL NOT create a run.

Authentication SHALL fail closed. A trigger whose declared method cannot be
evaluated — a missing secret, an unknown method — SHALL reject the call rather
than fall through to accepting it. `none` SHALL be reachable only by declaring it,
never as the default for a misconfigured trigger.

#### Scenario: An unauthenticated call is rejected before a run exists
- **WHEN** a webhook declaring a shared secret is called without it
- **THEN** the response is 401, and no flow run row is created.

#### Scenario: A misconfigured trigger rejects rather than opens
- **WHEN** a webhook declares a shared secret but no secret is configured
- **THEN** the call is rejected, and the trigger does NOT behave as if authentication were `none`.

#### Scenario: Open access is explicit
- **WHEN** a webhook trigger is created without choosing an authentication method
- **THEN** it does not accept anonymous calls until `none` is chosen deliberately.

### Requirement: A webhook returns a result

A webhook trigger SHALL be able to answer its caller. A flow SHALL be able to
declare the response it returns — status, headers and body — so a CloudEvents
producer receives a real acknowledgement rather than a fire-and-forget 204.

Returning a result SHALL require the run to execute synchronously; a flow that
declares a response and is queued asynchronously SHALL be rejected at save time
rather than returning an empty body at call time.

#### Scenario: A caller receives the looked-up object
- **WHEN** a webhook flow looks up an object and declares it as the response body
- **THEN** the HTTP response carries that object, and the caller does not need a second request.

#### Scenario: A response-declaring flow cannot be asynchronous
- **WHEN** a flow declaring a response is saved with an asynchronous execution mode
- **THEN** the save is refused with a message naming the conflict, rather than accepted and silently answering empty.

#### Scenario: A failing flow answers honestly
- **WHEN** a webhook flow fails mid-walk
- **THEN** the caller receives an error status rather than a success with an empty body.
