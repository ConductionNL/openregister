<?php

/**
 * Characterisation of PushSubscriptionMapper::store (upsert by owner + endpoint).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Db;

use OCA\OpenRegister\Notifications\Db\PushSubscription;
use OCA\OpenRegister\Notifications\Db\PushSubscriptionMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins both branches of store(): a new endpoint inserts an owned row, a known
 * endpoint refreshes the keys of the existing row.
 */
class PushSubscriptionMapperStoreTest extends TestCase {

	/**
	 * Build the mapper with the database-touching methods stubbed.
	 *
	 * @return PushSubscriptionMapper&MockObject
	 */
	private function mapper(): PushSubscriptionMapper&MockObject {
		return $this->getMockBuilder(PushSubscriptionMapper::class)
			->setConstructorArgs([$this->createMock(IDBConnection::class)])
			->onlyMethods(['findByUserAndEndpoint', 'insert', 'update'])
			->getMock();
	}//end mapper()

	/**
	 * A new endpoint is inserted with the owner, keys, user agent and a creation time.
	 *
	 * @return void
	 */
	public function testStoreInsertsANewOwnedSubscription(): void {
		$mapper = $this->mapper();
		$mapper->expects($this->once())->method('findByUserAndEndpoint')
			->with('alice', 'https://push.example/e')->willReturn(null);
		$mapper->expects($this->never())->method('update');
		$mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$result = $mapper->store('alice', 'https://push.example/e', 'key', 'secret', 'UA');

		$this->assertSame('alice', $result->getUserId());
		$this->assertSame('https://push.example/e', $result->getEndpoint());
		$this->assertSame('key', $result->getP256dh());
		$this->assertSame('secret', $result->getAuth());
		$this->assertSame('UA', $result->getUserAgent());
		$this->assertNotNull($result->getCreatedAt());
	}//end testStoreInsertsANewOwnedSubscription()

	/**
	 * A known endpoint refreshes keys and user agent on the existing row, keeping owner and creation time.
	 *
	 * @return void
	 */
	public function testStoreRefreshesAnExistingSubscription(): void {
		$existing = new PushSubscription();
		$existing->setId(7);
		$existing->setUserId('alice');
		$existing->setEndpoint('https://push.example/e');
		$existing->setP256dh('old-key');
		$existing->setAuth('old-secret');
		$existing->setUserAgent('old-UA');

		$mapper = $this->mapper();
		$mapper->expects($this->once())->method('findByUserAndEndpoint')
			->with('alice', 'https://push.example/e')->willReturn($existing);
		$mapper->expects($this->never())->method('insert');
		$mapper->expects($this->once())->method('update')->with($existing)->willReturnArgument(0);

		$result = $mapper->store('alice', 'https://push.example/e', 'key', 'secret', 'UA');

		$this->assertSame(7, $result->getId());
		$this->assertSame('alice', $result->getUserId());
		$this->assertSame('key', $result->getP256dh());
		$this->assertSame('secret', $result->getAuth());
		$this->assertSame('UA', $result->getUserAgent());
		$this->assertNull($result->getCreatedAt());
	}//end testStoreRefreshesAnExistingSubscription()
}//end class
