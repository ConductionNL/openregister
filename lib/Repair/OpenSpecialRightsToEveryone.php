<?php

/**
 * OpenSpecialRightsToEveryone: the upgrade that starts reading the special
 * rights, without refusing anybody who could act before it.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: write `*` under each special right a caller uses, in every organisation, once.
 *
 * WHY. Until decision 239 the special rights were stored and read by nothing,
 * so every signed-in user could run an agent and have a language model called.
 * The lists an organisation carries say otherwise: a new organisation got `[]`,
 * and most got `["admin"]` from the admin-group backfill. Reading those lists
 * as they stand would refuse nearly everybody on the day of the upgrade. This
 * step writes what the instance actually did, `*` beside whatever groups are
 * listed, so nothing is refused until an administrator or the owner narrows
 * the list by taking `*` out.
 *
 * ONCE. The step records that it ran in app config and does nothing after that.
 * Run again on every upgrade, it would put back a `*` an administrator took out.
 * When it fails part-way the flag is not set, so the next upgrade finishes it;
 * an organisation that already holds `*` is not written twice.
 *
 * Never throws: an organisation that cannot be written must not abort an app
 * upgrade.
 *
 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
 */
class OpenSpecialRightsToEveryone implements IRepairStep {

	/**
	 * App config key that records the step ran.
	 *
	 * @var string
	 */
	public const DONE_KEY = 'special_rights_opened_to_everyone';

	/**
	 * Constructor.
	 *
	 * @param OrganisationSpecialRights $specialRights The one writer of special rights.
	 * @param IAppConfig $appConfig Records that the step ran.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly OrganisationSpecialRights $specialRights,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
	 */
	public function getName(): string {
		return 'Keep every user able to use agents and language models now that special rights are enforced';
	}//end getName()

	/**
	 * Write `*` under each open-by-default special right in every organisation, once.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/enforce-organisation-special-rights/specs/organisation-special-rights/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool('openregister', self::DONE_KEY, false) === true) {
			return;
		}

		$changed = 0;
		$organisations = 0;
		try {
			foreach (OrganisationSpecialRights::OPEN_BY_DEFAULT as $right) {
				$answer = $this->specialRights->openToEveryoneInEveryOrganisation(right: $right);
				$changed += $answer['changed'];
				$organisations = $answer['organisations'];
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[OpenSpecialRightsToEveryone] Could not open every special right; the next upgrade finishes it',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			$output->warning('Special rights were not opened in every organisation: ' . $e->getMessage());
			return;
		}

		$this->appConfig->setValueBool('openregister', self::DONE_KEY, true);

		$output->info(
			sprintf(
				'Wrote "*" (every signed-in user) under %d special right list(s) across %d organisation(s). '
				. 'Take "*" out of a list to narrow that right to its groups.',
				$changed,
				$organisations
			)
		);
	}//end run()
}//end class
