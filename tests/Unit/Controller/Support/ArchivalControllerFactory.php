<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\ArchivalController;

/**
 * Builds ArchivalController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class ArchivalControllerFactory {
	/**
	 * The constructor arguments of ArchivalController.
	 *
	 * @param string $appName Unused; kept so existing calls still fit.
	 * @param IRequest $request Constructor argument.
	 * @param DestructionService $destructionService Constructor argument.
	 * @param LegalHoldService $legalHoldService Constructor argument.
	 * @param MagicMapper $objectMapper Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param IGroupManager $groupManager Constructor argument.
	 * @param LoggerInterface $logger Constructor argument.
	 * @param DestructionListRepository $lists Constructor argument.
	 * @param DestructionReviewService $reviews Constructor argument.
	 * @param ReviewOutcomeService $outcomes Constructor argument.
	 * @param AuditTrailMapper $auditMapper Constructor argument.
	 * @param ArchivalNominationService $nominations Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param DestructionListCreator $creator Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\Archival\DestructionService $destructionService,
		\OCA\OpenRegister\Service\Archival\LegalHoldService $legalHoldService,
		\OCA\OpenRegister\Db\MagicMapper $objectMapper,
		\OCP\IUserSession $userSession,
		\OCP\IGroupManager $groupManager,
		\Psr\Log\LoggerInterface $logger,
		\OCA\OpenRegister\Service\Archival\DestructionListRepository $lists,
		\OCA\OpenRegister\Service\Archival\DestructionReviewService $reviews,
		\OCA\OpenRegister\Service\Archival\ReviewOutcomeService $outcomes,
		\OCA\OpenRegister\Db\AuditTrailMapper $auditMapper,
		\OCA\OpenRegister\Service\Archival\ArchivalNominationService $nominations,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Service\Archival\DestructionListCreator $creator,
	): array {
		return [
			$request,
			new \OCA\OpenRegister\Controller\Support\ArchivalControllerServices(
				session: new \OCA\OpenRegister\Controller\Support\ControllerSession(
					userSession: $userSession,
					groupManager: $groupManager,
					logger: $logger ?? new \Psr\Log\NullLogger(),
				),
				mappers: new \OCA\OpenRegister\Controller\Support\ArchivalMappers(
					objectMapper: $objectMapper,
					lists: $lists,
					auditMapper: $auditMapper,
					schemaMapper: $schemaMapper,
				),
				decisions: new \OCA\OpenRegister\Controller\Support\ArchivalDecisions(
					destructionService: $destructionService,
					legalHoldService: $legalHoldService,
					reviews: $reviews,
					outcomes: $outcomes,
				),
				nomination: new \OCA\OpenRegister\Controller\Support\ArchivalNomination(
					nominations: $nominations,
					creator: $creator,
				),
			),
		];
	}

	/**
	 * Build ArchivalController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return ArchivalController
	 */
	public static function create(mixed ...$args): ArchivalController {
		return new ArchivalController(...self::args(...$args));
	}
}
