## 1. Interface and built-in providers

- [ ] 1.1 Create `lib/Mcp/IMcpToolProvider.php` defining the three-method contract: `getAppId(): string`, `getTools(): array<int, array<string, mixed>>`, `invokeTool(string $toolId, array $arguments): array<string, mixed>`. PSR-4 namespace `OCA\OpenRegister\Mcp`. Docblock states the per-tool envelope expected from `invokeTool` (a plain associative array; `isError:true` is recognised as a soft failure that the caller will wrap).
- [ ] 1.2 Create `lib/Mcp/BuiltIn/RegistersToolProvider.php` implementing `IMcpToolProvider`. `getAppId()` returns `'registers'`. `getTools()` returns the single descriptor currently inlined in `McpToolsService::getRegistersTool()`. `invokeTool` delegates to a private `executeRegisters()` whose body is the current `executeRegisters` from `McpToolsService` (constructor-injected `RegisterService`).
- [ ] 1.3 Create `lib/Mcp/BuiltIn/SchemasToolProvider.php` — same pattern, wraps `SchemaMapper`, `executeSchemas`.
- [ ] 1.4 Create `lib/Mcp/BuiltIn/ObjectsToolProvider.php` — same pattern, wraps `ObjectService`, `executeObjects`.
- [ ] 1.5 Delete the now-orphaned private `getRegistersTool`/`getSchemasTool`/`getObjectsTool`/`executeRegisters`/`executeSchemas`/`executeObjects` methods from `McpToolsService` after step 2.x is complete.

## 2. Refactor McpToolsService

- [ ] 2.1 Change the constructor signature to `__construct(array $providers, LoggerInterface $logger)`. Validate `$providers` are `IMcpToolProvider` instances at construct time (throw `InvalidArgumentException` on non-conforming).
- [ ] 2.2 Replace `listTools()`: iterate providers, accumulate `$provider->getTools()` into a single array, return `['tools' => $accumulated]`. Preserves insertion order so built-ins remain first when the factory feeds them first.
- [ ] 2.3 Replace `callTool($name, $args)`: extract the prefix (everything before the first `.`, or the full name if no dot), find the unique provider whose `getAppId()` equals the prefix, call `$provider->invokeTool($name, $args)`. Wrap result in the existing `{content:[{type:text,text:json}], isError:false}` envelope. On no-matching-provider throw `InvalidArgumentException('Unknown tool: '.$name)` — the controller already handles that.
- [ ] 2.4 On provider exception inside `invokeTool`, catch `\Throwable`, log at error level with tool name + provider class, return the existing isError envelope. Same behaviour the current code has for the built-ins.

## 3. Factory in Application

- [ ] 3.1 Add private method `registerMcpToolProviders(IRegistrationContext $context): void` to `lib/AppInfo/Application.php`. Call it from `register()` after `registerObjectInteractionServices`.
- [ ] 3.2 Inside the closure passed to `registerService(McpToolsService::class, ...)`: resolve `RegistersToolProvider`, `SchemasToolProvider`, `ObjectsToolProvider` from the container into the providers list (built-ins always first).
- [ ] 3.3 In the same closure, fetch `IAppManager`, iterate `getInstalledApps()`, try the three discovery candidates per app in order — DI alias `OCA\OpenRegister\Mcp\IMcpToolProvider::{appId}` → naïve FQCN `OCA\{ucfirst($appId)}\Mcp\{ucfirst($appId)}ToolProvider` → namespace-from-info.xml FQCN. Stop at the first candidate that resolves to an `IMcpToolProvider`.
- [ ] 3.4 For the info.xml candidate, use `file_get_contents($appPath.'/appinfo/info.xml')` + `simplexml_load_string($body)`. Do NOT use `simplexml_load_file` — NC's bootstrap nulls libxml's entity resolver and that call returns false on otherwise well-formed files.
- [ ] 3.5 Wrap each candidate attempt in `try/catch (\Throwable)`. Log `warning` with `{appId, candidate, error}` context on failure. Never let a single bad app abort enumeration.
- [ ] 3.6 Skip the namespace-from-info.xml candidate when the parsed `<namespace>` equals `ucfirst($appId)` (already covered by candidate #2 — avoid noisy duplicate attempts).
- [ ] 3.7 Log a single `info` line at the end of the closure listing the providers that resolved successfully (`{appId, class}`) so ops can confirm discovery.

## 4. Consumer-side validation

- [ ] 4.1 Confirm the OpenBuilt provider at `openbuilt/lib/Mcp/OpenBuiltToolProvider.php` still implements `OCA\OpenRegister\Mcp\IMcpToolProvider` after step 1.1 lands (PHP autoloader resolves the interface from OpenRegister's lib). No code change in OpenBuilt expected.
- [ ] 4.2 Same check on `openregister/custom_apps/decidesk/lib/Mcp/DecideskToolProvider.php`. No code change expected.
- [ ] 4.3 Confirm both consumer apps' `Application::register()` still call `registerServiceAlias('OCA\\OpenRegister\\Mcp\\IMcpToolProvider::{appId}', {ProviderClass}::class)`. These registrations remain the canonical candidate #1 path.

## 5. Direct-MCP smoke

- [ ] 5.1 Open an MCP session: `POST /api/mcp` with `method:initialize`; assert HTTP 200 + `Mcp-Session-Id` header.
- [ ] 5.2 `method:tools/list` against that session; assert `tools` array contains at minimum 16 entries (3 built-ins + 8 OpenBuilt + 5 Decidesk). Assert each entry has `name`, `description`, `inputSchema` and that the names match the published tool ids.
- [ ] 5.3 `method:tools/call` for `openbuilt.listApps` with `{}`; assert `result.isError === false` and the inner JSON has `success: true`.
- [ ] 5.4 `method:tools/call` for `decidesk.listRecentMeetings` with `{"limit":3}`; assert the inner JSON has `success: true`.
- [ ] 5.5 `method:tools/call` for `registers` with `{"action":"list","limit":5}` (built-in); assert behaviour unchanged from pre-spec.

## 6. Persona-harness regression

- [ ] 6.1 Run all 11 persona scenarios in `tests/mcp-personas/scenarios/` back-to-back via `php tests/mcp-personas/harness.php scenarios/*.json`. Required result: every scenario's `tool_succeeded` and `db_row_exists` asserts pass.
- [ ] 6.2 Tail `data/nextcloud.log` for any `[McpToolsService] Resolve failed` entries that are NOT one of the discovery candidates we expect to miss (built-in NC apps like `viewer`, `theming` have no MCP provider — those warnings are expected and non-fatal). Document the expected-noise list in the PR description.

## 7. Cleanup

- [ ] 7.1 Remove unused `use` statements + private helpers from `McpToolsService` after 2.x. Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) on the openregister side; resolve any pre-existing issues introduced by the move.
- [ ] 7.2 No changes to controller-side code (`McpServerController`, `McpController`) are expected; if any surface, treat as a regression of this change and fix.
