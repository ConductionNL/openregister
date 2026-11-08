<?php

/**
 * FlowBpmnInterchangeInterface: the BPMN export and import callers outside the component use.
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
use OCA\OpenRegister\Exception\BpmnImportRefused;
use OCA\OpenRegister\Exception\BpmnSchemaInvalid;

/**
 * A flow to BPMN 2.0 XML and back.
 *
 * Declares only what callers outside the component use; the exporter, the
 * importer and the schema validator behind it stay the component's own.
 */
interface FlowBpmnInterchangeInterface {

	/**
	 * A flow as BPMN 2.0 XML, validated before it is returned.
	 *
	 * @param Flow $flow The flow.
	 *
	 * @return string The XML.
	 *
	 * @throws BpmnSchemaInvalid When our own output does not validate, which is a bug here.
	 */
	public function export(Flow $flow): string;

	/**
	 * Read a BPMN file into a flow document and a mapping report.
	 *
	 * Constructs that do not map are reported and the flow is still created.
	 *
	 * @param string $xml The file.
	 *
	 * @return array{flow: array<string, mixed>, report: BpmnMappingReport} The result.
	 *
	 * @throws BpmnImportRefused When the file cannot be read.
	 * @throws BpmnSchemaInvalid When the document is not valid BPMN 2.0, which is a different answer.
	 */
	public function import(string $xml): array;

	/**
	 * Read a BPMN file, refusing it outright when anything does not map.
	 *
	 * @param string $xml The file.
	 *
	 * @return array{flow: array<string, mixed>, report: BpmnMappingReport} The result.
	 *
	 * @throws BpmnImportRefused When the file cannot be read, or when anything in it is refused.
	 * @throws BpmnSchemaInvalid When the document is not valid BPMN 2.0, which is a different answer.
	 */
	public function importStrictly(string $xml): array;
}//end interface
