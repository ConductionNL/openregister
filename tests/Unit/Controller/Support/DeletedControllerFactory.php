<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\DeletedController;

/**
 * Builds DeletedController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class DeletedControllerFactory {
	/**
	 * The constructor arguments of DeletedController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param MagicMapper $objectEntityMapper Constructor argument.
	 * @param RegisterMapper $registerMapper Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param AuditTrailMapper $auditTrailMapper Constructor argument.
	 * @param DeletionServiceBundle $deletion Constructor argument.
	 * @param DeletedObjectAuthorizer $authorizer Constructor argument.
	 * @param RenderObject $renderObject Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\MagicMapper $objectEntityMapper,
		\OCA\OpenRegister\Db\RegisterMapper $registerMapper,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Db\AuditTrailMapper $auditTrailMapper,
		\OCA\OpenRegister\Service\Deletion\DeletionServiceBundle $deletion,
		\OCA\OpenRegister\Service\Deletion\DeletedObjectAuthorizer $authorizer,
		\OCA\OpenRegister\Service\Object\RenderObject $renderObject,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\DeletedControllerServices(
				store: new \OCA\OpenRegister\Controller\Support\DeletedStore(
					objectEntityMapper: $objectEntityMapper,
					registerMapper: $registerMapper,
					auditTrailMapper: $auditTrailMapper,
					deletion: $deletion,
				),
				access: new \OCA\OpenRegister\Controller\Support\DeletedAccess(
					authorizer: $authorizer,
					renderObject: $renderObject,
					userSession: $userSession,
				),
			),
		];
	}

	/**
	 * Build DeletedController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return DeletedController
	 */
	public static function create(mixed ...$args): DeletedController {
		return new DeletedController(...self::args(...$args));
	}
}
