<?php

/**
 * CapacityPolicy
 *
 * Which decision and ground each capacity suggests (REQ-DKP-001).
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
 * @spec openspec/changes/detection-knows-the-person/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The organisation's capacity policy: per capacity a suggested decision and a ground.
 *
 * @spec openspec/changes/detection-knows-the-person/specs
 */
final class CapacityPolicy {

	/**
	 * A person acting in public office.
	 *
	 * @var string
	 */
	public const PUBLIC_OFFICE = 'public-office';

	/**
	 * A person acting in a professional role.
	 *
	 * @var string
	 */
	public const PROFESSIONAL = 'professional';

	/**
	 * A private person.
	 *
	 * @var string
	 */
	public const PRIVATE = 'private';

	/**
	 * Nothing tells.
	 *
	 * @var string
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * The capacities.
	 *
	 * @var list<string>
	 */
	public const CAPACITIES = [self::PUBLIC_OFFICE, self::PROFESSIONAL, self::PRIVATE, self::UNKNOWN];

	/**
	 * The shipped default: public office is released, everything else withheld under 5.1.2e.
	 *
	 * @var array<string, array{decision: string, ground: string|null}>
	 */
	public const DEFAULT = [
		self::PUBLIC_OFFICE => ['decision' => 'release', 'ground' => null],
		self::PROFESSIONAL  => ['decision' => 'withhold', 'ground' => '5.1.2e'],
		self::PRIVATE       => ['decision' => 'withhold', 'ground' => '5.1.2e'],
		self::UNKNOWN       => ['decision' => 'withhold', 'ground' => '5.1.2e'],
	];

	/**
	 * Constructor.
	 *
	 * @param array<string, array{decision: string, ground: string|null}> $rules Per capacity.
	 */
	private function __construct(private readonly array $rules) {
	}//end __construct()

	/**
	 * The policy from a stored map; a capacity missing, or carrying a decision
	 * other than release or withhold, keeps the shipped default, and a withhold
	 * without a ground keeps the default ground.
	 *
	 * @param mixed $stored The stored policy.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/detection-knows-the-person/specs
	 */
	public static function fromStored(mixed $stored): self {
		$rules = self::DEFAULT;
		if (is_array($stored) === false) {
			return new self(rules: $rules);
		}

		foreach (self::CAPACITIES as $capacity) {
			$rule = $stored[$capacity] ?? null;
			if (is_array($rule) === false || in_array(($rule['decision'] ?? null), ['release', 'withhold'], true) === false) {
				continue;
			}

			$ground = trim((string) ($rule['ground'] ?? ''));
			if ($rule['decision'] === 'withhold' && $ground === '') {
				$ground = (string) self::DEFAULT[$capacity]['ground'];
			}

			$rules[$capacity] = ['decision' => $rule['decision'], 'ground' => null];
			if ($ground !== '') {
				$rules[$capacity]['ground'] = $ground;
			}
		}

		return new self(rules: $rules);
	}//end fromStored()

	/**
	 * The suggested decision and ground for a capacity; an unknown capacity reads as `unknown`.
	 *
	 * @param string $capacity The capacity.
	 *
	 * @return array{decision: string, ground: string|null}
	 *
	 * @spec openspec/changes/detection-knows-the-person/specs
	 */
	public function ruleFor(string $capacity): array {
		return ($this->rules[$capacity] ?? $this->rules[self::UNKNOWN]);
	}//end ruleFor()
}//end class
