<?php

/**
 * Golden test: how FlowLocator::resolveObject() loads a run's subject.
 *
 * The load runs with RBAC and multitenancy OFF (a trigger has no acting
 * user), so what it forwards and what it refuses is pinned here on the code as
 * it stood before the load moved behind an ObjectRef: an empty uuid, register
 * or schema loads nothing; the three values reach the object store unchanged
 * with both guard flags off; a failed load or a non-object answer is null.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Db\FlowTriggerMapper;
use OCA\OpenRegister\Db\FlowVersionMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowLocatorStores;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * What resolveObject() forwards to the object store, and what it answers.
 */
final class FlowLocatorSubjectGoldenTest extends TestCase {

	/**
	 * Every find() call, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $finds = [];

	/**
	 * A locator whose object store answers with `$answer` (or throws it).
	 *
	 * @param mixed $answer What find() returns, or a Throwable it throws.
	 *
	 * @return FlowLocator
	 */
	private function locator(mixed $answer): FlowLocator {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true) use ($answer): ?ObjectEntity {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		);

		return new FlowLocator(
			new FlowLocatorStores(
				$this->createMock(FlowMapper::class),
				$this->createMock(FlowTriggerMapper::class),
				$this->createMock(FlowVersionMapper::class)
			),
			$objects,
			new NullLogger()
		);
	}//end locator()

	/**
	 * A found object is returned, loaded with both guard flags off and the values unchanged.
	 *
	 * @return void
	 */
	public function testTheSubjectIsLoadedWithTheGuardsOffAndReturned(): void {
		$object = new ObjectEntity();

		$this->assertSame($object, $this->locator($object)->resolveObject(ref: new ObjectRef(register: 'reg-a', schema: 'schema-b', id: 'obj-1')));
		$this->assertSame(
			[['id' => 'obj-1', 'register' => 'reg-a', 'schema' => 'schema-b', '_rbac' => false, '_multitenancy' => false]],
			$this->finds
		);
	}//end testTheSubjectIsLoadedWithTheGuardsOffAndReturned()

	/**
	 * An empty uuid, register or schema loads nothing at all.
	 *
	 * @return void
	 */
	public function testAnEmptyPartLoadsNothing(): void {
		$locator = $this->locator(new ObjectEntity());

		$this->assertNull($locator->resolveObject(ref: new ObjectRef(register: 'reg-a', schema: 'schema-b', id: '')));
		$this->assertNull($locator->resolveObject(ref: new ObjectRef(register: '', schema: 'schema-b', id: 'obj-1')));
		$this->assertNull($locator->resolveObject(ref: new ObjectRef(register: 'reg-a', schema: '', id: 'obj-1')));
		$this->assertSame([], $this->finds);
	}//end testAnEmptyPartLoadsNothing()

	/**
	 * A failed load and a missing object both answer null.
	 *
	 * @return void
	 */
	public function testAFailedOrEmptyLoadIsNull(): void {
		$this->assertNull($this->locator(new RuntimeException('gone'))->resolveObject(ref: new ObjectRef(register: 'reg-a', schema: 'schema-b', id: 'obj-1')));
		$this->assertNull($this->locator(null)->resolveObject(ref: new ObjectRef(register: 'reg-a', schema: 'schema-b', id: 'obj-2')));
		$this->assertCount(2, $this->finds);
	}//end testAFailedOrEmptyLoadIsNull()
}//end class
