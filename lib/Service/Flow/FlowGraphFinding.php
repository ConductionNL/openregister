<?php

/**
 * FlowGraphFinding
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * What a graph comparison found: its verdict, and a summary of what the
 * candidate removed for a refusal to quote.
 *
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-an-author-may-raise-the-verdict-and-never-lower-it
 */
final class FlowGraphFinding {

	/**
	 * Name the finding.
	 *
	 * @param string $verdict FlowGraphDiff::MAJOR or ::MINOR.
	 * @param string $removed A summary of what was removed, '' for none.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-an-author-may-raise-the-verdict-and-never-lower-it
	 */
	public function __construct(
		public readonly string $verdict,
		public readonly string $removed = '',
	) {
	}//end __construct()
}//end class
