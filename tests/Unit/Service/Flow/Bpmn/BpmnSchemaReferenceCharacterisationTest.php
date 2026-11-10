<?php

/**
 * Characterisation of BpmnSchemaValidator::resolveSchemaReference().
 *
 * Pins which system ids resolve to a vendored schema file and which come
 * back null, including encoded and URI forms, on the current code.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Bpmn;

use OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Bpmn\BpmnSchemaValidator
 */
final class BpmnSchemaReferenceCharacterisationTest extends TestCase {

	/**
	 * Each system id and whether it resolves, by vendored basename.
	 *
	 * @return void
	 */
	public function testWhichReferencesResolve(): void {
		$validator = new BpmnSchemaValidator();
		$directory = realpath($validator->schemaDirectory());
		$this->assertNotFalse($directory);

		$cases = [
			'BPMN20.xsd' => 'BPMN20.xsd',
			'Semantic%2Exsd' => 'Semantic.xsd',
			'file://' . $directory . '/DC.xsd' => 'DC.xsd',
			$directory . '/DI.xsd' => 'DI.xsd',
			'./BPMNDI.xsd' => 'BPMNDI.xsd',
			'file://' => null,
			'%2E%2E%2F%2E%2E%2Fcomposer.json' => null,
			'../schema/BPMN20.xsd' => 'BPMN20.xsd',
			'../Bpmn/BpmnSchemaValidator.php' => null,
			'PROVENANCE.md' => null,
			'missing.xsd' => null,
			'file:///etc/hostname' => null,
			$directory => null,
		];

		$actual = [];
		foreach (array_keys($cases) as $systemId) {
			$resolved = $validator->resolveSchemaReference(systemId: (string)$systemId);
			if ($resolved !== null) {
				$this->assertSame($directory, dirname($resolved));
				$resolved = basename($resolved);
			}

			$actual[$systemId] = $resolved;
		}

		$this->assertSame($cases, $actual);
	}//end testWhichReferencesResolve()
}//end class
