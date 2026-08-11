<?php

/**
 * OpenRegister Tool Registry Facade
 *
 * PUBLIC API — stable cross-app contract (gate-27 / ADR-022).
 *
 * This class is OpenRegister's supported public surface for another
 * Conduction app's engine (e.g. Hermiq's ported agent ToolLoop) to read
 * and invoke the chat-side tool registry (ToolRegistry + McpProviderBridge)
 * without depending on OR's internal wiring directly. Removing or changing
 * the two public method signatures on this class is a breaking change to
 * consumers outside this repository and MUST go through an openspec change
 * that updates the `ai-mcp` capability spec (REQ-006) — this is the
 * contract `hydra-gate-no-phantom-cross-app-rpc` (gate-27) points external
 * callers at, so an `ObjectService->publish()`-style silent removal cannot
 * happen to this surface.
 *
 * The facade is a pure read/invoke wrapper: it changes no ToolRegistry
 * behavior, adds no registration path, and performs NO impersonation —
 * invocations run in the caller's ambient Nextcloud request/session context
 * only (hydra ADR-034 Decision 7).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

use OCA\OpenRegister\Service\ToolRegistry;
use OCA\OpenRegister\Tool\ToolInterface;
use Psr\Log\LoggerInterface;

/**
 * ToolRegistryFacade
 *
 * Small, additive public read/invoke surface over ToolRegistry. Exposes
 * exactly three methods:
 *
 * - listTools()  — every LLPhant-shaped function descriptor known to the
 *   registry (built-in tools AND MCP-bridged per-app tools), optionally
 *   narrowed by a whitelist of registry ids ({appId}.{toolName} — the
 *   hydra ADR-035 Decision 4 `Agent.toolWhitelist` semantics; empty means
 *   "all discovered tools allowed").
 * - describeTools() — the same tools described for a PERSON: app, tool and
 *   the operation (create/read/update/delete/special). Separate from
 *   listTools() because those descriptors go to the model verbatim.
 * - invokeTool() — resolve a function name (or dotted mcpId) back to its
 *   owning ToolInterface and delegate to executeFunction(), returning the
 *   same {result, isError} envelope as McpToolsService::invokeTool().
 *
 * Registration stays exclusively on the ToolRegistrationEvent +
 * ToolRegistrationListener path; this facade cannot mutate the registry.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @psalm-suppress UnusedClass - Public API consumed by external apps (Hermiq), injected via DI
 */
