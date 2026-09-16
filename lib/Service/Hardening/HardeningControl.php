<?php

/**
 * One security control, as the hardening report names it.
 *
 * A control is a number or a switch that somebody can turn the wrong way. The
 * row carries the value that is in force, where it comes from, the floor this
 * instance declared for it, and whether the two agree. An administrator reads
 * the rows; {@see HardeningFloorGuard} refuses the writes that would break one.
 *
 * 🔴 `meetsFloor` IS COMPUTED, NEVER SET. A stored verdict is a verdict that
 * goes stale the moment the value under it moves, and a hardening report whose
 * verdict lags its value is worse than no report: it is read once and trusted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hardening;

use JsonSerializable;

/**
 * A single row of the hardening report.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hardening
 */
final class HardeningControl implements JsonSerializable {

	/**
	 * Constructor.
	 *
	 * @param string $id The control identifier, e.g. `auth.rateLimit.windowSeconds`.
	 * @param string $title What the control does, in one line.
	 * @param string $category The group it is reported under.
	 * @param string $source Who enforces it: `platform`, `administered` or `code`.
	 * @param int|null $value The value in force, or null when it cannot be read.
	 * @param int $floor The declared floor.
	 * @param string $comparator `atLeast` or `atMost`, naming which way is stronger.
	 * @param string $unit What the value counts: `seconds`, `attempts`, `bytes`, `entries` or `switch`.
	 * @param string $note Why the value reads as it does, for a control that needs it.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $category,
		public readonly string $source,
		public readonly ?int $value,
		public readonly int $floor,
		public readonly string $comparator,
		public readonly string $unit,
		public readonly string $note = '',
	) {

	}//end __construct()

	/**
	 * Whether the value in force satisfies the declared floor.
	 *
	 * A value that cannot be read fails, per ADR-005. An unreadable control is
	 * not a control that happens to be fine.
	 *
	 * @return bool True when the control is at or above its floor.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function meetsFloor(): bool {
		if ($this->value === null) {
			return false;
		}

		return self::satisfies(comparator: $this->comparator, value: $this->value, floor: $this->floor);

	}//end meetsFloor()

	/**
	 * What the administrator sees in the state column.
	 *
	 * @return string `on`, `off` or `unknown`.
	 */
	public function state(): string {
		if ($this->value === null) {
			return 'unknown';
		}

		if ($this->unit === 'switch') {
			if ($this->value === 0) {
				return 'off';
			}

			return 'on';
		}

		return 'on';

	}//end state()

	/**
	 * Compare a value with a floor the way the comparator says to.
	 *
	 * The one place the direction of "stronger" is decided. Every caller that
	 * decides it again is a caller that can decide it the other way round.
	 *
	 * @param string $comparator `atLeast` or `atMost`.
	 * @param int $value The value to judge.
	 * @param int $floor The floor to judge it against.
	 *
	 * @return bool True when the value is at or above the floor's strength.
	 */
	public static function satisfies(string $comparator, int $value, int $floor): bool {
		if ($comparator === 'atMost') {
			return $value <= $floor;
		}

		return $value >= $floor;

	}//end satisfies()

	/**
	 * The row as the API publishes it.
	 *
	 * @return array<string, mixed> The serialised control.
	 */
	public function jsonSerialize(): array {
		$row = [
			'id' => $this->id,
			'title' => $this->title,
			'category' => $this->category,
			'source' => $this->source,
			'unit' => $this->unit,
			'value' => $this->value,
			'floor' => $this->floor,
			'comparator' => $this->comparator,
			'state' => $this->state(),
			'meetsFloor' => $this->meetsFloor(),
		];

		if ($this->note !== '') {
			$row['note'] = $this->note;
		}

		return $row;

	}//end jsonSerialize()
}//end class
