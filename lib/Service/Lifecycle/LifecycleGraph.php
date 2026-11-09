<?php

/**
 * OpenRegister LifecycleGraph
 *
 * The graph block of a graph-mode lifecycle and the field it drives.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * The `graph` block of a graph-mode lifecycle and the field it drives.
 *
 * The read path (available actions) and the write path (applying a
 * `move-to-<uuid>`) derive candidates from the same pair, so it travels as
 * one value (ADR-011 rule 2).
 *
 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
 */
final class LifecycleGraph {

	/**
	 * Name the graph.
	 *
	 * @param array<string, mixed> $block The `graph` block off the annotation.
	 * @param string               $field The lifecycle field name on the object.
	 *
	 * @spec openspec/changes/fk-graph-lifecycle-transitions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly array $block,
		public readonly string $field,
	) {
	}//end __construct()
}//end class
