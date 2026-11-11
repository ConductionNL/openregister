<?php

declare(strict_types=1);

/**
 * RegisterMapperHelper Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\RegisterMapperHelper;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure helper routines extracted from RegisterMapper.
 */
class RegisterMapperHelperTest extends TestCase {
	private RegisterMapperHelper $helper;

	protected function setUp(): void {
		parent::setUp();
		$this->helper = new RegisterMapperHelper();
	}

	/**
	 * @dataProvider bumpProvider
	 */
	public function testBumpPatchVersion(string $input, string $expected): void {
		$this->assertSame($expected, $this->helper->bumpPatchVersion($input));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function bumpProvider(): array {
		return [
			'full semver' => ['1.0.0', '1.0.1'],
			'preserves prerelease' => ['1.0.0-beta', '1.0.1-beta'],
			'preserves build suffix' => ['2.3.4+build.9', '2.3.5+build.9'],
			'pads bare major' => ['1', '1.0.1'],
			'pads major.minor' => ['1.2', '1.2.1'],
			'trims whitespace' => ['  3.4.5  ', '3.4.6'],
			'unparsable falls back' => ['not-a-version', '0.0.1'],
			'empty falls back' => ['', '0.0.1'],
		];
	}

	public function testDecodeSchemasFieldPassesArraysThrough(): void {
		$this->assertSame([1, 2, 3], $this->helper->decodeSchemasField([1, 2, 3]));
	}

	public function testDecodeSchemasFieldDecodesJson(): void {
		$this->assertSame([4, 5], $this->helper->decodeSchemasField('[4,5]'));
	}

	public function testDecodeSchemasFieldFallsBackToCommaSeparated(): void {
		$this->assertSame(['7', '8'], array_values($this->helper->decodeSchemasField('7, 8')));
	}

	public function testDecodeSchemasFieldReturnsEmptyForEmptyString(): void {
		$this->assertSame([], $this->helper->decodeSchemasField(''));
	}

	public function testDecodeSchemasFieldReturnsEmptyForUnexpectedType(): void {
		$this->assertSame([], $this->helper->decodeSchemasField(42));
		$this->assertSame([], $this->helper->decodeSchemasField(null));
	}
}
