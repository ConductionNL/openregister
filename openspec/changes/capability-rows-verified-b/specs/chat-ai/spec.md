## ADDED Requirements

### Requirement: An administrator picks the language model provider, including one on their own server

The LLM settings SHALL let an administrator pick the provider for embeddings and for chat from
OpenAI, Fireworks AI and Ollama. For Ollama the administrator SHALL give the address of the
Ollama server, so a model that runs on the organisation's own server can be used and no record
text leaves it.

#### Scenario: An administrator switches to a local Ollama server
- **GIVEN** an instance whose embedding provider is OpenAI
- **WHEN** an administrator picks Ollama, enters `http://ollama.intern:11434` and a model, and saves
- **THEN** the LLM settings read back provider `ollama` with that address and model
- **AND** the next embedding is requested from that address

#### Scenario: An unknown provider is refused
- **GIVEN** the LLM settings
- **WHEN** a request sets the embedding provider to `cohere`
- **THEN** generating an embedding fails with "Unsupported embedding provider: cohere"
