<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ChatStreamController;

/**
 * Builds ChatStreamController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ChatStreamControllerFactory {
	/**
	 * The constructor arguments of ChatStreamController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ChatService $chatService Constructor argument.
	 * @param ConversationMapper $conversationMapper Constructor argument.
	 * @param AgentMapper $agentMapper Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IDBConnection $db Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Mcp\Service\ChatService $chatService,
		\OCA\OpenRegister\Mcp\Db\ConversationMapper $conversationMapper,
		\OCA\OpenRegister\Mcp\Db\AgentMapper $agentMapper,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IUserSession $userSession,
		\OCP\IDBConnection $db,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\ChatStreamControllerServices(
				chat: new \OCA\OpenRegister\Controller\Support\ChatStreamChat(
					conversationMapper: $conversationMapper,
					agentMapper: $agentMapper,
					chatService: $chatService,
					logger: $logger,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ChatStreamPlatform(
					userSession: $userSession,
					db: $db,
				),
			),
		];
	}

	/**
	 * Build ChatStreamController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ChatStreamController
	 */
	public static function create(mixed ...$args): ChatStreamController {
		return new ChatStreamController(...self::args(...$args));
	}
}
