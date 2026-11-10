<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\AuditTrailController;

/**
 * Builds AuditTrailController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class AuditTrailControllerFactory {
	/**
	 * The constructor arguments of AuditTrailController.
	 *
	 * @param string $appName Unused; kept so existing calls still fit.
	 * @param IRequest $request Constructor argument.
	 * @param LogService $logService Constructor argument.
	 * @param AuditTrailMapper $auditTrailMapper Constructor argument.
	 * @param AuditHashService $auditHashService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param ReadableAuditTrailLister $readableLister Constructor argument.
	 * @param AuditTrailPageService $auditPage Constructor argument.
	 * @param ObjectHistoryForReaders $historyForReaders Constructor argument.
	 * @param ?ExplicitRevealTrailMasker $revealMasker Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\LogService $logService,
		\OCA\OpenRegister\Db\AuditTrailMapper $auditTrailMapper,
		\OCA\OpenRegister\Service\AuditHashService $auditHashService,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\OCA\OpenRegister\Service\Audit\ReadableAuditTrailLister $readableLister,
		\OCA\OpenRegister\Service\Audit\AuditTrailPageService $auditPage,
		\OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders $historyForReaders,
		?\OCA\OpenRegister\Service\Rbac\ExplicitRevealTrailMasker $revealMasker = null,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\AuditTrailControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\AuditTrailMappers(
					auditTrailMapper: $auditTrailMapper,
				),
				log: new \OCA\OpenRegister\Controller\Support\AuditTrailLog(
					logService: $logService,
					auditHashService: $auditHashService,
					readableLister: $readableLister,
					auditPage: $auditPage,
				),
				readers: new \OCA\OpenRegister\Controller\Support\AuditTrailReaders(
					historyForReaders: $historyForReaders,
					revealMasker: $revealMasker,
				),
				platform: new \OCA\OpenRegister\Controller\Support\AuditTrailPlatform(
					userSession: $userSession,
					groupManager: $groupManager,
				),
			),
		];
	}

	/**
	 * Build AuditTrailController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return AuditTrailController
	 */
	public static function create(mixed ...$args): AuditTrailController {
		return new AuditTrailController(...self::args(...$args));
	}
}
