<?php

/**
 * PersonRegisterMatcher
 *
 * Loads the persons and place registers once per detection run (REQ-DKP-003).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the in-memory run a PERSON detection is matched against.
 *
 * Each configured register is read once, by name only, and kept as a set of
 * normalised names for the run. A register that cannot be read gives no
 * names: the run then changes no confidence for it, drops nothing, and the
 * failure is logged.
 *
 * @spec openspec/changes/detection-knows-the-person/specs/person-aware-detection/spec.md#requirement-a-person-detection-is-checked-against-the-organisations-own-registers-before-it-is-proposed-req-dkp-003
 */
class PersonRegisterMatcher {

	/**
	 * Constructor.
	 *
	 * @param RegisterNameReader $reader Reads the names of a register.
	 * @param LoggerInterface    $logger Logs a register that cannot be read.
	 */
	public function __construct(
		private readonly RegisterNameReader $reader,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The run for one batch of detections.
	 *
	 * @param array<string, string>|null $personRegister The persons register and purpose (PersonRegisterSource::personRegister()).
	 * @param float                      $boost          The confidence a match adds.
	 * @param array<string, string>|null $placeRegister  The place register (PersonRegisterSource::placeRegister()).
	 *
	 * @return PersonRegisterRun
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
	 */
	public function run(?array $personRegister, float $boost, ?array $placeRegister): PersonRegisterRun {
		if ($personRegister === null) {
			return PersonRegisterRun::notConfigured(placeNames: $this->normalisedNames(source: $placeRegister));
		}

		return new PersonRegisterRun(
			register: PersonRegisterSource::label(source: $personRegister),
			purpose: $personRegister['purpose'],
			boost: $boost,
			personNames: $this->normalisedNames(source: $personRegister),
			placeNames: $this->normalisedNames(source: $placeRegister)
		);
	}//end run()

	/**
	 * The normalised names of a register, as a set; empty when none is configured or it cannot be read.
	 *
	 * @param array<string, string>|null $source The register.
	 *
	 * @return array<string, true>
	 */
	private function normalisedNames(?array $source): array {
		if ($source === null) {
			return [];
		}

		try {
			$names = $this->reader->names(source: $source);
		} catch (Throwable $error) {
			$this->logger->warning(
				message: '[PersonRegisterMatcher] A register could not be read; its matches are skipped for this run',
				context: ['register' => PersonRegisterSource::label(source: $source), 'error' => $error->getMessage()]
			);
			return [];
		}

		$set = [];
		foreach ($names as $name) {
			$normalised = PersonRegisterRun::normalise(name: $name);
			if ($normalised !== '') {
				$set[$normalised] = true;
			}
		}

		return $set;
	}//end normalisedNames()
}//end class
