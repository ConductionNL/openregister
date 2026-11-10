<?php

/**
 * McpRegistrar: wiring for the Mcp component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Mcp
 * @package  OCA\OpenRegister\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp;

use OCA\OpenRegister\Kernel\Contracts\AgentMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ChatServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\ConversationMapperInterface;
use OCA\OpenRegister\Mcp\Db\AgentMapper;
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListener;
use OCA\OpenRegister\ContextChat\Listener\ContextChatSubmissionListenerInterface;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolCatalogueInterface;
use OCA\OpenRegister\Mcp\Service\Mcp\McpToolsService;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Mcp\Service\ToolRegistryInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * The wiring behind McpRegistrar.
 *
 * McpRegistrar keeps the public entry points and delegates each of them here.
 *
 * Binds the Mcp component's interfaces and registers its McpToolsService factory.
 */
final class McpWiring
{
    /**
     * Interface => the class behind it.
     */
    public const BINDINGS = [
        AgentMapperInterface::class        => AgentMapper::class,
        ChatServiceInterface::class        => ChatService::class,
        ContextChatSubmissionListenerInterface::class => ContextChatSubmissionListener::class,
        ConversationMapperInterface::class => ConversationMapper::class,
        ToolRegistryInterface::class => ToolRegistry::class,
    ];

    /**
     * Interface => the factory-registered service behind it.
     *
     * Kept apart from BINDINGS because McpToolsService is not autowired: its
     * factory is registered by McpProviderDiscovery below, and the alias
     * resolves to that same shared instance.
     */
    public const FACTORY_BINDINGS = [
        McpToolCatalogueInterface::class => McpToolsService::class,
    ];

    /**
     * Register the Mcp component's bindings.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec exclude Container wiring; no behaviour of its own.
     */
    public static function register(IRegistrationContext $context): void
    {
        foreach ([...self::BINDINGS, ...self::FACTORY_BINDINGS] as $contract => $service) {
            $context->registerServiceAlias($contract, $service);
        }

        // McpToolsService is registered once, here, so the bootstrap names only
        // this registrar. The factory is lazy, so where it sits among the other
        // registrations does not change what it resolves to.
        (new McpProviderDiscovery())->register(context: $context);
    }//end register()
}//end class
