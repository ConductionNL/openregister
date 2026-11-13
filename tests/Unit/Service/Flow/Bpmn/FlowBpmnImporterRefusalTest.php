<?php

/**
 * Unit test for FlowBpmnImporter's refusals before any mapping: an empty file,
 * a file that is no XML, and a valid BPMN file that holds no process.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use OCA\OpenRegister\Exception\BpmnImportRefused;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary;
use OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\FlowBpmnImporter
 * @uses   \OCA\OpenRegister\Exception\BpmnImportRefused
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnVocabulary
 * @uses   \OCA\OpenRegister\Service\Flow\Bpmn\BpmnDiagramLayout
 */
class FlowBpmnImporterRefusalTest extends TestCase {

	/**
	 * The refusal an import makes.
	 *
	 * @param string $xml The file.
	 *
	 * @return string
	 */
	private function refusal(string $xml): string {
		$importer = new FlowBpmnImporter(vocabulary: new BpmnVocabulary(), validator: new BpmnSchemaValidator());
		try {
			$importer->import(xml: $xml);
		} catch (BpmnImportRefused $e) {
			return $e->getMessage();
		}

		return 'imported';
	}//end refusal()

	/**
	 * An empty or blank file is refused as empty.
	 *
	 * @return void
	 */
	public function testAnEmptyFileIsRefused(): void {
		$this->assertSame('The file is empty.', $this->refusal(xml: ''));
		$this->assertSame('The file is empty.', $this->refusal(xml: "  \n "));
	}//end testAnEmptyFileIsRefused()

	/**
	 * A file that is no XML is refused with the parser's first error.
	 *
	 * @return void
	 */
	public function testAFileThatIsNoXmlIsRefused(): void {
		$this->assertStringStartsWith('The file could not be read: ', $this->refusal(xml: '<definitions'));
	}//end testAFileThatIsNoXmlIsRefused()

	/**
	 * A valid BPMN file without a process holds no flow.
	 *
	 * @return void
	 */
	public function testAFileWithoutAProcessIsRefused(): void {
		$this->assertSame(
			'The file declares no bpmn:process, so there is no flow in it.',
			$this->refusal(xml: '<bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" id="d" targetNamespace="x"/>')
		);
	}//end testAFileWithoutAProcessIsRefused()
}//end class
