<?php

/**
 * FlowBpmnInterchange: BPMN export and import behind one interface.
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

use OCA\OpenRegister\Db\Flow;

/**
 * Exports and imports through a fresh exporter or importer per call.
 *
 * Each call builds its exporter or importer, with its own vocabulary and
 * schema validator, exactly as the flow endpoints did before: the importer
 * keeps a diagram layout per document, so no instance is reused across calls.
 */
final class FlowBpmnInterchange implements FlowBpmnInterchangeInterface {

	/**
	 * {@inheritDoc}
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string The XML.
	 */
	public function export(Flow $flow): string {
		return (new FlowBpmnExporter(vocabulary: new BpmnVocabulary(), validator: new BpmnSchemaValidator()))->export(flow: $flow);
	}//end export()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $xml The file.
	 *
	 * @return array{flow: array<string, mixed>, report: BpmnMappingReport} The result.
	 */
	public function import(string $xml): array {
		return $this->importer()->import(xml: $xml);
	}//end import()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $xml The file.
	 *
	 * @return array{flow: array<string, mixed>, report: BpmnMappingReport} The result.
	 */
	public function importStrictly(string $xml): array {
		return $this->importer()->importStrictly(xml: $xml);
	}//end importStrictly()

	/**
	 * A fresh importer.
	 *
	 * @return FlowBpmnImporter The importer.
	 */
	private function importer(): FlowBpmnImporter {
		return new FlowBpmnImporter(vocabulary: new BpmnVocabulary(), validator: new BpmnSchemaValidator());
	}//end importer()
}//end class
