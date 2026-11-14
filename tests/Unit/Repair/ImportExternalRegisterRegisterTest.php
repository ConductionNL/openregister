<?php

/**
 * Unit tests for ImportExternalRegisterRegister and its descriptor: three
 * sourced schemas, served by the openconnector-http object source and off
 * until a source is named.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/external-register-view-leaf/specs/object-source-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Repair\ImportExternalRegisterRegister;
use OCA\OpenRegister\Service\ObjectSource\OpenConnectorHttpProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;

/**
 * @covers \OCA\OpenRegister\Repair\ImportExternalRegisterRegister
 * @uses \OCA\OpenRegister\Repair\AbstractRegisterImportStep
 */
final class ImportExternalRegisterRegisterTest extends TestCase {

	private const SCHEMAS = ['bag-adres', 'brk-perceel', 'woz-waarde'];

	/**
	 * The descriptor the step imports.
	 *
	 * @return array<string, mixed>
	 */
	private function descriptor(): array {
		$path = dirname(__DIR__, 3) . (new ReflectionClassConstant(ImportExternalRegisterRegister::class, 'REGISTER_PATH'))->getValue();
		return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	}

	public function testTheStepNamesWhatItImports(): void {
		$step = (new ReflectionClass(ImportExternalRegisterRegister::class))->newInstanceWithoutConstructor();

		$this->assertSame('Import OpenRegister external-registers register (BAG, BRK and WOZ sourced schemas)', $step->getName());
	}

	public function testTheRegisterHoldsTheThreeSchemas(): void {
		$descriptor = $this->descriptor();

		$this->assertSame('core', $descriptor['x-openregister']['type']);
		$this->assertSame(self::SCHEMAS, $descriptor['components']['registers']['external-registers']['schemas']);
		$this->assertSame(self::SCHEMAS, array_keys($descriptor['components']['schemas']));
	}

	public function testEverySchemaIsSourcedReadOnlyAndOffUntilASourceIsNamed(): void {
		foreach ($this->descriptor()['components']['schemas'] as $slug => $schema) {
			$source = $schema['configuration']['x-openregister-object-source'];
			$this->assertSame(OpenConnectorHttpProvider::ID, $source['provider'], $slug);
			$this->assertTrue($source['readOnly'], $slug);
			$this->assertSame('', $source['config']['source'], $slug . ' must ship without a source');
			$this->assertStringContainsString('{key}', $source['config']['find'], $slug);
			$this->assertArrayHasKey($source['config']['id'], $schema['properties'], $slug . ' must declare the field its id comes from');
		}
	}
}
