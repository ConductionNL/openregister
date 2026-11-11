<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\RulesController;

/**
 * Builds RulesController from its services as separate arguments, wrapped into its constructor bundles.
 */
final class RulesControllerFactory {
	/**
	 * The constructor arguments of RulesController.
	 *
	 * @param string $appName Constructor argument.
	 * @param IRequest $request Constructor argument.
	 * @param SchemaMapper $schemaMapper Constructor argument.
	 * @param RuleInventoryService $inventory Constructor argument.
	 * @param RuleRunMapper $runs Constructor argument.
	 * @param RuleTrialService $trials Constructor argument.
	 * @param RuleEnablementService $enablement Constructor argument.
	 * @param RuleVocabulary $vocabulary Constructor argument.
	 * @param RuleReplayService $replays Constructor argument.
	 * @param IUserSession $userSession Constructor argument.
	 *
	 * @return array<int, mixed>
	 */
	public static function args(
		string $appName,
		\OCP\IRequest $request,
		\OCA\OpenRegister\Db\SchemaMapper $schemaMapper,
		\OCA\OpenRegister\Service\Rules\RuleInventoryService $inventory,
		\OCA\OpenRegister\Db\RuleRunMapper $runs,
		\OCA\OpenRegister\Service\Rules\RuleTrialService $trials,
		\OCA\OpenRegister\Service\Rules\RuleEnablementService $enablement,
		\OCA\OpenRegister\Service\Rules\RuleVocabulary $vocabulary,
		\OCA\OpenRegister\Service\Rules\RuleReplayService $replays,
		\OCP\IUserSession $userSession,
	): array {
		return [
			$appName,
			$request,
			new \OCA\OpenRegister\Controller\Support\RulesControllerServices(
				mappers: new \OCA\OpenRegister\Controller\Support\RulesMappers(
					schemaMapper: $schemaMapper,
					runs: $runs,
				),
				domain: new \OCA\OpenRegister\Controller\Support\RulesDomain(
					inventory: $inventory,
					trials: $trials,
					enablement: $enablement,
					vocabulary: $vocabulary,
				),
				replay: new \OCA\OpenRegister\Controller\Support\RulesReplay(
					replays: $replays,
				),
				platform: new \OCA\OpenRegister\Controller\Support\DsarCasePlatform(
					userSession: $userSession,
				),
			),
		];
	}

	/**
	 * Build RulesController; takes the same arguments as args().
	 *
	 * @param mixed ...$args Arguments for args(), positional or named.
	 *
	 * @return RulesController
	 */
	public static function create(mixed ...$args): RulesController {
		return new RulesController(...self::args(...$args));
	}
}
