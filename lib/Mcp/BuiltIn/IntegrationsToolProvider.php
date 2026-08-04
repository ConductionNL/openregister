<?php

/**
 * Built-in integrations MCP tool provider.
 *
 * Makes the pluggable integration registry (ADR-019) discoverable to AI
 * agents through OpenRegister's MCP surface (ADR-022 "MCP discovery"
 * abstraction). Each registered IntegrationProvider becomes addressable
 * through the single namespaced tool `openregister.integrations`, whose
 * `action` argument selects between discovery (`list-integrations`) and
 * per-object operations (`list`, `get`, `link`, `create`).
 *
 * The tool delegates straight to IntegrationRegistry + the matched
 * IntegrationProvider, so it inherits the providers' own storage-strategy
 * semantics: query-time/list-only providers throw NotImplementedException
 * on `link`/`create`, which McpToolsService surfaces as an MCP error
 * envelope rather than a fatal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction BV
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/pluggable-integration-registry/proposal.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\BuiltIn;

use InvalidArgumentException;
use OCA\OpenRegister\Mcp\IMcpToolProvider;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;

/**
 * IntegrationsToolProvider
 *
 * Built-in IMcpToolProvider exposing the integration registry as an
 * AI-agent discovery + invocation surface. Registered alongside the other
 * built-ins in Application::registerMcpToolProviders().
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp\BuiltIn
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 */
class IntegrationsToolProvider implements IMcpToolProvider
{

    /**
     * Tool id for the integrations tool.
     */
    public const TOOL_ID = 'openregister.integrations';

    /**
     * Constructor.
     *
     * @param IntegrationRegistry $registry Integration registry.
     */
    public function __construct(
        private readonly IntegrationRegistry $registry
    ) {
    }//end __construct()

    /**
     * Returns the owning app id.
     *
     * @return string Always "openregister"
     */
    public function getAppId(): string
    {
        return 'openregister';
    }//end getAppId()

