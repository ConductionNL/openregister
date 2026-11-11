<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

/**
 * Golden test for ImportSelection.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Service\Configuration\ImportSelection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins isEmpty(), filter() and hasContent() for each document and selection.
 *
 * Compared with fixtures/import-selection-golden.json. Set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportSelection
 */
class ImportSelectionGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/import-selection-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$document = [
			'openapi' => '3.0.0',
			'info' => ['title' => 'Doc', 'version' => '1.2.3'],
			'x-openregister' => ['type' => 'application', 'seedData' => ['a' => 1], 'app' => 'x'],
			'objects' => [['title' => 'top-level']],
			'components' => [
				'registers' => ['Zaken' => ['title' => 'Zaken'], 'publicaties' => ['title' => 'Pub'], 5 => ['title' => 'Five']],
				'schemas' => ['ZaakType' => ['title' => 'ZT'], 'besluit' => ['title' => 'B']],
				'objects' => [
					['title' => 'one', '@self' => ['register' => 'Zaken', 'schema' => 'ZaakType', 'slug' => 'Obj-1']],
					['title' => 'two', '@self' => ['register' => 'zaken', 'schema' => 'besluit', 'slug' => 'obj-2']],
					['title' => 'no self'],
					['title' => 'partial', '@self' => ['slug' => 'p']],
					'scalar row',
				],
				'endpoints' => ['e' => []],
				'mappings' => ['m' => []],
			],
		];
		return [
			'empty selection' => [$document, []],
			'empty lists' => [$document, ['registers' => [], 'schemas' => [], 'objects' => []]],
			'non-array lists' => [$document, ['registers' => 'zaken', 'schemas' => null, 'objects' => 5]],
			'registers mixed case' => [$document, ['registers' => ['zaken', 'PUBLICATIES', 'missing', 5]]],
			'schemas only' => [$document, ['schemas' => ['zaaktype']]],
			'objects by key' => [$document, ['objects' => ['zaken:zaaktype:obj-1', 'ZAKEN:BESLUIT:OBJ-2', '::p', 'zaken:besluit:none']]],
			'everything' => [$document, ['registers' => ['Zaken'], 'schemas' => ['besluit'], 'objects' => ['zaken:besluit:obj-2']]],
			'no components' => [['openapi' => '3.0.0', 'x-openregister' => 'string meta'], ['registers' => ['zaken']]],
			'components not arrays' => [['components' => ['registers' => 'x', 'schemas' => 7, 'objects' => 'y']], ['registers' => ['x'], 'schemas' => ['7'], 'objects' => ['y']]],
			'other selection keys' => [$document, ['endpoints' => ['e'], 'mappings' => ['m']]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $document
	 * @param array<string, mixed> $selection
	 */
	#[DataProvider('cases')]
	public function testSelectionMatchesGolden(array $document, array $selection): void {
		$filtered = ImportSelection::filter($document, $selection);
		$actual = json_decode(json_encode([
			'isEmpty' => ImportSelection::isEmpty($selection),
			'filtered' => $filtered,
			'hasContent' => ImportSelection::hasContent($filtered),
		]), true);

		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testSelectionMatchesGolden()
}//end class
