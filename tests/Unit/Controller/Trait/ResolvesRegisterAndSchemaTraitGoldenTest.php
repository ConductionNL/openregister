<?php

/**
 * ResolvesRegisterAndSchemaTrait — golden test of every resolution outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Trait
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Trait;

use OCA\OpenRegister\Controller\Trait\ResolvesRegisterAndSchemaTrait;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Pins the resolved ids and entities, the clear-first order, and which
 * not-found exception each failure becomes.
 */
class ResolvesRegisterAndSchemaTraitGoldenTest extends TestCase {
	/**
	 * Calls made on the object service, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * A trait consumer exposing the resolution.
	 *
	 * @return object The consumer.
	 */
	private function consumer(): object {
		return new class {
			use ResolvesRegisterAndSchemaTrait;

			public function resolve(string $register, string $schema, ObjectOperationsInterface $service): array {
				return $this->resolveRegisterAndSchema(register: $register, schema: $schema, objectService: $service);
			}//end resolve()
		};
	}//end consumer()

	/**
	 * An object service whose setRegister() moves the current register from $before to $after, then throws $registerFailure if given.
	 *
	 * @param Register|null  $before          Current register before setRegister().
	 * @param Register|null  $after           Current register after setRegister().
	 * @param Throwable|null $registerFailure Thrown by setRegister().
	 * @param Throwable|null $schemaFailure   Thrown by setSchema().
	 *
	 * @return ObjectOperationsInterface&MockObject The service.
	 */
	private function service(?Register $before, ?Register $after, ?Throwable $registerFailure = null, ?Throwable $schemaFailure = null): ObjectOperationsInterface {
		$current = $before;
		$service = $this->createMock(ObjectOperationsInterface::class);
		$service->method('clearCurrents')->willReturnCallback(function (): void {
			$this->calls[] = 'clearCurrents';
		});
		$service->method('getCurrentRegisterEntity')->willReturnCallback(function () use (&$current): ?Register {
			$this->calls[] = 'getCurrentRegisterEntity';
			return $current;
		});
		$service->method('setRegister')->willReturnCallback(function () use (&$current, $after, $registerFailure, $service) {
			$this->calls[] = 'setRegister';
			$current = $after;
			if ($registerFailure !== null) {
				throw $registerFailure;
			}

			return $service;
		});
		$service->method('setSchema')->willReturnCallback(function () use ($schemaFailure, $service) {
			$this->calls[] = 'setSchema';
			if ($schemaFailure !== null) {
				throw $schemaFailure;
			}

			return $service;
		});
		$service->method('getRegister')->willReturn(19);
		$service->method('getSchema')->willReturn(9476);
		return $service;
	}//end service()

	/**
	 * Run the resolution and return the thrown exception.
	 *
	 * @param ObjectOperationsInterface $service The service.
	 *
	 * @return Throwable The exception.
	 */
	private function failure(ObjectOperationsInterface $service): Throwable {
		try {
			$this->consumer()->resolve('reg-slug', 'schema-slug', $service);
		} catch (Throwable $e) {
			return $e;
		}

		$this->fail('the resolution did not throw');
	}//end failure()

	public function testResolvesIdsAndEntitiesAfterClearingFirst(): void {
		$register = new Register();
		$schema = new Schema();
		$service = $this->service(null, $register);
		$service->method('getCurrentSchemaEntity')->willReturn($schema);

		$result = $this->consumer()->resolve('reg-slug', 'schema-slug', $service);

		$this->assertSame(['register' => 19, 'schema' => 9476, 'registerEntity' => $register, 'schemaEntity' => $schema], $result);
		$this->assertSame(['clearCurrents', 'getCurrentRegisterEntity', 'setRegister', 'setSchema', 'getCurrentRegisterEntity'], $this->calls);
	}//end testResolvesIdsAndEntitiesAfterClearingFirst()

	public function testRegisterThatNeverResolvesIsARegisterFailure(): void {
		$previous = new DoesNotExistException('no row');

		$e = $this->failure($this->service(null, null, $previous));

		$this->assertInstanceOf(RegisterNotFoundException::class, $e);
		$this->assertSame("Register not found: 'reg-slug'", $e->getMessage());
		$this->assertSame(404, $e->getCode());
		$this->assertSame($previous, $e->getPrevious());
		$this->assertSame('clearCurrents', $this->calls[0]);
	}//end testRegisterThatNeverResolvesIsARegisterFailure()

	public function testUnchangedRegisterEntityIsARegisterFailure(): void {
		$register = new Register();

		$e = $this->failure($this->service($register, $register, new DoesNotExistException('no row')));

		$this->assertInstanceOf(RegisterNotFoundException::class, $e);
	}//end testUnchangedRegisterEntityIsARegisterFailure()

	public function testRegisterThatResolvedBeforeTheThrowIsASchemaFailure(): void {
		$previous = new DoesNotExistException('pending ref');

		$e = $this->failure($this->service(null, new Register(), $previous));

		$this->assertInstanceOf(SchemaNotFoundException::class, $e);
		$this->assertSame("Schema not found: 'schema-slug'", $e->getMessage());
		$this->assertSame(404, $e->getCode());
		$this->assertSame($previous, $e->getPrevious());
	}//end testRegisterThatResolvedBeforeTheThrowIsASchemaFailure()

	public function testChangedRegisterEntityIsASchemaFailure(): void {
		$e = $this->failure($this->service(new Register(), new Register(), new DoesNotExistException('pending ref')));

		$this->assertInstanceOf(SchemaNotFoundException::class, $e);
	}//end testChangedRegisterEntityIsASchemaFailure()

	public function testSchemaThatDoesNotResolveIsASchemaFailure(): void {
		$previous = new DoesNotExistException('no schema');

		$e = $this->failure($this->service(null, new Register(), null, $previous));

		$this->assertInstanceOf(SchemaNotFoundException::class, $e);
		$this->assertSame("Schema not found: 'schema-slug'", $e->getMessage());
		$this->assertSame($previous, $e->getPrevious());
	}//end testSchemaThatDoesNotResolveIsASchemaFailure()

	public function testOtherFailuresPassThroughUntouched(): void {
		$boom = new RuntimeException('boom');

		$this->assertSame($boom, $this->failure($this->service(null, null, $boom)));
		$this->assertSame($boom, $this->failure($this->service(null, new Register(), null, $boom)));
	}//end testOtherFailuresPassThroughUntouched()
}//end class
