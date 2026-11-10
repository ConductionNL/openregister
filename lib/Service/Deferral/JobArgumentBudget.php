<?php

/**
 * OpenRegister JobArgumentBudget
 *
 * The room one deferred entry may take in the job argument of a job class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deferral
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

namespace OCA\OpenRegister\Service\Deferral;

/**
 * The job class a chunk is cut for and the room its entries may take in one job argument.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
 */
final class JobArgumentBudget {
	/**
	 * Constructor.
	 *
	 * @param string $jobClass FQCN of the job the chunks are for.
	 * @param int    $room     The encoded length the entries of one job may take.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-deferred-listener-chunk-fits-the-job-argument-limit
	 */
	public function __construct(
		public readonly string $jobClass,
		public readonly int $room,
	) {
	}//end __construct()
}//end class
