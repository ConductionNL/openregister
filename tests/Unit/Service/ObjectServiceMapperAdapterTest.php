<?php

declare(strict_types=1);

/**
 * ObjectServiceMapperAdapter Unit Tests.
 *
 * Verifies that the adapter forwards its bound `(register, schema)` to the
 * underlying ObjectService — in particular the delete() path that previously
 * dropped the scope and could silently delete a UUID from a foreign magic
 * table (#1638).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-object-delete-api/tasks.md#5
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\ObjectServiceMapperAdapter;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the delete() forwarding behaviour on ObjectServiceMapperAdapter.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ObjectServiceMapperAdapterTest extends TestCase {

	/**
	 * Adapter forwards its bound register + schema to ObjectService::deleteObject().
	 *
	 * @return void
	 */
	public function testDeleteForwardsBoundScopeToObjectService(): void {
		/** @var ObjectService&MockObject $service */
		$service = $this->createMock(ObjectService::class);

		$service
			->expects($this->once())
			->method('deleteObject')
			->with(
				$this->equalTo('abc-123'),
				$this->equalTo(1),
				$this->equalTo(10)
			)
			->willReturn(true);

		$adapter = new ObjectServiceMapperAdapter(
			objectService: $service,
			register: 1,
			schema: 10
		);

		$this->assertTrue($adapter->delete(['id' => 'abc-123']));

	}//end testDeleteForwardsBoundScopeToObjectService()

	/**
	 * Adapter without a bound scope forwards null + null (legacy unscoped path).
	 *
	 * @return void
	 */
	public function testDeleteWithoutScopeForwardsNulls(): void {
		/** @var ObjectService&MockObject $service */
		$service = $this->createMock(ObjectService::class);

		$service
			->expects($this->once())
			->method('deleteObject')
			->with(
				$this->equalTo('abc-123'),
				$this->isNull(),
				$this->isNull()
			)
			->willReturn(true);

		$adapter = new ObjectServiceMapperAdapter(
			objectService: $service,
			register: null,
			schema: null
		);

		$this->assertTrue($adapter->delete(['id' => 'abc-123']));

	}//end testDeleteWithoutScopeForwardsNulls()

	/**
	 * Adapter propagates DoesNotExistException raised by the scoped lookup.
	 *
	 * When the adapter is bound to a register+schema and the UUID lives in a
	 * different magic table, ObjectService::deleteObject() raises
	 * DoesNotExistException. The adapter MUST propagate it instead of
	 * swallowing it — callers can then distinguish "scope mismatch" from
	 * "operation succeeded".
	 *
	 * @return void
	 */
	public function testDeletePropagatesDoesNotExistExceptionFromService(): void {
		/** @var ObjectService&MockObject $service */
		$service = $this->createMock(ObjectService::class);

		$service
			->method('deleteObject')
			->willThrowException(new DoesNotExistException('not in scope'));

		$adapter = new ObjectServiceMapperAdapter(
			objectService: $service,
			register: 1,
			schema: 10
		);

		$this->expectException(DoesNotExistException::class);

		$adapter->delete(['id' => 'abc-123']);

	}//end testDeletePropagatesDoesNotExistExceptionFromService()

	/**
	 * Adapter rejects delete() with no id.
	 *
	 * @return void
	 */
	public function testDeleteWithoutIdThrowsValidationException(): void {
		/** @var ObjectService&MockObject $service */
		$service = $this->createMock(ObjectService::class);

		$service
			->expects($this->never())
			->method('deleteObject');

		$adapter = new ObjectServiceMapperAdapter(
			objectService: $service,
			register: 1,
			schema: 10
		);

		$this->expectException(ValidationException::class);

		$adapter->delete([]);

	}//end testDeleteWithoutIdThrowsValidationException()

}//end class
