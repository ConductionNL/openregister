<?php

/**
 * Which runs a run history lists: the narrowing filters of a run-history read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-run-summary/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;

/**
 * The filters of a run history read, each one only ever narrowing.
 *
 * A null or empty flow id or status, a null moment and an empty flow set all
 * mean "no restriction" on that axis. Visibility is NOT a filter: who may see
 * a run travels separately as a {@see FlowRunViewer}.
 *
 * @spec openspec/specs/flow-run-summary/spec.md
 */
final readonly class FlowRunFilter {
	/**
	 * Constructor.
	 *
	 * @param string|null        $flowId  Restrict to one flow.
	 * @param string|null        $status  Restrict to one status.
	 * @param DateTime|null      $since   Only runs created at or after this moment.
	 * @param array<int, string> $flowIds Restrict to any of these flows; empty means no restriction.
	 *
	 * @return void
	 */
	public function __construct(
		public ?string $flowId = null,
		public ?string $status = null,
		public ?DateTime $since = null,
		public array $flowIds = [],
	) {
	}//end __construct()
}//end class
