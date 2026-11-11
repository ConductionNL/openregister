<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ChatController;

/**
 * Builds ChatController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ChatControllerFactory {
	/**
	 * The constructor arguments of ChatController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param ChatService $chatService Constructor argument.
	 * @param ConversationMapper $conversationMapper Constructor argument.
	 * @param MessageMapper $messageMapper Constructor argument.
	 * @param FeedbackMapper $feedbackMapper Constructor argument.
	 * @param AgentMapper $agentMapper Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param IDBConnection $db Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param IL10N $l10n Constructor argument.
	 * @param string $userId Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Mcp\Service\ChatService $chatService,
		\OCA\OpenRegister\Mcp\Db\ConversationMapper $conversationMapper,
		\OCA\OpenRegister\Notifications\Db\MessageMapper $messageMapper,
		\OCA\OpenRegister\Db\FeedbackMapper $feedbackMapper,
		\OCA\OpenRegister\Mcp\Db\AgentMapper $agentMapper,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCP\IDBConnection $db,
		\Psr\Log\LoggerInterface $logger,
		\OCP\IL10N $l10n,
		string $userId,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ChatControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\ChatMappers(
					conversationMapper: $conversationMapper,
					messageMapper: $messageMapper,
					feedbackMapper: $feedbackMapper,
					agentMapper: $agentMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\ChatDomain(
					chatService: $chatService,
					organisationService: $organisationService,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ChatPlatform(
					db: $db,
					logger: $logger,
					l10n: $l10n,
				),
			),
			$userId,
		];
	}

	/**
	 * Build ChatController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ChatController
	 */
	public static function create(mixed ...$args): ChatController {
		return new ChatController(...self::args(...$args));
	}
}
