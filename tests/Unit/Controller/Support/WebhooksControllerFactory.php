<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\WebhooksController;

/**
 * Builds WebhooksController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class WebhooksControllerFactory {
	/**
	 * The constructor arguments of WebhooksController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param WebhookMapper $webhookMapper Constructor argument.
	 * @param WebhookLogMapper $webhookLogMapper Constructor argument.
	 * @param WebhookService $webhookService Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param ?IUserSession $userSession Constructor argument.
	 * @param ?IGroupManager $groupManager Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\WebhookMapper $webhookMapper,
		\OCA\OpenRegister\Db\WebhookLogMapper $webhookLogMapper,
		\OCA\OpenRegister\Service\WebhookService $webhookService,
		\Psr\Log\LoggerInterface $logger,
		?\OCP\IUserSession $userSession = null,
		?\OCP\IGroupManager $groupManager = null,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\WebhooksControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\WebhooksMappers(
					webhookMapper: $webhookMapper,
					webhookLogMapper: $webhookLogMapper,
					webhookService: $webhookService,
					logger: $logger,
				),
				platform: new \OCA\OpenRegister\Controller\Support\TasksPlatform(
					userSession: $userSession,
					groupManager: $groupManager,
				),
			),
		];
	}

	/**
	 * Build WebhooksController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return WebhooksController
	 */
	public static function create(mixed ...$args): WebhooksController {
		return new WebhooksController(...self::args(...$args));
	}
}
