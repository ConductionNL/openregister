<?php

/**
 * Golden test for PackDefinitionValidator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\MigrationPack
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/migration-mapping-packs/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\MigrationPack;

use OCA\OpenRegister\Exception\InvalidPackDefinitionException;
use OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Runs every case below through validate() and compares the full grid of
 * findings, each as its untranslated text plus its parameters, with
 * fixtures/pack-definition-validator-golden.json. Set UPDATE_GOLDEN=1 to
 * rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\MigrationPack\PackDefinitionValidator
 */
class PackDefinitionValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/pack-definition-validator-golden.json';

	/**
	 * A validator whose translations show the source text and the parameters.
	 *
	 * @return PackDefinitionValidator
	 */
	private function validator(): PackDefinitionValidator {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => $text . ' <' . json_encode($params, JSON_UNESCAPED_SLASHES) . '>'
		);
		return new PackDefinitionValidator($l10n);
	}//end validator()

	/**
	 * The valid pack every case overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function base(): array {
		return [
			'id' => 'test-pack',
			'name' => 'Test Pack',
			'sourceFormat' => 'csv',
			'version' => '1.0.0',
			'idStrategy' => ['type' => 'generate'],
			'fieldMappings' => [['source' => 'Name', 'target' => 'title']],
		];
	}//end base()

	/**
	 * Every case: overrides on the valid pack, or null for an empty document.
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private static function cases(): array {
		return [
			'valid pack' => [],
			'empty document' => null,
			'id integer' => ['id' => 5],
			'id empty' => ['id' => ''],
			'id uppercase' => ['id' => 'Abc'],
			'id leading hyphen' => ['id' => '-a'],
			'id slug with digits' => ['id' => '1-a-b-2'],
			'name integer' => ['name' => 3],
			'name empty' => ['name' => ''],
			'source format null' => ['sourceFormat' => null],
			'source format unknown' => ['sourceFormat' => 'xml'],
			'source format integer' => ['sourceFormat' => 5],
			'source format excel' => ['sourceFormat' => 'excel'],
			'version short' => ['version' => '1.0'],
			'version integer' => ['version' => 1],
			'version prerelease' => ['version' => '1.0.0-beta'],
			'mappings string' => ['fieldMappings' => 'x'],
			'mappings empty' => ['fieldMappings' => []],
			'mappings rows' => ['fieldMappings' => [
				'first' => 'x',
				'second' => [],
				'third' => ['source' => '', 'target' => 5, 'required' => 'yes'],
				'fourth' => ['source' => 'a', 'target' => 'b', 'required' => null, 'transform' => null],
				'fifth' => ['source' => 'a', 'target' => 'b', 'required' => false, 'transform' => 'trim'],
				'sixth' => ['source' => 'a', 'target' => 'b', 'required' => true, 'transform' => ['type' => 'trim']],
			]],
			'transform types' => ['fieldMappings' => [
				['source' => 'a', 'target' => 'b', 'transform' => []],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'nope']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 5]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'date']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'date', 'sourceFormat' => 5]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'date', 'targetFormat' => []]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'date', 'sourceFormat' => 1, 'targetFormat' => 2]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'date', 'sourceFormat' => null, 'targetFormat' => 'Y-m-d']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'bool-map']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'bool-map', 'map' => []]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'bool-map', 'map' => 'x']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'bool-map', 'map' => ['ja' => true]]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'lookup']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'lookup', 'map' => ['a' => 'b']]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'concat']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'concat', 'fields' => 'x']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'concat', 'fields' => ['a', '', 5]]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'concat', 'fields' => ['k' => 5]]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'concat', 'fields' => ['a', 'b']]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'const']],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'const', 'value' => null]],
				['source' => 'a', 'target' => 'b', 'transform' => ['type' => 'trim', 'extra' => 1]],
			]],
			'defaults null' => ['defaults' => null],
			'defaults string' => ['defaults' => 'x'],
			'defaults empty' => ['defaults' => []],
			'skip rows null' => ['skipRows' => null],
			'skip rows string' => ['skipRows' => 'x'],
			'skip rows valid' => ['skipRows' => [1, 2]],
			'skip rows zero' => ['skipRows' => [0]],
			'skip rows numeric string' => ['skipRows' => [1, '2', -1]],
			'skip rows empty' => ['skipRows' => []],
			'id strategy missing' => ['idStrategy' => null],
			'id strategy string' => ['idStrategy' => 'x'],
			'id strategy unknown type' => ['idStrategy' => ['type' => 'nope']],
			'id strategy no type' => ['idStrategy' => []],
			'id strategy source field missing' => ['idStrategy' => ['type' => 'sourceField']],
			'id strategy source field empty' => ['idStrategy' => ['type' => 'sourceField', 'field' => '']],
			'id strategy source field integer' => ['idStrategy' => ['type' => 'sourceField', 'field' => 5]],
			'id strategy source field set' => ['idStrategy' => ['type' => 'sourceField', 'field' => 'Nr']],
			'everything wrong at once' => [
				'id' => 'A B',
				'name' => null,
				'sourceFormat' => 'pdf',
				'version' => 'v1',
				'fieldMappings' => [['transform' => ['type' => 'const']]],
				'defaults' => 1,
				'skipRows' => [0],
				'idStrategy' => ['type' => 'sourceField'],
			],
		];
	}//end cases()

	/**
	 * @return void
	 */
	public function testFindingsMatchTheGoldenGrid(): void {
		$validator = $this->validator();
		$actual = [];
		foreach (self::cases() as $name => $overrides) {
			$definition = [];
			if ($overrides !== null) {
				$definition = array_merge(self::base(), $overrides);
			}

			$actual[$name] = $validator->validate($definition);
		}

		$json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, $json);
		}

		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(file_get_contents(self::FIXTURE), $json);
	}//end testFindingsMatchTheGoldenGrid()

	/**
	 * @return void
	 */
	public function testAssertValidCarriesTheSameFindings(): void {
		$validator = $this->validator();
		$definition = array_merge(self::base(), ['id' => 'A', 'skipRows' => [0]]);
		try {
			$validator->assertValid($definition);
			$this->fail('assertValid() accepted an invalid pack');
		} catch (InvalidPackDefinitionException $exception) {
			$this->assertSame($validator->validate($definition), $exception->getErrors());
			$this->assertCount(2, $exception->getErrors());
		}

		$validator->assertValid(self::base());
	}//end testAssertValidCarriesTheSameFindings()
}//end class
