<?php

/**
 * Unit test for BpmnSchemaValidator's edges: a document without a root has a
 * violation that names no element and no line, and only the vendored schema
 * files resolve as schema references.
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

use DOMDocument;
use OCA\OpenRegister\Exception\BpmnSchemaInvalid;
use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator
 * @uses   \OCA\OpenRegister\Exception\BpmnSchemaInvalid
 */
class BpmnSchemaValidatorEdgeTest extends TestCase {

	/**
	 * A document without a root element is a violation naming no element.
	 *
	 * @return void
	 */
	public function testADocumentWithoutARootNamesNoElement(): void {
		$violation = (new BpmnSchemaValidator())->firstViolation(document: new DOMDocument());

		$this->assertSame('The document has no document element.', $violation['message']);
		$this->assertSame('', $violation['element']);
		$this->assertLessThanOrEqual(0, $violation['line']);
	}//end testADocumentWithoutARootNamesNoElement()

	/**
	 * A violation without a line is refused without a line in its message; one with a line names it and its element.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheLineOnlyWhenThereIsOne(): void {
		$validator = new BpmnSchemaValidator();
		try {
			$validator->assertValid(document: new DOMDocument(), subject: 'Het bestand');
			$this->fail('an empty document must be refused');
		} catch (BpmnSchemaInvalid $e) {
			$this->assertSame('Het bestand is not valid BPMN 2.0.2: The document has no document element.', $e->getMessage());
		}

		$document = new DOMDocument();
		$document->loadXML('<x/>');
		try {
			$validator->assertValid(document: $document);
			$this->fail('a foreign root must be refused');
		} catch (BpmnSchemaInvalid $e) {
			$this->assertStringStartsWith('The file is not valid BPMN 2.0.2 on line 1: Element \'x\'', $e->getMessage());
		}
	}//end testTheRefusalNamesTheLineOnlyWhenThereIsOne()

	/**
	 * Only a vendored schema file resolves, by name or file URL; an empty, outside or unknown reference does not.
	 *
	 * @return void
	 */
	public function testOnlyVendoredSchemaFilesResolve(): void {
		$validator = new BpmnSchemaValidator();
		$directory = (string) realpath($validator->schemaDirectory());

		$this->assertSame($directory.'/Semantic.xsd', $validator->resolveSchemaReference(systemId: 'Semantic.xsd'));
		$this->assertSame($directory.'/BPMN20.xsd', $validator->resolveSchemaReference(systemId: 'file://'.rawurlencode($directory.'/BPMN20.xsd')));
		$this->assertNull($validator->resolveSchemaReference(systemId: ''));
		$this->assertNull($validator->resolveSchemaReference(systemId: '/etc/hostname'));
		$this->assertNull($validator->resolveSchemaReference(systemId: '../BpmnSchemaValidator.php'));
		$this->assertNull($validator->resolveSchemaReference(systemId: 'Missing.xsd'));
	}//end testOnlyVendoredSchemaFilesResolve()

	/**
	 * A file outside the schema directory that carries a vendored file's name does not resolve.
	 *
	 * @return void
	 */
	public function testAVendoredNameOutsideTheDirectoryDoesNotResolve(): void {
		$directory = sys_get_temp_dir().'/n17-bpmn-'.bin2hex(random_bytes(4));
		mkdir($directory);
		$impostor = $directory.'/Semantic.xsd';
		file_put_contents($impostor, '<xsd:schema xmlns:xsd="http://www.w3.org/2001/XMLSchema"/>');

		try {
			$this->assertNull((new BpmnSchemaValidator())->resolveSchemaReference(systemId: $impostor));
		} finally {
			unlink($impostor);
			rmdir($directory);
		}
	}//end testAVendoredNameOutsideTheDirectoryDoesNotResolve()
}//end class
