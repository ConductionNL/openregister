<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Dmn
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Listener\DecisionTableValidationListener;
use OCA\OpenRegister\Service\Dmn\DecisionTableBulkGuard;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bulk writer dispatches no ObjectCreatingEvent, so the decision-table rules run through this guard.
 *
 * @covers \OCA\OpenRegister\Service\Dmn\DecisionTableBulkGuard
 * @covers \OCA\OpenRegister\Listener\DecisionTableValidationListener
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator
 * @uses \OCA\OpenRegister\Service\Dmn\DecisionTableValidator
 * @uses \OCA\OpenRegister\Service\Dmn\UnaryTestEvaluator
 * @uses \OCA\OpenRegister\Service\Object\SaveObjects
 */
class DecisionTableBulkGuardTest extends TestCase {

	/**
	 * A guard whose schema `7` is the decision-table schema and `8` another one.
	 *
	 * @param array<int, ObjectEntity> $stored Tables already stored under the key.
	 *
	 * @return DecisionTableBulkGuard
	 */
	private function guard(array $stored = []): DecisionTableBulkGuard {
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string|int $id): Schema {
				$schema = new Schema();
				$schema->setSlug((string)$id === '8' ? 'other' : 'decision-table');

				return $schema;
			}
		);
		$objects = $this->createMock(ObjectService::class);
		$objects->method('searchObjectsBySlug')->willReturn($stored);
		$register = new Register();
		$register->setSlug('decision-tables');
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);

		$listener  = new DecisionTableValidationListener($schemas, new DecisionTableValidator(), $objects, $registers);
		$container = $this->createMock(\Psr\Container\ContainerInterface::class);
		$container->method('get')->with(DecisionTableValidationListener::class)->willReturn($listener);

		return new DecisionTableBulkGuard($container);
	}//end guard()

	/**
	 * A table row as a bulk payload.
	 *
	 * @param string $uuid The row's uuid ('' for a new row).
	 * @param string $schema The row's schema.
	 *
	 * @return array<string, mixed>
	 */
	private function table(string $uuid = '', string $schema = '7'): array {
		$row = DecisionTableStoreTest::shippingTable();
		$row['@self'] = ['schema' => $schema, 'register' => '3'];
		if ($uuid !== '') {
			$row['@self']['uuid'] = $uuid;
		}

		return $row;
	}//end table()

	/**
	 * A valid table under a free key, and rows of other schemas, pass.
	 *
	 * @return void
	 */
	public function testAValidTableAndOtherSchemasPass(): void {
		$rows = [$this->table(), ['name' => 'x', '@self' => ['schema' => '8', 'register' => '3']]];

		$this->assertSame([], $this->guard()->refusals(objects: $rows, register: null, schema: null));
	}//end testAValidTableAndOtherSchemasPass()

	/**
	 * A key another stored table carries refuses the row, by its position in the batch.
	 *
	 * @return void
	 */
	public function testAKeyAnotherStoredTableCarriesRefusesTheRow(): void {
		$other = new ObjectEntity();
		$other->setUuid('u-2');
		$other->setObject(DecisionTableStoreTest::shippingTable());

		$refused = $this->guard([$other])->refusals(objects: [4 => $this->table()], register: null, schema: null);

		$this->assertSame([4], array_keys($refused));
		$this->assertStringContainsString('already used by decision table u-2', $refused[4]);
	}//end testAKeyAnotherStoredTableCarriesRefusesTheRow()

	/**
	 * Two rows of one batch that claim the same key cannot both be stored; the first stands, a re-send of the
	 * same row (same uuid) is no clash.
	 *
	 * @return void
	 */
	public function testTwoRowsOfOneBatchClaimingTheKeyAreRefusedButTheSameRowIsNot(): void {
		$rows = [$this->table(uuid: 'a'), $this->table(uuid: 'b'), $this->table(uuid: 'a')];

		$refused = $this->guard()->refusals(objects: $rows, register: null, schema: null);

		$this->assertSame([1], array_keys($refused));
		$this->assertStringContainsString('claimed by another row of the same save', $refused[1]);

		$unnamed = $this->guard()->refusals(objects: [$this->table(), $this->table()], register: null, schema: null);
		$this->assertSame([1], array_keys($unnamed), 'two new rows under one key clash');
	}//end testTwoRowsOfOneBatchClaimingTheKeyAreRefusedButTheSameRowIsNot()

	/**
	 * A table the engine cannot run is refused, and the batch's default schema and register are used when a row
	 * names none (also as entities).
	 *
	 * @return void
	 */
	public function testATableTheEngineCannotRunIsRefusedUnderTheBatchDefaults(): void {
		$bad = DecisionTableStoreTest::shippingTable();
		$bad['rules'][0]['inputEntries'] = [];
		$schema = new Schema();
		$schema->setId(7);
		$register = new Register();
		$register->setId(3);

		$refused = $this->guard()->refusals(objects: [$bad], register: $register, schema: $schema);

		$this->assertSame([0], array_keys($refused));
		$this->assertStringContainsString('The decision table cannot be saved', $refused[0]);
		$this->assertSame([], $this->guard()->refusals(objects: [DecisionTableStoreTest::shippingTable()], register: 0, schema: '0'), 'no schema means no decision table');
	}//end testATableTheEngineCannotRunIsRefusedUnderTheBatchDefaults()

	/**
	 * A bulk save refuses the decision-table rows through the real saveObjects entry point, reporting the row's position.
	 *
	 * @return void
	 */
	public function testSaveObjectsRefusesAClashingTableBeforeWriting(): void {
		$other = new ObjectEntity();
		$other->setUuid('u-2');
		$other->setObject(DecisionTableStoreTest::shippingTable());
		$mapper = $this->createMock(MagicMapper::class);
		$mapper->expects($this->never())->method('bulkUpsert');
		$service = new SaveObjects(
			$mapper,
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SaveObject::class),
			$this->createMock(IUserSession::class),
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class),
			null,
			null,
			null,
			null,
			null,
			$this->guard([$other])
		);

		$result = $service->saveObjects([3 => $this->table()]);

		$this->assertSame([], $result['saved']);
		$this->assertCount(1, $result['invalid']);
		$this->assertSame(3, $result['invalid'][0]['index']);
		$this->assertStringContainsString('already used by decision table u-2', $result['invalid'][0]['error']);
	}//end testSaveObjectsRefusesAClashingTableBeforeWriting()
}//end class
