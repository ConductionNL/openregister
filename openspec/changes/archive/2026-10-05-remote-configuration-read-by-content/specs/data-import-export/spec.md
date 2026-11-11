# data-import-export

## ADDED Requirements

### Requirement: A remote configuration is read by its content, not its Content-Type

Fetching a configuration from a remote source (github, gitlab or url) SHALL try to decode the body as JSON and then as YAML whatever Content-Type the source answers with; the Content-Type SHALL only decide which is tried first. A body that decodes to neither SHALL be refused with 400 naming the Content-Type.

#### Scenario: a configuration on raw GitHub is previewed

- **GIVEN** a configuration with sourceType github and sourceUrl on raw.githubusercontent.com, which answers valid JSON as `text/plain; charset=utf-8`
- **WHEN** an administrator opens its preview
- **THEN** the preview lists what an import would change instead of answering "Failed to parse response body as JSON or YAML"
- @e2e exclude {remote fetch decoding, covered by FetchHandlerTest}
