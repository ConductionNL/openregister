<?php

/**
 * ReferenceRelinkPlan: the reference moves a merge will make, before it makes them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

/**
 * Every reference to a losing record, with where it moves and a per-schema count.
 *
 * `exceedsCap` says the plan was stopped at the cap: a record referenced from
 * more places than one merge should rewrite is refused with the count, not
 * truncated and applied in part (design, Risks).
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */
final readonly class ReferenceRelinkPlan {

	/**
	 * Constructor.
	 *
	 * @param array<int, ReferenceMove> $moves      The moves, in discovery order.
	 * @param int                       $cap        The most moves one merge may make.
	 * @param bool                      $exceedsCap Whether discovery hit the cap.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A plan either hit the cap or did not; it carries no behaviour to split.
	 */
	public function __construct(
		public array $moves,
		public int $cap,
		public bool $exceedsCap=false,
	) {
	}//end __construct()

	/**
	 * The references that will move, by schema and count, for the preview.
	 *
	 * @return array<int, array{schema: string, count: int}>
	 *
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
	 */
	public function references(): array {
		$counts = [];
		foreach ($this->moves as $move) {
			$schema = ($move->schema ?? '');
			$counts[$schema] = (($counts[$schema] ?? 0) + 1);
		}

		$references = [];
		foreach ($counts as $schema => $count) {
			$references[] = ['schema' => (string)$schema, 'count' => $count];
		}

		return $references;
	}//end references()
}//end class
