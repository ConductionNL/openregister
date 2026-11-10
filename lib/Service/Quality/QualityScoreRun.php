<?php

/**
 * OpenRegister QualityScoreRun
 *
 * One object being scored against its rules: the object's data and the moment freshness is measured from.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use DateTimeImmutable;

/**
 * The object and the clock reading one QualityScorer::score() call works with; it never leaves the scorer.
 *
 * @spec openspec/changes/mdm-foundation/tasks.md#task-1
 */
final class QualityScoreRun {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $object The object's data.
	 * @param DateTimeImmutable    $now    The moment freshness is measured from.
	 */
	public function __construct(
		public readonly array $object,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
