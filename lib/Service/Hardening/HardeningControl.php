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
	 * @param HardeningControlLabel $label   How the report names the control.
	 * @param HardeningReading      $reading The value in force against the declared floor.
	 * @param string                $note    Why the value reads as it does, for a control that needs it.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly HardeningControlLabel $label,
		public readonly HardeningReading $reading,
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
		if ($this->reading->value === null) {
			return false;
		}

		return (new FloorRule(comparator: $this->reading->comparator, floor: $this->reading->floor))->admits(value: $this->reading->value);

	}//end meetsFloor()

	/**
	 * What the administrator sees in the state column.
	 *
	 * @return string `on`, `off` or `unknown`.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function state(): string {
		if ($this->reading->value === null) {
			return 'unknown';
		}

		if ($this->reading->unit === 'switch') {
			if ($this->reading->value === 0) {
				return 'off';
			}

			return 'on';
		}

		return 'on';

	}//end state()

	/**
	 * The row as the API publishes it.
	 *
	 * @return array<string, mixed> The serialised control.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-the-instance-reports-every-control-against-a-declared-floor-and-refuses-a-change-that-weakens-one-req-ihc-006
	 */
	public function jsonSerialize(): array {
		$row = array_merge(
			$this->labelFields(),
			$this->readingFields(),
			['state' => $this->state(), 'meetsFloor' => $this->meetsFloor()]
		);

		if ($this->note !== '') {
			$row['note'] = $this->note;
		}

		return $row;

	}//end jsonSerialize()

	/**
	 * What the control is: its id, title, category and source.
	 *
	 * @return array<string, string> The label fields.
	 */
	private function labelFields(): array {
		return [
			'id' => $this->label->id,
			'title' => $this->label->title,
			'category' => $this->label->category,
			'source' => $this->label->source,
		];
	}//end labelFields()

	/**
	 * What the control reads: its unit, value, floor and comparator.
	 *
	 * @return array<string, mixed> The reading fields.
	 */
	private function readingFields(): array {
		return [
			'unit' => $this->reading->unit,
			'value' => $this->reading->value,
			'floor' => $this->reading->floor,
			'comparator' => $this->reading->comparator,
		];
	}//end readingFields()
}//end class
