<?php

/**
 * OpenRegister UniqueHintWarnings
 *
 * The per-request slot a soft-uniqueness alert travels in, from the save path
 * that found it to the controller that answers the caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

/**
 * A drained-on-read collector for soft-uniqueness warnings.
 *
 * The alert is not an error and not part of the object, so it cannot ride on
 * the exception path and it must not be written into the payload. It is
 * collected here during the save and drained by the controller onto the
 * response.
 *
 * DRAINED ON READ, and that is the whole safety property. A bulk save walks
 * hundreds of rows through the same request; a collector that only ever
 * appended would hand row four hundred the warnings of rows one to three
 * hundred and ninety-nine, and a warning attached to the wrong record is worse
 * than none. Reading clears.
 *
 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
class UniqueHintWarnings {

	/**
	 * Warnings collected since the last drain.
	 *
	 * @var array<int, array{property: string, matches: array<int, string>, visible: bool}>
	 */
	private array $warnings = [];

	/**
	 * Record that a nominated property's value already exists elsewhere.
	 *
	 * @param string $property The nominated property.
	 * @param array<int, string> $matches Uuids of the objects already holding the value, as far as the caller may see them.
	 * @param bool $visible Whether the caller may see WHICH objects; false means a match exists but is not theirs to name.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function record(string $property, array $matches, bool $visible = true): void {
		$this->warnings[] = [
			'property' => $property,
			'matches' => array_values($matches),
			'visible' => $visible,
		];
	}//end record()

	/**
	 * Take everything collected so far and forget it.
	 *
	 * @return array<int, array{property: string, matches: array<int, string>, visible: bool}> The warnings.
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function drain(): array {
		$warnings = $this->warnings;
		$this->warnings = [];

		return $warnings;
	}//end drain()
}//end class
