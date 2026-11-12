<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Repair\SeedZgwZakenMigrationPack;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for the built-in migration pack definition the repair step seeds.
 *
 * Compared with fixtures/seed-migration-pack-definition-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Repair\SeedZgwZakenMigrationPack
 */
class SeedMigrationPackDefinitionGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/seed-migration-pack-definition-golden.json';

	public function testDefinitionMatchesGolden(): void {
		$step = (new \ReflectionClass(SeedZgwZakenMigrationPack::class))->newInstanceWithoutConstructor();
		$actual = json_decode(json_encode((new \ReflectionMethod($step, 'definition'))->invoke($step)), true);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertFileExists(self::FIXTURE);
		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $actual);
	}//end testDefinitionMatchesGolden()
}//end class
