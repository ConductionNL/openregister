<?php

/**
 * What a recompute did: how many objects it touched, left unchanged, and which failed and why.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation;

use JsonSerializable;
use OCA\OpenRegister\Command\RematerialiseRun;

/**
 * The counts of one recompute.
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */
final readonly class RecomputeResult implements JsonSerializable {
	/**
	 * Record the counts.
	 *
	 * @param int                                     $touched   Objects whose values changed (or would, in a dry run).
	 * @param int                                     $unchanged Objects whose values already held.
	 * @param list<array{id: string, reason: string}> $failed    The failures, with the object's id and the reason.
	 */
	public function __construct(
		public int $touched,
		public int $unchanged,
		public array $failed,
	) {
	}//end __construct()

	/**
	 * The counts of a finished run.
	 *
	 * @param RematerialiseRun $run The run.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public static function of(RematerialiseRun $run): self {
		return new self(touched: $run->touched, unchanged: $run->unchanged, failed: $run->failures);
	}//end of()

	/**
	 * The counts as the endpoint answers them.
	 *
	 * @return array{touched: int, unchanged: int, failed: list<array{id: string, reason: string}>}
	 *
	 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
	 */
	public function jsonSerialize(): array {
		return ['touched' => $this->touched, 'unchanged' => $this->unchanged, 'failed' => $this->failed];
	}//end jsonSerialize()
}//end class