class ToolRegistryFacade
{
    /**
     * Constructor
     *
     * Both dependencies are autowired by Nextcloud's DI container — no
     * explicit Application.php registration is needed (mirrors ToolRegistry
     * itself).
     *
     * @param ToolRegistry    $toolRegistry The chat-side tool registry.
     * @param LoggerInterface $logger       PSR logger.
     *
     * @spec openspec/specs/ai-mcp/spec.md
     */
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List every callable function descriptor known to the tool registry.
     *
     * Flattens registry-id-level tools that expose multiple functions (the
     * built-in RegisterTool alone exposes five) into one list of
     * LLPhant-shaped descriptors (`name`, `description`, `parameters`, plus
     * `mcpId` for MCP-bridged tools) — the exact shape a tool-loop hands an
     * LLM provider. Mirrors what ToolManagementHandler does internally for
     * the in-app chat path, as a public stable read.
     *
     * The whitelist is matched PER FUNCTION, the same dual-form way invokeTool()
     * resolves a call: an entry may name a function's LLPhant-safe `name`
     * (`list_schemas`), its dotted `mcpId` (`openbuild.upsertSchema`), or the
     * registry id of the owning tool (`openregister.schema`, which admits all of
     * that tool's functions).
     *
     * Matching per FUNCTION rather than per TOOL is deliberate and load-bearing.
     * A registry id such as `openregister.schema` is ONE tool exposing five
     * functions (list/get/create/update/delete_schema), and most built-in
     * descriptors carry no `mcpId` at all — so intersecting the whitelist against
     * registry ids made the two id spaces disagree in BOTH directions: an entry
     * naming a real function (`list_schemas`) matched nothing and silently
     * resolved to zero tools, while an entry naming a tool handed over every
     * function it owns — `delete_schema` included — defeating a caller's
     * read-only intent. Consumers store function-level ids (an ADR-035
     * toolWhitelist, Hermiq's per-agent grants) and invokeTool() has always
     * resolved them function-level; this is listTools() keeping the contract the
     * rest of the class already documents.
     *
     * @param array<int,string> $toolWhitelist Optional whitelist of function names,
     *                                         dotted mcpIds and/or registry ids
     *                                         ({appId}.{toolName}). Empty = all
     *                                         discovered tools allowed (ADR-035
     *                                         Decision 4 default semantics).
     *
     * @return array<int,array<string,mixed>> Flattened LLPhant function descriptors.
     *
     * @spec openspec/specs/ai-mcp/spec.md
     */
    public function listTools(array $toolWhitelist=[]): array
    {
        $descriptors = [];

        foreach ($this->resolveRegisteredTools(toolWhitelist: []) as $registryId => $tool) {
            foreach ($tool->getFunctions() as $function) {
                $allowed = $this->functionIsWhitelisted(
                    function: $function,
                    registryId: (string) $registryId,
                    toolWhitelist: $toolWhitelist
                );

                if ($allowed === false) {
                    continue;
                }

                $descriptors[] = $function;
            }
        }

        return $descriptors;
    }//end listTools()

    /**
     * The same tools, described for a PERSON choosing between them.
     *
     * A separate method, deliberately. `listTools()`' descriptors are handed to
     * the LLM as function definitions — `ToolLoop` passes them straight through
     * — so adding keys there would put `app` and `right` into a tool-calling
     * payload, which strict provider APIs reject outright. The agent form needs
     * more than the LLM does; the LLM must keep getting exactly what it got.
     *
     * WHAT AN EDITOR COULD NOT KNOW
     * -----------------------------
     * A descriptor carries `name`, `description` and `parameters` and nothing
     * else, so a picker rendering the catalogue could only show 98 raw ids like
     * `cms_create_page`. Which APP contributed a tool is known only here — it
     * is the first segment of the registry id — and a consumer guessing it from
     * the name prefix would be inventing a mapping the registry already owns.
     *
     * `right` IS DERIVED, and says so. The verb is recovered from the function
     * name, which is a naming convention this registry owns; anything the
     * convention does not cover is `special` rather than guessed into a CRUD
     * bucket it might not belong in. Measured over the 98 registered functions:
     * 87 classify, 11 stay special — `delegateAgent`, `pipelineForecast`,
     * `upsertSchema` and friends, which genuinely are neither create nor update
     * alone.
     *
     * @return array<int, array{name: string, description: string, app: string, tool: string, group: string, right: string}>
     *         One entry per callable function.
     *
     * @spec openspec/specs/ai-mcp/spec.md
     */
    public function describeTools(): array
    {
        $described = [];

        foreach ($this->resolveRegisteredTools(toolWhitelist: []) as $registryId => $tool) {
            // `<app>.<group>` — the app is authoritative here and nowhere else.
            $segments = explode('.', (string) $registryId);
            $app      = ($segments[0] ?? '');
            $group    = ($segments[1] ?? '');

            foreach ($tool->getFunctions() as $function) {
                $name = (string) ($function['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $described[] = [
                    'name'        => $name,
                    'description' => (string) ($function['description'] ?? ''),
                    'app'         => $app,
                    // The FUNCTION, not its group. Grouping collapsed
                    // `cms_create_page` and `cms_create_publication` into one
                    // label — "opencatalogi | cms | create" twice over — and a
                    // picker with two identical rows is one an author cannot
                    // choose from. The group is still available as `group` for
                    // anything that wants to bucket them.
                    'tool'        => $name,
                    'group'       => $group,
                    'right'       => self::rightOf(name: $name),
                ];
            }//end foreach
        }//end foreach

        return $described;

    }//end describeTools()

    /**
     * The operation a tool name describes: a CRUD verb, or `special`.
     *
     * The name is split on underscores, dots AND camelCase humps —
     * `decidesk_listOpenActionItems` hides its verb inside one token, and a
     * split that missed it mislabelled 36 of 98 tools as `special`.
     *
     * Unrecognised is `special`, never a guess. A tool filed under the wrong
     * CRUD right is worse than one filed under none: it tells an administrator
     * granting rights something confident and false.
     *
     * @param string $name The function name.
     *
     * @return string `create`, `read`, `update`, `delete` or `special`.
     *
     * @spec openspec/specs/ai-mcp/spec.md
     */
    private static function rightOf(string $name): string
    {
        $verbs = [
            'create'   => 'create',
            'add'      => 'create',
            'new'      => 'create',
            'import'   => 'create',
            'send'     => 'create',
            'start'    => 'create',
            'list'     => 'read',
            'get'      => 'read',
            'read'     => 'read',
            'search'   => 'read',
            'find'     => 'read',
            'fetch'    => 'read',
            'show'     => 'read',
            'describe' => 'read',
            'export'   => 'read',
            'update'   => 'update',
            'edit'     => 'update',
            'set'      => 'update',
            'patch'    => 'update',
            'move'     => 'update',
            'delete'   => 'delete',
            'remove'   => 'delete',
            'destroy'  => 'delete',
        ];

        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);
        foreach (preg_split('/[_.\s]+/', (string) $spaced) as $part) {
            $verb = strtolower((string) $part);
            if (isset($verbs[$verb]) === true) {
                return $verbs[$verb];
            }
        }

        return 'special';

    }//end rightOf()

    /**
     * Whether one function descriptor is admitted by a whitelist.
     *
     * An empty whitelist admits everything (ADR-035 Decision 4). Otherwise the
     * entry must name the function's `name`, its dotted `mcpId`, or the registry
     * id of the tool that owns it.
     *
     * @param array<string,mixed> $function      The LLPhant function descriptor.
     * @param string              $registryId    Registry id of the owning tool.
     * @param array<int,string>   $toolWhitelist The whitelist to apply.
     *
     * @return bool True when the descriptor should be listed.
     *
     * @spec openspec/specs/ai-mcp/spec.md
     */
    private function functionIsWhitelisted(array $function, string $registryId, array $toolWhitelist): bool
    {
        if ($toolWhitelist === []) {
            return true;
        }

        $candidates = [$registryId];

        $name = ($function['name'] ?? null);
        if (is_string($name) === true && $name !== '') {
            $candidates[] = $name;
        }

        $mcpId = ($function['mcpId'] ?? null);
        if (is_string($mcpId) === true && $mcpId !== '') {
            $candidates[] = $mcpId;
        }

        return (array_intersect($candidates, $toolWhitelist) !== []);
    }//end functionIsWhitelisted()

    /**
     * Invoke a tool function by its descriptor name or dotted mcpId.
     *
     * Resolves $toolId against the same flattened function index listTools()
     * builds — matching either the LLPhant-safe function `name` (e.g.
     * `decidesk_listMeetings`, the form an LLM tool-call echoes back) or the
     * dotted `mcpId` (e.g. `decidesk.listMeetings`, the form an ADR-035
     * toolWhitelist stores) — and delegates to the owning tool's
     * executeFunction().
     *
     * NO impersonation: there is deliberately no $userId / acting-user /
     * agent parameter. The call executes in the caller's ambient Nextcloud
     * request/session context only; per-object IDOR boundaries remain each
     * provider's own responsibility (IMcpToolProvider contract, hydra
     * ADR-034 Decision 7).
     *
     * @param string              $toolId    Function name or dotted mcpId.
     * @param array<string,mixed> $arguments Decoded arguments object.
     *
     * @return array{result: array<string,mixed>, isError: bool} Result envelope
     *         (same shape as McpToolsService::invokeTool()).
     *
     * @spec openspec/specs/ai-mcp/spec.md
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        $tool = $this->findOwningTool(toolId: $toolId);

        if ($tool === null) {
            return [
                'result'  => ['error' => 'Unknown tool: '.$toolId],
                'isError' => true,
            ];
        }

        try {
            $result = $tool->executeFunction($toolId, $arguments);

            return [
                'result'  => $result,
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            // Catch \Throwable, not \Exception: a TypeError from a malformed
            // argument must not escape the facade as an uncaught 500 —
            // matching the \Throwable catches in McpProviderBridge and
            // McpToolsService.
            $this->logger->error(
                message: '[ToolRegistryFacade] Tool invocation failed',
                context: ['tool' => $toolId, 'error' => $e->getMessage()]
            );

            return [
                'result'  => ['error' => $e->getMessage()],
                'isError' => true,
            ];
        }//end try
    }//end invokeTool()

    /**
     * Resolve the registered ToolInterface instances, optionally whitelisted.
     *
     * @param array<int,string> $toolWhitelist Registry ids to keep (empty = all).
     *
     * @return array<string,ToolInterface> Map of registry id to tool instance.
     */
    private function resolveRegisteredTools(array $toolWhitelist): array
    {
        $registryIds = array_keys($this->toolRegistry->getAllTools());

        if ($toolWhitelist !== []) {
            $registryIds = array_values(array_intersect($registryIds, $toolWhitelist));
        }

        $tools = [];
        foreach ($registryIds as $registryId) {
            $tool = $this->toolRegistry->getTool($registryId);
            if ($tool === null) {
                continue;
            }

            $tools[$registryId] = $tool;
        }

        return $tools;
    }//end resolveRegisteredTools()

    /**
     * Find the tool whose function descriptors match the given identifier.
     *
     * Matches a descriptor's `name` (LLPhant-safe form) or `mcpId` (dotted
     * form) — the same dual-form resolution McpProviderBridge::resolveMcpId()
     * already performs for bridged tools. First match in registry iteration
     * order wins (built-ins first, per ToolRegistrationListener ordering),
     * consistent with McpToolsService::findProviderForTool().
     *
     * @param string $toolId Function name or dotted mcpId.
     *
     * @return ToolInterface|null The owning tool, or null when no descriptor matches.
     */
    private function findOwningTool(string $toolId): ?ToolInterface
    {
        foreach ($this->resolveRegisteredTools(toolWhitelist: []) as $tool) {
            foreach ($tool->getFunctions() as $function) {
                $matchesName  = (($function['name'] ?? '') === $toolId);
                $matchesMcpId = (($function['mcpId'] ?? '') === $toolId);

                if ($matchesName === true || $matchesMcpId === true) {
                    return $tool;
                }
            }
        }

        return null;
    }//end findOwningTool()
}//end class
