<?php

/**
 * FlowGraphRevision
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
 * A candidate flow graph set against the graph that is live and the semantic
 * version that graph was published under: what {@see FlowSemanticVersion}
 * compares to number a publish or to preview one.
 *
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
 */
final class FlowGraphRevision {

	/**
	 * Name the revision.
	 *
	 * @param array<string, mixed>|null $published      The graph that is live, or null before the first publish.
	 * @param array<string, mixed>      $candidate      The graph being published or considered.
	 * @param string|null               $previousSemver The last semantic version, if any.
	 *
	 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-a-semantic-version-is-derived-at-publish-from-the-graph
	 */
	public function __construct(
		public readonly ?array $published,
		public readonly array $candidate,
		public readonly ?string $previousSemver,
	) {
	}//end __construct()
}//end class
