<?php

/**
 * What a run's marking is remapped against when it moves to another version.
 *
 * The target version's nodes, the run's own version's nodes and the author's
 * old-to-new node mapping are read together for every token in the marking,
 * so they are one value instead of three arguments.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Target nodes, source nodes and the node mapping of one migration check.
 *
 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
 */
final class FlowRunMarkingRemap {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                     $nodes       The target version's nodes, by id.
	 * @param array<string, array<string, mixed>>|null $sourceNodes The run's own version's nodes, by id, or null when unreadable.
	 * @param array<string, string>                    $mapping     Old node id to new node id.
	 *
	 * @spec openspec/changes/migrate-run-between-versions/specs/flow-definition-versioning/spec.md#requirement-a-run-can-be-migrated-to-another-version-explicitly-and-validated
	 */
	public function __construct(
		public readonly array $nodes,
		public readonly ?array $sourceNodes,
		public readonly array $mapping,
	) {

	}//end __construct()
}//end class
