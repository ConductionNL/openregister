<?php

/**
 * Unit tests for MappingTransformer.
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

use OCA\OpenRegister\Service\MigrationPack\MappingTransformer;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Transforms return a value or a translated error; pointers walk nested rows.
 *
 * @covers \OCA\OpenRegister\Service\MigrationPack\MappingTransformer
 */
class MappingTransformerTest extends TestCase {

	/**
	 * A transformer whose translations fill in the parameters.
	 *
	 * @return MappingTransformer
	 */
	private function transformer(): MappingTransformer {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		return new MappingTransformer($l10n);
	}//end transformer()

	/**
	 * @return void
	 */
	public function testApply(): void {
		$transformer = $this->transformer();
		$row = ['Achter' => 'Jansen'];
		$this->assertSame(['value' => ' x ', 'error' => null], $transformer->apply(' x ', null, $row));
		$this->assertSame(['value' => 'x', 'error' => null], $transformer->apply(' x ', ['type' => 'trim'], $row));
		$this->assertSame(['value' => 'Jan Jansen', 'error' => null], $transformer->apply('Jan', ['type' => 'concat', 'fields' => ['Achter']], $row));
		$this->assertSame(['value' => true, 'error' => null], $transformer->apply('J', ['type' => 'bool-map', 'map' => ['J' => 1]], $row));
		$this->assertSame(
			['value' => null, 'error' => 'Value "N" has no mapping and no default is configured'],
			$transformer->apply('N', ['type' => 'lookup', 'map' => []], $row)
		);
		$this->assertSame(['value' => null, 'error' => 'Unknown transform type "x"'], $transformer->apply('a', ['type' => 'x'], $row));
	}//end testApply()

	/**
	 * @return void
	 */
	public function testResolveSource(): void {
		$transformer = $this->transformer();
		$row = ['a' => ['b/c' => ['d' => 1]], 'e' => 2];
		$this->assertSame(1, $transformer->resolveSource($row, '/a/b~1c/d'));
		$this->assertSame(2, $transformer->resolveSource($row, 'e'));
		$this->assertNull($transformer->resolveSource($row, '/a/x'));
		$this->assertNull($transformer->resolveSource($row, ''));
	}//end testResolveSource()
}//end class
