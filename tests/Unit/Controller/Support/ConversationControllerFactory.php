<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ConversationController;

/**
 * Builds ConversationController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ConversationControllerFactory {
	/**
	 * The constructor arguments of ConversationController.
	 *
	 * @param IRequest $request Constructor argument.
	 * @param ConversationMapper $conversationMapper Constructor argument.
	 * @param MessageMapper $messageMapper Constructor argument.
	 * @param FeedbackMapper $feedbackMapper Constructor argument.
	 * @param AgentMapper $agentMapper Constructor argument.
	 * @param OrganisationService $organisationService Constructor argument.
	 * @param ChatService $chatService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param string $userId Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		\OCP\IRequest $request,
		\OCA\OpenRegister\Mcp\Db\ConversationMapper $conversationMapper,
		\OCA\OpenRegister\Notifications\Db\MessageMapper $messageMapper,
		\OCA\OpenRegister\Db\FeedbackMapper $feedbackMapper,
		\OCA\OpenRegister\Mcp\Db\AgentMapper $agentMapper,
		\OCA\OpenRegister\Service\OrganisationService $organisationService,
		\OCA\OpenRegister\Mcp\Service\ChatService $chatService,
		\Psr\Log\LoggerInterface $logger,
		string $userId,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ConversationControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\ChatMappers(
					conversationMapper: $conversationMapper,
					messageMapper: $messageMapper,
					feedbackMapper: $feedbackMapper,
					agentMapper: $agentMapper,
				),
				domain: new \OCA\OpenRegister\Controller\Support\ConversationDomain(
					organisationService: $organisationService,
					chatService: $chatService,
				),
				platform: new \OCA\OpenRegister\Controller\Support\ConversationPlatform(
					logger: $logger,
				),
			),
			$userId,
		];
	}

	/**
	 * Build ConversationController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ConversationController
	 */
	public static function create(mixed ...$args): ConversationController {
		return new ConversationController(...self::args(...$args));
	}
}
