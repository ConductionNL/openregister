<?php

/**
 * Scores an object payload against declarative quality rules, as the save listener asks for it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use DateTimeImmutable;

/**
 * Scores an object payload against declarative quality rules, as the save listener asks for it.
 *
 * Declares only what callers outside lib/Service use; the container binds it
 * to {@see QualityScorer} in ContractBindings.
 */
interface QualityScorerInterface {

	/**
	 * Score an object payload against a list of quality rules.
	 *
	 * @param array<string, mixed> $object The object's stored data.
	 * @param array<int, mixed> $rules Declarative rule list.
	 * @param DateTimeImmutable $now Reference instant for freshness rules.
	 *
	 * @return float Quality score in [0, 1]. Returns 1.0 when no usable rule
	 *               applies (an object with no declared quality constraints is
	 *               trivially compliant).
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-1
	 */
	public function score(array $object, array $rules, DateTimeImmutable $now): float;

	/**
	 * Map a numeric score to a status label using optional thresholds.
	 *
	 * @param float $score Score in [0, 1].
	 * @param array<string, mixed> $thresholds Map with optional `good` / `fair` cut-offs.
	 *
	 * @return string One of `good`, `fair`, `poor`.
	 *
	 * @spec openspec/changes/mdm-foundation/tasks.md#task-1
	 */
	public function status(float $score, array $thresholds): string;
}//end interface
