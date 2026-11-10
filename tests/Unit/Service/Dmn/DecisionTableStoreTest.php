<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Dmn;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableStore;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * Named decision tables, read from the register and run by the real engine.
 *
 * Neutral fixture: a shipping-rate table, parcel weight band to carrier.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
class DecisionTableStoreTest extends TestCase {

	/**
	 * A stored table row.
	 *
	 * @param string $key The table key.
	 * @param string $carrier What the heavy rule answers.
	 *
	 * @return array<string, mixed>
	 */
	public static function shippingTable(string $key = 'parcel-carrier', string $carrier = 'freight'): array {
		return [
			'key' => $key,
			'name' => 'Parcel carrier',
			'hitPolicy' => 'FIRST',
			'inputs' => [['name' => 'weight', 'type' => 'number']],
			'outputs' => [['name' => 'carrier', 'type' => 'string']],
			'rules' => [
				['id' => 'light', 'inputEntries' => ['< 2'], 'outputEntries' => ['letterbox']],
				['id' => 'heavy', 'inputEntries' => ['>= 2'], 'outputEntries' => [$carrier]],
			],
		];
	}//end shippingTable()

	/**
	 * A store over the given rows.
	 *
	 * @param array<int, array{0: string, 1: array<string, mixed>}> $rows Uuid and data per row.
	 * @param bool $unreadable Whether the register read fails.
	 *
	 * @return DecisionTableStore
	 */
	private function store(array $rows, bool $unreadable = false): DecisionTableStore {
		$objects = $this->createMock(ObjectService::class);
		$method  = $objects->method('searchObjectsBySlug')->with(DecisionTableStore::REGISTER, DecisionTableStore::SCHEMA);
		if ($unreadable === true) {
			$method->willThrowException(new RuntimeException('register missing'));
		} else {
			$entities = [];
			foreach ($rows as [$uuid, $data]) {
				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($data);
				$entities[] = $entity;
			}

			$method->willReturn($entities);
		}

		return new DecisionTableStore($objects, new DecisionTableValidator(), new DecisionTableEvaluator());
	}//end store()

	public function testATableIsFoundByKey(): void {
		$store = $this->store([['u-1', self::shippingTable()]]);
		$this->assertSame('Parcel carrier', $store->find('parcel-carrier')['name']);
	}

	/**
	 * Two rows with one key (written around the save guard) are two tables in no fixed order: the lookup refuses
	 * rather than answering with whichever the register returned first.
	 *
	 * @return void
	 */
	public function testTwoTablesWithOneKeyAreRefusedNotPickedFrom(): void {
		$store = $this->store([['u-1', self::shippingTable()], ['u-2', self::shippingTable(carrier: 'air')]]);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('More than one decision table has the key "parcel-carrier" (u-1, u-2)');
		$store->find('parcel-carrier');
	}//end testTwoTablesWithOneKeyAreRefusedNotPickedFrom()

	public function testATableIsFoundByUuid(): void {
		$store = $this->store([['u-1', self::shippingTable()]]);
		$this->assertSame('parcel-carrier', $store->find('u-1')['key']);
	}

	public function testAKeyWinsOverAUuidThatHappensToMatch(): void {
		$store = $this->store([['parcel-carrier', self::shippingTable('other', 'van')], ['u-2', self::shippingTable()]]);
		$this->assertSame('freight', $store->evaluate('parcel-carrier', ['weight' => 5])['outputs']['carrier']);
	}

	public function testEvaluateRunsTheStoredTable(): void {
		$store  = $this->store([['u-1', self::shippingTable()]]);
		$result = $store->evaluate('parcel-carrier', ['weight' => 1.5]);
		$this->assertSame(['carrier' => 'letterbox'], $result['outputs']);
		$this->assertSame(['light'], $result['matchedRuleIds']);
		$this->assertSame('FIRST', $result['hitPolicy']);
		$this->assertSame('parcel-carrier', $result['tableKey']);
	}

	public function testAnUnknownReferenceIsRefusedNotAnsweredEmpty(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('No decision table has the key or id "nope"');
		$this->store([['u-1', self::shippingTable()]])->find('nope');
	}

	public function testAnEmptyReferenceIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->store([])->find('  ');
	}

	public function testAStoredTableTheEngineCannotRunIsRefusedOnRead(): void {
		$broken = self::shippingTable();
		$broken['hitPolicy'] = 'OUTPUT ORDER';
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('cannot be run');
		$this->store([['u-1', $broken]])->find('parcel-carrier');
	}

	public function testAnUnreadableRegisterIsAFaultNotANoMatch(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessage('could not be read');
		$this->store([], unreadable: true)->find('parcel-carrier');
	}
}
