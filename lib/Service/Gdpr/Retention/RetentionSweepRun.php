<?php

/**
 * RetentionSweepRun — one retention sweep over the DSAR cases, in progress.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Retention
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Retention;

/**
 * The sweep clock, whether it writes, and the summary filled per case.
 *
 * Built by RetentionSweepService::runSweep() and never handed out of it.
 *
 * @spec openspec/specs/dsar-retention-sweep/spec.md
 */
final class RetentionSweepRun {
	/**
	 * The sweep summary, filled per case.
	 *
	 * @var array<string, mixed>
	 */
	public array $summary = [];

	/**
	 * Open the sweep.
	 *
	 * @param int  $now    The sweep clock (unix timestamp).
	 * @param bool $dryRun When true, nothing is purged.
	 *
	 * @spec openspec/specs/dsar-retention-sweep/spec.md
	 */
	public function __construct(
		public readonly int $now,
		public readonly bool $dryRun,
	) {
	}//end __construct()
}//end class
