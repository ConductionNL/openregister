<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261113160000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261113160000: the follower table and the notice record on the changelog.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113160000
 */
class Version1Date20261113160000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheFollowerTableIsCreatedUniqueOnSchemaAndUser(): void {
		$followers = $this->createTableMock();
		$followers->expects($this->exactly(4))->method('addColumn')->willReturn($this->createMock(IColumn::class));
		$followers->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$followers->expects($this->once())->method('addUniqueIndex')->with(['schema_id', 'uid'], 'or_schema_follower_uniq');
		$changelog = $this->createTableMock();
		$changelog->method('hasColumn')->willReturn(false);
		$added = [];
		$changelog->method('addColumn')->willReturnCallback(
			function (string $name) use (&$added) {
				$added[] = $name;
				return $this->createMock(IColumn::class);
			}
		);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnMap([['openregister_schema_followers', false], ['openregister_schema_changelog', true]]);
		$schema->method('createTable')->with('openregister_schema_followers')->willReturn($followers);
		$schema->method('getTable')->with('openregister_schema_changelog')->willReturn($changelog);

		(new Version1Date20261113160000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame(['notice_sent_to', 'notice_sent_at'], $added);
	}

	public function testAnInstallThatHasBothIsLeftAlone(): void {
		$changelog = $this->createTableMock();
		$changelog->method('hasColumn')->willReturn(true);
		$changelog->expects($this->never())->method('addColumn');
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');
		$schema->method('getTable')->willReturn($changelog);

		$this->assertSame($schema, (new Version1Date20261113160000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
