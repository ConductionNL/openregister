<?php

/**
 * The derived share-principal lists are recomputed at the table, on both write shapes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/shared-credentials-and-flows/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicSharePrincipals;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver;
use OCA\OpenRegister\Service\Sharing\SharePrincipalDeriverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSharePrincipals
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Sharing\SharePrincipalDeriver
 */
class MagicSharePrincipalsTest extends TestCase {

	private const SHARED = [
		['type' => 'user', 'id' => 'alice', 'permission' => 'use'],
		['type' => 'group', 'id' => 'finance', 'permission' => 'use'],
	];

	private function schema(bool $shares = true): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('brokeredcredential');
		$properties = ['name' => ['type' => 'string']];
		if ($shares === true) {
			$properties += [
				'sharedWith' => ['type' => 'array'],
				'sharedUsers' => ['type' => 'array'],
				'sharedGroups' => ['type' => 'array'],
			];
		}

		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	private function principals(mixed $deriver = null, bool $expectLookup = true): MagicSharePrincipals {
		$container = $this->createMock(ContainerInterface::class);
		if ($expectLookup === false) {
			$container->expects($this->never())->method('get');
		} else {
			$container->method('get')->with(SharePrincipalDeriverInterface::class)->willReturn($deriver ?? new SharePrincipalDeriver());
		}

		return new MagicSharePrincipals(container: $container);
	}//end principals()

	public function testASchemaWithAllThreeListsDeclaresThem(): void {
		$this->assertTrue(MagicSharePrincipals::declares(schema: $this->schema()));
	}//end testASchemaWithAllThreeListsDeclaresThem()

	public function testASchemaMissingOneDerivedListDoesNotDeclareThem(): void {
		$schema = $this->schema(shares: false);
		$schema->setProperties(['sharedWith' => ['type' => 'array'], 'sharedUsers' => ['type' => 'array']]);

		$this->assertFalse(MagicSharePrincipals::declares(schema: $schema));
	}//end testASchemaMissingOneDerivedListDoesNotDeclareThem()

	public function testAnotherSchemaPassesThroughUntouchedAndResolvesNothing(): void {
		$data = ['name' => 'x', 'sharedUsers' => ['mallory']];

		$out = $this->principals(expectLookup: false)->forObject(data: $data, schema: $this->schema(shares: false));

		$this->assertSame($data, $out);
	}//end testAnotherSchemaPassesThroughUntouchedAndResolvesNothing()

	public function testAClientSuppliedDerivedListIsReplacedByTheShareList(): void {
		$data = ['name' => 'x', 'sharedWith' => self::SHARED, 'sharedUsers' => ['mallory'], 'sharedGroups' => ['admin']];

		$out = $this->principals()->forObject(data: $data, schema: $this->schema());

		$this->assertSame(['alice'], $out['sharedUsers']);
		$this->assertSame(['finance'], $out['sharedGroups']);
		$this->assertSame(self::SHARED, $out['sharedWith']);
		$this->assertSame('x', $out['name']);
	}//end testAClientSuppliedDerivedListIsReplacedByTheShareList()

	public function testAnAbsentShareListClearsStalePrincipals(): void {
		$out = $this->principals()->forObject(data: ['sharedUsers' => ['bob'], 'sharedGroups' => ['ops']], schema: $this->schema());

		$this->assertSame([], $out['sharedUsers']);
		$this->assertSame([], $out['sharedGroups']);
	}//end testAnAbsentShareListClearsStalePrincipals()

	public function testABulkRowUnderObjectIsDerived(): void {
		$rows = [['@self' => ['id' => 'u1'], 'object' => ['sharedWith' => self::SHARED, 'sharedUsers' => ['mallory']]]];

		$out = $this->principals()->forRows(objects: $rows, schema: $this->schema());

		$this->assertSame(['id' => 'u1'], $out[0]['@self']);
		$this->assertSame(['alice'], $out[0]['object']['sharedUsers']);
		$this->assertSame(['finance'], $out[0]['object']['sharedGroups']);
	}//end testABulkRowUnderObjectIsDerived()

	public function testATopLevelBulkRowIsDerivedAndKeepsItsSelf(): void {
		$rows = [
			'a' => ['@self' => ['id' => 'u2'], 'sharedWith' => [['type' => 'user', 'id' => 'carol']], 'sharedGroups' => ['admin']],
			'b' => 'not-a-row',
		];

		$out = $this->principals()->forRows(objects: $rows, schema: $this->schema());

		$this->assertSame(['id' => 'u2'], $out['a']['@self']);
		$this->assertSame(['carol'], $out['a']['sharedUsers']);
		$this->assertSame([], $out['a']['sharedGroups']);
		$this->assertSame('not-a-row', $out['b']);
	}//end testATopLevelBulkRowIsDerivedAndKeepsItsSelf()

	public function testBulkRowsOfAnotherSchemaPassThrough(): void {
		$rows = [['sharedUsers' => ['mallory']]];

		$this->assertSame($rows, $this->principals(expectLookup: false)->forRows(objects: $rows, schema: $this->schema(shares: false)));
	}//end testBulkRowsOfAnotherSchemaPassThrough()

	public function testWithoutADeriverTheWriteIsRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not bound'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('"brokeredcredential" carries a share list');

		(new MagicSharePrincipals(container: $container))->forObject(data: ['sharedWith' => self::SHARED], schema: $this->schema());
	}//end testWithoutADeriverTheWriteIsRefused()

	public function testAWrongServiceIsRefusedToo(): void {
		$this->expectException(RuntimeException::class);

		$this->principals(deriver: new \stdClass())->forRows(objects: [['sharedWith' => self::SHARED]], schema: $this->schema());
	}//end testAWrongServiceIsRefusedToo()
}//end class
