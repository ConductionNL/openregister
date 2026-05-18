## ADDED Requirements

### Requirement: Per-app MCP tool provider interface

The system SHALL expose a stable PHP interface
`OCA\OpenRegister\Mcp\IMcpToolProvider` that any installed Nextcloud
app MAY implement to contribute tools to OpenRegister's MCP
`tools/list` and `tools/call` surface. The interface MUST define
exactly three public methods: `getAppId(): string`, `getTools(): array`,
and `invokeTool(string $toolId, array $arguments): array`.

`getAppId()` MUST return the Nextcloud app id that owns the provider
(e.g. `"openbuilt"`, `"decidesk"`). The app id functions as the
namespace for tool ids: every descriptor returned by `getTools()` MUST
have an `id` of the form `{appId}.{toolName}` so the central dispatcher
can route by prefix without ambiguity. The three built-in tool ids
(`registers`, `schemas`, `objects`) are exempt from the dot-prefix rule;
their owning built-in providers return those exact strings from
`getAppId()` and the tools are dispatched by literal name.

`getTools()` MUST return an array of tool descriptor arrays, each with
at minimum `id`, `name`, `description`, and `inputSchema` (a JSON
Schema object). The system MUST NOT validate descriptor structure
beyond surface-level array shape; deeper validation is the provider's
responsibility.

`invokeTool($toolId, $arguments)` MUST return a plain associative
array. A successful call returns the tool's domain payload directly;
a soft failure returns `['isError' => true, 'error' => <code>,
'message' => <human-readable>]` so the dispatcher can surface the
failure to the caller without throwing. The dispatcher MUST also
catch any `\Throwable` raised inside `invokeTool` and convert it to
the same isError envelope so a single bad provider call cannot crash
the request.

#### Scenario: Consumer app contributes tools via the interface
- **GIVEN** an installed Nextcloud app `openbuilt` whose `OCA\OpenBuilt\Mcp\OpenBuiltToolProvider` implements `IMcpToolProvider` and returns 8 descriptors with ids `openbuilt.listApps`, `openbuilt.createApp`, etc., from `getTools()`
- **WHEN** an authenticated client opens an MCP session and calls `tools/list`
- **THEN** the response MUST include all 8 `openbuilt.*` descriptors alongside the 3 built-in tools
- **AND** every `openbuilt.*` descriptor MUST carry the exact `id`, `name`, `description`, and `inputSchema` the provider returned

#### Scenario: Tool call routes by app-id prefix
- **GIVEN** the dispatcher has the OpenBuilt provider and a Decidesk provider registered
- **WHEN** an authenticated client calls `tools/call` with `name = "openbuilt.createApp"` and valid arguments
- **THEN** the dispatcher MUST invoke `OpenBuiltToolProvider::invokeTool("openbuilt.createApp", $arguments)` and NOT the Decidesk provider
- **AND** the response MUST wrap the provider's return value in the existing `{content:[{type:"text", text:<json>}], isError:false}` envelope

#### Scenario: Provider soft-failure surfaces as inner isError
- **GIVEN** an `IMcpToolProvider` whose `invokeTool` returns `['isError' => true, 'error' => 'forbidden', 'message' => 'You must be signed in.']`
- **WHEN** a client calls that tool
- **THEN** the outer MCP envelope MUST still be `isError:false` (the call dispatched cleanly)
- **AND** the inner `content[0].text` JSON MUST contain `isError:true`, the original `error`, and the original `message` verbatim

#### Scenario: Provider exception surfaces as outer isError
- **GIVEN** an `IMcpToolProvider` whose `invokeTool` throws an uncaught `\RuntimeException("boom")`
- **WHEN** a client calls that tool
- **THEN** the dispatcher MUST catch the throwable, log it at error level with the tool id and provider class
- **AND** the response MUST return the existing `{content:[{type:"text", text:<error json>}], isError:true}` envelope per the MCP protocol

### Requirement: Per-app provider discovery

