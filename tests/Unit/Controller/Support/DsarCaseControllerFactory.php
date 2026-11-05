<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\DsarCaseController;

/**
 * Builds DsarCaseController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class DsarCaseControllerFactory {
	/**
	 * The constructor arguments of DsarCaseController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param ObjectService $objectService Constructor argument.
	 * @param CaseObjectAccessor $accessor Constructor argument.
	 * @param CaseAccessControl $accessControl Constructor argument.
	 * @param TransitionEngine $transitionEngine Constructor argument.
	 * @param EvidenceHarvestService $harvestService Constructor argument.
	 * @param RedactionWriteService $redactionService Constructor argument.
	 * @param ExportBundleService $bundleService Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 * @param DsarPolicyPackResolver $packResolver Constructor argument.
	 * @param IdentityVerifyRegistry $identityRegistry Constructor argument.
	 * @param RegulatorEscalateRegistry $regulatorRegistry Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Service\ObjectService $objectService,
		\OCA\OpenRegister\Service\Gdpr\Case\CaseObjectAccessor $accessor,
		\OCA\OpenRegister\Service\Gdpr\Case\CaseAccessControl $accessControl,
		\OCA\OpenRegister\Service\Lifecycle\TransitionEngine $transitionEngine,
		\OCA\OpenRegister\Service\Gdpr\Evidence\EvidenceHarvestService $harvestService,
		\OCA\OpenRegister\Service\Gdpr\Redaction\RedactionWriteService $redactionService,
		\OCA\OpenRegister\Service\Gdpr\Export\ExportBundleService $bundleService,
		\OCP\IUserSession $userSession,
		\OCA\OpenRegister\Service\Gdpr\Policy\DsarPolicyPackResolver $packResolver,
		\OCA\OpenRegister\Service\Gdpr\Identity\IdentityVerifyRegistry $identityRegistry,
		\OCA\OpenRegister\Service\Gdpr\Regulator\RegulatorEscalateRegistry $regulatorRegistry,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\DsarCaseControllerServices(
				cases: new \OCA\OpenRegister\Controller\Support\DsarCaseCases(
					objectService: $objectService,
					accessor: $accessor,
					accessControl: $accessControl,
					transitionEngine: $transitionEngine,
				),
				disclosure: new \OCA\OpenRegister\Controller\Support\DsarCaseDisclosure(
					harvestService: $harvestService,
					redactionService: $redactionService,
					bundleService: $bundleService,
					packResolver: $packResolver,
				),
				registries: new \OCA\OpenRegister\Controller\Support\DsarCaseRegistries(
					identityRegistry: $identityRegistry,
					regulatorRegistry: $regulatorRegistry,
				),
				platform: new \OCA\OpenRegister\Controller\Support\DsarCasePlatform(
					userSession: $userSession,
				),
			),
		];
	}

	/**
	 * Build DsarCaseController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return DsarCaseController
	 */
	public static function create(mixed ...$args): DsarCaseController {
		return new DsarCaseController(...self::args(...$args));
	}
}
