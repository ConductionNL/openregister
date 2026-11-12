<?php

/**
 * The effective rules, blocking keys and cut-off of one duplicate detection run.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\Quality
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

/**
 * Resolved from a run's request and the schema's `x-openregister-dedup` annotation.
 */
final class DedupConfig {
	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>> $rules        Effective, well-formed match rules.
	 * @param array<int, string>               $blockingKeys Field names (plain or dotted) to block on.
	 * @param float                            $cutOff       Score a pair must reach.
	 */
	public function __construct(
		public readonly array $rules,
		public readonly array $blockingKeys,
		public readonly float $cutOff,
	) {
	}//end __construct()
}//end class
