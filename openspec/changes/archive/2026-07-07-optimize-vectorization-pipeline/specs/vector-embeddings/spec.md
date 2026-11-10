## ADDED Requirements

### Requirement: Embedding requests are time-bounded

Every outbound embedding HTTP request SHALL set a connect timeout and a read
timeout. A slow or hung provider SHALL fail the affected chunk within the timeout
and SHALL NOT block the rest of the batch or the cron run indefinitely.

#### Scenario: Hung provider does not stall the batch

- **WHEN** the embedding provider does not respond
- **THEN** the request fails within the configured timeout
- **AND** the chunk is marked failed and the batch continues

### Requirement: Batch embedding uses one request per batch

When embedding multiple texts, OpenRegister SHALL send them as a single provider
request where the provider supports array input, rather than one HTTP request per
text.

#### Scenario: N texts embed in one request

- **WHEN** a batch of N texts is embedded and the provider supports array input
- **THEN** a single embeddings request is issued for the batch

### Requirement: Unchanged content is not re-embedded

Vectorization SHALL skip chunks whose content hash is unchanged and already
embedded. A batch parameter of `0` SHALL mean a sane default page size, not an
uncapped full-table load.

#### Scenario: Re-running on unchanged corpus does no work

- **WHEN** vectorization runs again over an unchanged corpus
- **THEN** no embedding requests are made
- **AND** changing one chunk re-embeds only that chunk

### Requirement: Batch vectorization runs asynchronously

Batch vectorization SHALL run in a background job. The triggering endpoint SHALL
enqueue the work and return promptly with a status handle, not process the batch
synchronously in the request.

#### Scenario: Batch endpoint returns promptly

- **WHEN** a client triggers batch vectorization
- **THEN** the endpoint enqueues a job and returns without waiting for all
  embeddings to complete
