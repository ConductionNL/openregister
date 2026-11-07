<?php

/**
 * Unit tests for PackTransformValidator.
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

use OCA\OpenRegister\Service\MigrationPack\PackTransformValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The transform validator reports against the path it is given and stops at
 * the first finding.
 *
 * @covers \OCA\OpenRegister\Service\MigrationPack\PackTransformValidator
 */
class PackTransformValidatorTest extends TestCase {

	/**
	 * A validator whose translations fill in the parameters.
	 *
	 * @return PackTransformValidator
	 */
	private function validator(): PackTransformValidator {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => vsprintf($text, $params)
		);
		return new PackTransformValidator($l10n);
	}//end validator()

	/**
	 * @return void
	 */
	public function testFindingsUseTheGivenPath(): void {
		$this->assertSame(
			['col.type must be one of: trim, date, bool-map, concat, lookup, const (got \'x\')'],
			$this->validator()->validate(['type' => 'x'], 'col')
		);
		$this->assertSame(['col must be an object with a "type" key'], $this->validator()->validate('trim', 'col'));
		$this->assertSame(
			['col.fields[1] must be a non-empty string'],
			$this->validator()->validate(['type' => 'concat', 'fields' => ['a', '', 3]], 'col')
		);
	}//end testFindingsUseTheGivenPath()

	/**
	 * @return void
	 */
	public function testEachTypeWithItsRequiredFieldsPasses(): void {
		$validator = $this->validator();
		$this->assertSame([], $validator->validate(['type' => 'trim'], 'p'));
		$this->assertSame([], $validator->validate(['type' => 'date', 'sourceFormat' => 'd-m-Y'], 'p'));
		$this->assertSame([], $validator->validate(['type' => 'bool-map', 'map' => ['ja' => true]], 'p'));
		$this->assertSame([], $validator->validate(['type' => 'lookup', 'map' => ['a' => 'b']], 'p'));
		$this->assertSame([], $validator->validate(['type' => 'concat', 'fields' => ['a']], 'p'));
		$this->assertSame([], $validator->validate(['type' => 'const', 'value' => null], 'p'));
	}//end testEachTypeWithItsRequiredFieldsPasses()
}//end class
