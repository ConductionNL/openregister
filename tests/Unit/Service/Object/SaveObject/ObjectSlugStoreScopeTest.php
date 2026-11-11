<?php

/**
 * Unit tests for slug lookups in a scope without a register.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper\MagicSlugStoreInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugScope;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Without a register there is no table to ask: no holder and no state, and the store is never queried.
 * With one, the store is asked for that register and schema.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore
 */
class ObjectSlugStoreScopeTest extends TestCase {

	/**
	 * The magic slug store, mocked.
	 *
	 * @var MagicSlugStoreInterface&MockObject
	 */
	private MagicSlugStoreInterface&MockObject $magic;

	/**
	 * The store under test.
	 *
	 * @return ObjectSlugStore
	 */
	private function store(): ObjectSlugStore {
		return new ObjectSlugStore(
			$this->magic,
			$this->createMock(RegisterMapperInterface::class),
			$this->createMock(ObjectSlugLog::class)
		);

	}//end store()

	/**
	 * Build the shared mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->magic = $this->createMock(MagicSlugStoreInterface::class);

	}//end setUp()

	/**
	 * A register-less scope has no holder and an empty state, without a query.
	 *
	 * @return void
	 */
	public function testARegisterlessScopeAsksNothing(): void {
		$this->magic->expects($this->never())->method('findSlugHolders');
		$this->magic->expects($this->never())->method('findSlugState');
		$scope = new ObjectSlugScope(null, new Schema());

		$this->assertNull($this->store()->holderOf(want: new \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugWant(slug: 'jan', scope: $scope, objectUuid: 'u-1', path: 'save'), slug: 'jan'));
		$this->assertSame(['holders' => [], 'stored' => []], $this->store()->state(scope: $scope, claims: new \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClaims(slugs: ['jan'], uuids: ['u-1'])));

	}//end testARegisterlessScopeAsksNothing()

	/**
	 * A scope with a register asks the store and answers its first holder.
	 *
	 * @return void
	 */
	public function testAScopedLookupAsksTheStore(): void {
		$register = new Register();
		$schema   = new Schema();
		$this->magic->expects($this->once())
			->method('findSlugHolders')
			->with(new RegisterSchema($register, $schema), 'jan', 'u-1')
			->willReturn(['u-9', 'u-8']);
		$this->magic->expects($this->once())
			->method('findSlugState')
			->with(new RegisterSchema($register, $schema), ['jan'], ['u-1'])
			->willReturn(['holders' => ['jan' => 'u-9'], 'stored' => []]);
		$scope = new ObjectSlugScope($register, $schema);

		$this->assertSame('u-9', $this->store()->holderOf(want: new \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugWant(slug: 'jan', scope: $scope, objectUuid: 'u-1', path: 'save'), slug: 'jan'));
		$this->assertSame(['jan' => 'u-9'], $this->store()->state(scope: $scope, claims: new \OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugClaims(slugs: ['jan'], uuids: ['u-1']))['holders']);

	}//end testAScopedLookupAsksTheStore()
}//end class