    /**
     * Returns tool descriptors.
     *
     * @return list<array{id: string, name: string, description: string, inputSchema: array}>
     */
    public function getTools(): array
    {
        return [
            [
                'id'          => self::TOOL_ID,
                'name'        => 'integrations',
                'description' => 'Discover and query pluggable integrations linked to OpenRegister objects '
                    .'(e.g. files, calendar, contacts, xwiki). Use action "list-integrations" to enumerate '
                    .'which integrations exist on this instance, then "list"/"get" to read linked things for '
                    .'a specific object, and "link"/"create" to attach a new linked thing where the '
                    .'integration supports it.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'action'        => [
                            'type'        => 'string',
                            'enum'        => ['list-integrations', 'list', 'get', 'link', 'create'],
                            'description' => 'list-integrations enumerates available integrations; '
                                .'list/get/link/create operate on linked things for one object.',
                        ],
                        'integrationId' => [
                            'type'        => 'string',
                            'description' => 'Stable integration id (e.g. "files", "calendar"). '
                                .'Required for list/get/link/create.',
                        ],
                        'register'      => [
                            'type'        => 'string',
                            'description' => 'Register slug or numeric id (required for list/get/link/create).',
                        ],
                        'schema'        => [
                            'type'        => 'string',
                            'description' => 'Schema slug or numeric id (required for list/get/link/create).',
                        ],
                        'objectId'      => [
                            'type'        => 'string',
                            'description' => 'Object uuid that owns the linked things (required for '
                                .'list/get/link/create).',
                        ],
                        'entityId'      => [
                            'type'        => 'string',
                            'description' => 'Linked-thing id (required for get).',
                        ],
                        'filters'       => [
                            'type'        => 'object',
                            'description' => 'Optional list filters (_limit, _page, _search).',
                        ],
                        'payload'       => [
                            'type'        => 'object',
                            'description' => 'New linked-thing fields (for link/create).',
                        ],
                    ],
                    'required'   => ['action'],
                ],
            ],
        ];
    }//end getTools()

    /**
     * Invoke the integrations tool.
     *
     * @param string               $toolId    Must be "openregister.integrations"
     * @param array<string, mixed> $arguments Tool arguments with action and operation params
     *
     * @return array<string, mixed> JSON-encodable result
     *
     * @throws InvalidArgumentException If action is unknown or required params missing
     */
    public function invokeTool(string $toolId, array $arguments): array
    {
        $action = $arguments['action'] ?? null;

        return match ($action) {
            'list-integrations' => $this->listIntegrations(),
            'list'              => $this->listLinked(arguments: $arguments),
            'get'               => $this->getLinked(arguments: $arguments),
            'link', 'create'    => $this->createLinked(arguments: $arguments),
            default             => throw new InvalidArgumentException('Unknown action: '.$action),
        };
    }//end invokeTool()

    /**
     * Discovery: enumerate every registered integration with its metadata.
     *
     * Mirrors the OCS capability's public surface so an AI agent gets the
     * same view of "what exists" regardless of which discovery channel it
     * uses.
     *
     * @return array<string, mixed> Registered ids + per-integration descriptors
     */
    private function listIntegrations(): array
    {
        $integrations = [];
        foreach ($this->registry->list() as $provider) {
            $integrations[] = [
                'id'              => $provider->getId(),
                'label'           => $provider->getLabel(),
                'group'           => $provider->getGroup(),
                'enabled'         => $provider->isEnabled(),
                'requiredApp'     => $provider->getRequiredApp(),
                'storageStrategy' => $provider->getStorageStrategy(),
            ];
        }

        return [
            'registered'   => $this->registry->listIds(),
            'integrations' => $integrations,
        ];
    }//end listIntegrations()

    /**
     * List linked things the named integration exposes for an object.
     *
     * @param array<string, mixed> $arguments Must contain integrationId, register, schema, objectId
     *
     * @return array<string, mixed> Items wrapper
     */
    private function listLinked(array $arguments): array
    {
        $provider = $this->resolveProvider(arguments: $arguments);
        $filters  = $arguments['filters'] ?? [];
        if (is_array($filters) === false) {
            $filters = [];
        }

        $items = $provider->list(
            $this->requireParam(arguments: $arguments, param: 'register'),
            $this->requireParam(arguments: $arguments, param: 'schema'),
            $this->requireParam(arguments: $arguments, param: 'objectId'),
            $filters
        );

        return ['items' => $items];
    }//end listLinked()

    /**
     * Fetch a single linked thing by id.
     *
     * @param array<string, mixed> $arguments Must contain integrationId, register, schema, objectId, entityId
     *
     * @return array<string, mixed> The linked thing
     */
    private function getLinked(array $arguments): array
    {
        $provider = $this->resolveProvider(arguments: $arguments);

        return $provider->get(
            $this->requireParam(arguments: $arguments, param: 'register'),
            $this->requireParam(arguments: $arguments, param: 'schema'),
            $this->requireParam(arguments: $arguments, param: 'objectId'),
            $this->requireParam(arguments: $arguments, param: 'entityId')
        );
    }//end getLinked()

    /**
     * Create / attach a new linked thing.
     *
     * Providers with query-time/list-only storage throw
     * NotImplementedException, which McpToolsService renders as an error
     * envelope.
     *
     * @param array<string, mixed> $arguments Must contain integrationId, register, schema, objectId, payload
     *
     * @return array<string, mixed> The created linked thing
     */
    private function createLinked(array $arguments): array
    {
        $provider = $this->resolveProvider(arguments: $arguments);
        $payload  = $arguments['payload'] ?? [];
        if (is_array($payload) === false) {
            $payload = [];
        }

        return $provider->create(
            $this->requireParam(arguments: $arguments, param: 'register'),
            $this->requireParam(arguments: $arguments, param: 'schema'),
            $this->requireParam(arguments: $arguments, param: 'objectId'),
            $payload
        );
    }//end createLinked()

    /**
     * Resolve and validate the integration provider for an operation.
     *
     * @param array<string, mixed> $arguments Must contain integrationId
     *
     * @return IntegrationProvider The matched, enabled provider
     *
     * @throws InvalidArgumentException When integrationId is missing, unknown, or disabled
     */
    private function resolveProvider(array $arguments): IntegrationProvider
    {
        $integrationId = $this->requireParam(arguments: $arguments, param: 'integrationId');
        $provider      = $this->registry->get(id: $integrationId);

        if ($provider === null) {
            throw new InvalidArgumentException('Unknown integration: '.$integrationId);
        }

        // Mirror the controller's gate: a disabled integration (backing app
        // missing / external source unconfigured) must not be invoked.
        if ($provider->isEnabled() === false) {
            throw new InvalidArgumentException('Integration not available: '.$integrationId);
        }

        return $provider;
    }//end resolveProvider()

    /**
     * Assert a parameter is present and return it as a string.
     *
     * @param array<string, mixed> $arguments Tool arguments
     * @param string               $param     Required parameter name
     *
     * @return string The parameter value cast to string
     *
     * @throws InvalidArgumentException If parameter is missing
     */
    private function requireParam(array $arguments, string $param): string
    {
        if (isset($arguments[$param]) === false || $arguments[$param] === '') {
            throw new InvalidArgumentException('Missing required parameter: '.$param);
        }

        return (string) $arguments[$param];
    }//end requireParam()
}//end class
