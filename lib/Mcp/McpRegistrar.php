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
use OCA\OpenRegister\Mcp\Listener\ContextChatSubmissionListenerInterface;
use OCA\OpenRegister\Mcp\Db\ConversationMapper;
use OCA\OpenRegister\Mcp\Service\ChatService;
use OCA\OpenRegister\Mcp\Service\ToolRegistry;
use OCA\OpenRegister\Mcp\Service\ToolRegistryInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the Mcp component's interfaces.
 */
final class McpRegistrar
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
        foreach (self::BINDINGS as $contract => $service) {
            $context->registerServiceAlias($contract, $service);
        }
    }//end register()
}//end class
