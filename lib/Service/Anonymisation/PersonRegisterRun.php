<?php

/**
 * PersonRegisterRun
 *
 * One detection run's persons and place registers, held in memory (REQ-DKP-003).
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

use Normalizer;

/**
 * Matches PERSON detections against the run's registers by normalised name.
 *
 * A persons register match raises the confidence by the boost, capped at
 * 0.99, and sets `personMatch` to the register's label. A candidate that
 * matches the place register and not the persons register is marked
 * `uncertain`. No candidate is dropped, and nothing of the matched record is
 * kept. The run counts its lookups, for the run record.
 *
 * @spec openspec/changes/detection-knows-the-person/specs/person-aware-detection/spec.md#requirement-a-person-detection-is-checked-against-the-organisations-own-registers-before-it-is-proposed-req-dkp-003
 */
final class PersonRegisterRun {

	/**
	 * What the run records when no persons register is configured.
	 *
	 * @var string
	 */
	public const NOT_CONFIGURED = 'not-configured';

	/**
	 * The PERSON detections looked up so far.
	 *
	 * @var integer
	 */
	private int $lookups = 0;

	/**
	 * Constructor.
	 *
	 * @param string|null         $register    The persons register's label, null when none is configured.
	 * @param string|null         $purpose     The purpose the lookup is made for.
	 * @param float               $boost       The confidence a match adds.
	 * @param array<string, true> $personNames The persons register's normalised names.
	 * @param array<string, true> $placeNames  The place register's normalised names.
	 */
	public function __construct(
		public readonly ?string $register,
		public readonly ?string $purpose,
		private readonly float $boost,
		private readonly array $personNames,
		private readonly array $placeNames,
	) {
	}//end __construct()

	/**
	 * A run without a persons register: confidences are unchanged.
	 *
	 * @param array<string, true> $placeNames The place register's normalised names, if one is configured.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
	 */
	public static function notConfigured(array $placeNames=[]): self {
		return new self(register: null, purpose: null, boost: 0.0, personNames: [], placeNames: $placeNames);
	}//end notConfigured()

	/**
	 * A name with case, accents, punctuation and repeated spaces taken out.
	 *
	 * @param string $name The name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
	 */
	public static function normalise(string $name): string {
		$decomposed = $name;
		if (class_exists(Normalizer::class) === true) {
			$decomposed = (string) Normalizer::normalize($name, Normalizer::FORM_D);
		}

		$plain = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
		$plain = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($plain));

		return trim($plain);
	}//end normalise()

	/**
	 * A detection with the register match applied; a detection that is not a PERSON is returned as it is.
	 *
	 * @param array<string, mixed> $detected The detected entity.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
	 */
	public function apply(array $detected): array {
		if (($detected['type'] ?? null) !== 'PERSON') {
			return $detected;
		}

		$name = self::normalise(name: (string) ($detected['value'] ?? ''));
		if ($name === '') {
			return $detected;
		}

		if ($this->register !== null) {
			$this->lookups++;
			if (isset($this->personNames[$name]) === true) {
				$confidence = (float) ($detected['confidence'] ?? 0.0) + $this->boost;
				$detected['confidence'] = round(min(PersonRegisterSource::MAX_CONFIDENCE, $confidence), 4);
				$detected['personMatch'] = ['source' => $this->register];
				return $detected;
			}
		}

		if ($this->inPlaceName(name: $name) === true) {
			$detected['uncertain'] = true;
		}

		return $detected;
	}//end apply()

	/**
	 * What the run records: the persons register or not-configured, its purpose and the number of lookups.
	 *
	 * @return array{personRegister: string, purpose: string|null, lookups: int}
	 *
	 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.2
	 */
	public function summary(): array {
		return [
			'personRegister' => $this->register ?? self::NOT_CONFIGURED,
			'purpose' => $this->purpose,
			'lookups' => $this->lookups,
		];
	}//end summary()

	/**
	 * Whether a normalised name is a place name or starts a word of one ("willem de zwijger" in "willem de zwijgerlaan").
	 *
	 * @param string $name The normalised name.
	 *
	 * @return boolean
	 */
	private function inPlaceName(string $name): bool {
		foreach (array_keys($this->placeNames) as $place) {
			if (str_contains(' '.$place, ' '.$name) === true) {
				return true;
			}
		}

		return false;
	}//end inPlaceName()
}//end class
