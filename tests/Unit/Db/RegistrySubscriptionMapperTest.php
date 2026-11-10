<?php

/**
 * RegistrySubscriptionMapper reads, saves and deletes base-registry subscriptions.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\RegistrySubscription;
use OCA\OpenRegister\Db\RegistrySubscriptionMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\RegistrySubscriptionMapper
 * @uses   \OCA\OpenRegister\Db\RegistrySubscription
 */
class RegistrySubscriptionMapperTest extends TestCase {
	use RecordingQueryBuilderTrait;

	/**
	 * A mapper over the recording query builder.
	 *
	 * @return RegistrySubscriptionMapper
	 */
	private function mapper(): RegistrySubscriptionMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		return new RegistrySubscriptionMapper($db);
	}//end mapper()

	/**
	 * The whole query log as one string.
	 *
	 * @return string
	 */
	private function log(): string {
		return implode("\n", $this->queryLog);
	}//end log()

	/**
	 * findForObject returns the object's subscription.
	 *
	 * @return void
	 */
	public function testFindForObjectReturnsTheRow(): void {
		$this->queuedRows = [[['id' => 4, 'object_uuid' => 'o-1', 'registry' => 'brp', 'state' => 'active']]];

		$row = $this->mapper()->findForObject('o-1');

		$this->assertSame('brp', $row?->getRegistry());
		$this->assertSame('active', $row?->getState());
		$this->assertStringContainsString('eq(object_uuid, :p1', $this->log());
		$this->assertStringContainsString('setMaxResults(1)', $this->log());
	}//end testFindForObjectReturnsTheRow()

	/**
	 * findForObject returns null for an object without a subscription.
	 *
	 * @return void
	 */
	public function testFindForObjectReturnsNullWithoutRow(): void {
		$this->queuedRows = [[]];

		$this->assertNull($this->mapper()->findForObject('o-1'));
	}//end testFindForObjectReturnsNullWithoutRow()

	/**
	 * findForObjects keys the rows by object uuid.
	 *
	 * @return void
	 */
	public function testFindForObjectsKeysByObjectUuid(): void {
		$this->queuedRows = [[
			['id' => 1, 'object_uuid' => 'o-1', 'registry' => 'brp'],
			['id' => 2, 'object_uuid' => 'o-2', 'registry' => 'kvk'],
		]];

		$rows = $this->mapper()->findForObjects(['o-1', 'o-2', 'o-3']);

		$this->assertSame(['o-1', 'o-2'], array_keys($rows));
		$this->assertSame('kvk', $rows['o-2']->getRegistry());
		$this->assertStringContainsString('in(object_uuid', $this->log());
	}//end testFindForObjectsKeysByObjectUuid()

	/**
	 * findForObjects of nothing queries nothing.
	 *
	 * @return void
	 */
	public function testFindForObjectsOfNothingDoesNotQuery(): void {
		$this->assertSame([], $this->mapper()->findForObjects([]));
		$this->assertSame([], $this->queryLog);
	}//end testFindForObjectsOfNothingDoesNotQuery()

	/**
	 * findActiveByIdentity matches registry, identity and the active state only.
	 *
	 * @return void
	 */
	public function testFindActiveByIdentityMatchesActiveOnly(): void {
		$this->queuedRows = [[['id' => 1, 'object_uuid' => 'o-1']]];

		$rows = $this->mapper()->findActiveByIdentity('brp', '999990019');

		$this->assertCount(1, $rows);
		$log = $this->log();
		$this->assertStringContainsString('eq(registry, :p1', $log);
		$this->assertStringContainsString('eq(identity_value, :p2', $log);
		$this->assertStringContainsString('param :p3 = active, 2', $log);
	}//end testFindActiveByIdentityMatchesActiveOnly()

	/**
	 * save inserts a row without an id.
	 *
	 * @return void
	 */
	public function testSaveInsertsANewRow(): void {
		$row = new RegistrySubscription();
		$row->setObjectUuid('o-1');

		$this->mapper()->save($row);

		$log = $this->log();
		$this->assertStringContainsString('insert(openregister_registry_subs', $log);
		$this->assertStringNotContainsString('update(', $log);
	}//end testSaveInsertsANewRow()

	/**
	 * save updates a row with an id.
	 *
	 * @return void
	 */
	public function testSaveUpdatesAStoredRow(): void {
		$row = new RegistrySubscription();
		$row->setId(5);
		$row->setState('ended');

		$this->mapper()->save($row);

		$log = $this->log();
		$this->assertStringContainsString('update(openregister_registry_subs', $log);
		$this->assertStringNotContainsString('insert(', $log);
	}//end testSaveUpdatesAStoredRow()

	/**
	 * deleteForObject deletes the stored row by its id.
	 *
	 * @return void
	 */
	public function testDeleteForObjectDeletesTheRow(): void {
		$this->queuedRows = [[['id' => 8, 'object_uuid' => 'o-1']]];

		$this->mapper()->deleteForObject('o-1');

		$log = $this->log();
		$this->assertStringContainsString('#2 delete(openregister_registry_subs', $log);
		$this->assertStringContainsString('param :p2 = 8, 1', $log);
	}//end testDeleteForObjectDeletesTheRow()

	/**
	 * deleteForObject without a stored row deletes nothing.
	 *
	 * @return void
	 */
	public function testDeleteForObjectWithoutRowDeletesNothing(): void {
		$this->queuedRows = [[]];

		$this->mapper()->deleteForObject('o-1');

		$this->assertStringNotContainsString('delete(', $this->log());
	}//end testDeleteForObjectWithoutRowDeletesNothing()
}//end class
