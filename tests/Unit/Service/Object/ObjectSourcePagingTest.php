<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ObjectSourcePageReader;
use OCA\OpenRegister\Service\Object\ObjectSourceQuery;
use OCA\OpenRegister\Service\Object\ObjectSourceWindow;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSourceRead;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\ObjectSourcePageReader
 * @covers \OCA\OpenRegister\Service\Object\ObjectSourceQuery
 * @covers \OCA\OpenRegister\Service\Object\ObjectSourceWindow
 * @uses   \OCA\OpenRegister\Service\ObjectSourceRead
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration
 */
class ObjectSourcePagingTest extends TestCase {
	public function testANegativeOffsetCountsAsZero(): void {
		$this->assertSame(0, (new ObjectSourceWindow(['offset' => -5]))->offset);
	}//end testANegativeOffsetCountsAsZero()

	public function testOnlyScalarFieldsBecomeFilters(): void {
		$query = (new ObjectSourceQuery())->forProvider(
			['status' => 'open', 'tags' => ['a'], '@self' => 'x', '_search' => 'y', 'extend' => 'z']
		);

		$this->assertSame(['status' => 'open'], $query['filters']);
	}//end testOnlyScalarFieldsBecomeFilters()

	public function testAnEnabledProviderIsNotAskedWithoutARegister(): void {
		$provider = $this->createMock(ObjectSourceProvider::class);
		$provider->method('isEnabled')->willReturn(true);
		$provider->expects($this->never())->method('findAll');
		$schema = new Schema();
		$schema->setConfiguration(['x-openregister-object-source' => ['provider' => 'stub']]);

		$read   = new ObjectSourceRead(provider: $provider, schema: $schema, query: [], config: []);
		$result = (new ObjectSourcePageReader(new NullLogger()))->read($read, null);

		$this->assertSame([], $result['results']);
		$this->assertSame(0, $result['total']);
	}//end testAnEnabledProviderIsNotAskedWithoutARegister()
}//end class
