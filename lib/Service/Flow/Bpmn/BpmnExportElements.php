<?php

/**
 * The process elements of an exported flow: its nodes and attached edges.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Bpmn;

/**
 * The nodes and edges the exporter writes as process elements.
 *
 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
 */
final class BpmnExportElements {
	/**
	 * Bundle the elements.
	 *
	 * @param array<int, array<string, mixed>> $nodes The nodes.
	 * @param array<int, array<string, mixed>> $edges The attached edges, each with an `id`.
	 *
	 * @spec openspec/changes/flow-bpmn-interchange/specs/flow-bpmn-interchange/spec.md
	 */
	public function __construct(
		public readonly array $nodes,
		public readonly array $edges,
	) {
	}//end __construct()
}//end class