The system SHALL discover `IMcpToolProvider` implementations across
every installed Nextcloud app at the moment `McpToolsService` is
resolved from the DI container. For each app id returned by
`IAppManager::getInstalledApps()` the discovery MUST try three
candidates in order and stop at the first that resolves to an
`IMcpToolProvider` instance:

1. DI alias key `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}`.
2. Naïve FQCN `OCA\{ucfirst($appId)}\Mcp\{ucfirst($appId)}ToolProvider`,
   only attempted when the class exists (no NotFoundException noise
   for apps that ship no provider).
3. Namespace-aware FQCN — read `<namespace>` from
   `{appPath}/appinfo/info.xml`, build
   `OCA\{$namespace}\Mcp\{$namespace}ToolProvider`. The XML MUST be
   read via `file_get_contents` followed by `simplexml_load_string`;
   `simplexml_load_file` MUST NOT be used because Nextcloud's bootstrap
   nulls libxml's external-entity resolver and that call returns false
   on otherwise well-formed files. The third candidate is skipped when
   the parsed `<namespace>` equals `ucfirst($appId)` (already covered
   by candidate #2).

Every failed candidate attempt MUST be logged at warning level with
the app id, the candidate that failed, and the underlying error
message. A failure on one candidate MUST NOT abort the loop; the next
candidate or the next app MUST still be tried.

A failure on every candidate for a given app id MUST NOT propagate to
the caller — the app simply contributes no tools that request. This
keeps the MCP endpoint alive when an app ships a broken provider.

Discovery MUST run once per resolution of `McpToolsService`. The
system MUST NOT cache resolved providers across requests; an
uninstall/install cycle therefore takes effect on the next request
without manual intervention.

#### Scenario: DI alias is the first candidate tried
- **GIVEN** an app `decidesk` whose `Application::register` calls `$context->registerServiceAlias('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::decidesk', DecideskToolProvider::class)`
- **WHEN** `McpToolsService` is resolved from the container
- **THEN** the discovery factory MUST resolve the DI alias and append the DecideskToolProvider to the providers array
- **AND** candidates #2 and #3 MUST NOT be attempted for that app

#### Scenario: CamelCase namespace requires info.xml lookup
- **GIVEN** an app `openbuilt` whose `appinfo/info.xml` declares `<namespace>OpenBuilt</namespace>` (NOT `Openbuilt`) and whose provider class is `OCA\OpenBuilt\Mcp\OpenBuiltToolProvider`
- **AND** the DI alias was not registered (e.g. registered in a per-app container scope unreachable from OR)
- **WHEN** the discovery factory walks installed apps
- **THEN** candidate #2 (`OCA\Openbuilt\Mcp\OpenbuiltToolProvider`) MUST fail with a warning log line
- **AND** candidate #3 MUST read info.xml via `file_get_contents` + `simplexml_load_string`, build `OCA\OpenBuilt\Mcp\OpenBuiltToolProvider`, and append the provider to the array

#### Scenario: Broken provider does not crash discovery
- **GIVEN** an app `brokenprovider` whose provider class throws inside its `__construct`
- **WHEN** the discovery factory tries to resolve it
- **THEN** the factory MUST catch the throwable, log a warning with the appId and error
- **AND** discovery MUST continue with the next installed app
- **AND** `tools/list` MUST still return successfully without the broken provider's tools

#### Scenario: Built-in tools dispatch alongside per-app tools
- **GIVEN** discovery has resolved 3 built-in providers (`registers`, `schemas`, `objects`) and 2 consumer providers (`openbuilt`, `decidesk`)
- **WHEN** a client calls `tools/list`
- **THEN** the response MUST include the 3 built-in tool ids (no dot) AND every consumer tool id (dot-prefixed)
- **AND** a call to `tools/call` with `name="registers"` MUST route to the built-in `RegistersToolProvider` and behave identically to the pre-spec implementation

#### Scenario: Uninstalled app stops contributing tools
- **GIVEN** the OpenBuilt app was installed at request N and uninstalled before request N+1
- **WHEN** the client opens a new MCP session at request N+1 and calls `tools/list`
- **THEN** no `openbuilt.*` tool descriptors MUST appear in the response
- **AND** no error MUST be raised in the discovery factory for the removed appId
