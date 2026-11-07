<?php

/**
 * Golden outcomes of SurvivorshipController's access paths, pinned before a refactor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\SurvivorshipController;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the RBAC/tenant-scoped read, the write order and every status mapping.
 */
class SurvivorshipControllerA24GoldenTest extends TestCase {

	/**
	 * objects.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * schemas.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * resolver.
	 *
	 * @var SourceRecordResolverInterface&MockObject
	 */
	private SourceRecordResolverInterface&MockObject $resolver;

	/**
	 * Calls in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->resolver = $this->createMock(SourceRecordResolverInterface::class);
	}//end setUp()

	/**
	 * Build the controller over these request params.
	 *
	 * @param array<string, mixed> $params Request params.
	 *
	 * @return SurvivorshipController
	 */
	private function controller(array $params = []): SurvivorshipController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => (array_key_exists($key, $params) === true ? $params[$key] : $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('steward');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SurvivorshipController('openregister', $request, $this->objects, $this->schemas, $this->resolver, $session);
	}//end controller()

	/**
	 * An object with a payload.
	 *
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('m-1');
		$object->setRegister('reg');
		$object->setSchema('sch');
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * The read must be RBAC + tenant scoped; record the call.
	 *
	 * @param ObjectEntity|null|\Throwable $result What find() yields.
	 *
	 * @return void
	 */
	private function findYields(mixed $result): void {
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null, bool $_rbac = true, bool $_multitenancy = true) use ($result) {
				$this->calls[] = 'find:' . $id;
				$this->assertSame([[], false, null, null, true, true], [$_extend, $files, $register, $schema, $_rbac, $_multitenancy]);
				if ($result instanceof \Throwable) {
					throw $result;
				}

				return $result;
			}
		);
	}//end findYields()

	/**
	 * Schema config served for the object's schema.
	 *
	 * @param array<string, mixed>|null $config Configuration, or null to fail the lookup.
	 *
	 * @return void
	 */
	private function schemaConfig(?array $config): void {
		$this->schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true) use ($config): Schema {
				$this->assertSame(['sch', true, false], [$id, $_rbac, $_multitenancy]);
				if ($config === null) {
					throw new RuntimeException('no schema');
				}

				$schema = new Schema();
				$schema->setConfiguration($config);
				return $schema;
			}
		);
	}//end schemaConfig()

	/**
	 * Sources outcomes.
	 *
	 * @return void
	 */
	public function testSourcesOutcomes(): void {
		$this->findYields($this->object(['a' => 1]));
		$this->schemaConfig(['x-openregister-survivorship' => ['sourceLink' => ['mode' => 'reverseFk']]]);
		$this->resolver->expects($this->once())->method('resolveSources')
			->with(['id' => 'm-1', 'a' => 1], 'm-1', ['sourceLink' => ['mode' => 'reverseFk']], 'reg')
			->willReturn([['uuid' => 's-1']]);
		$r = $this->controller()->sources('m-1');
		$this->assertSame([200, ['sources' => [['uuid' => 's-1']]]], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Sources refusals.
	 *
	 * @return void
	 */
	public function testSourcesRefusals(): void {
		$this->findYields(new NotAuthorizedException('forbidden here'));
		$r = $this->controller()->sources('m-1');
		$this->assertSame([403, ['error' => 'forbidden here']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Sources missing and failing.
	 *
	 * @return void
	 */
	public function testSourcesMissingAndFailing(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('bad')));
		$r = $this->controller()->sources('m-1');
		$this->assertSame([404, ['error' => 'Object not found.']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller()->sources('m-1');
		$this->assertSame([404, ['error' => 'bad']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Override without attribute never reads.
	 *
	 * @return void
	 */
	public function testOverrideWithoutAttributeNeverReads(): void {
		$this->objects->expects($this->never())->method('find');
		$r = $this->controller()->override('m-1');
		$this->assertSame([400, ['error' => 'An "attribute" is required.']], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Override allowed writes in order.
	 *
	 * @return void
	 */
	public function testOverrideAllowedWritesInOrder(): void {
		$object = $this->object(['name' => 'Gold', 'ov' => ['other' => ['value' => 1]]]);
		$this->findYields($object);
		$this->schemaConfig(['x-openregister-survivorship' => ['overridesField' => 'ov']]);
		$this->resolver->method('resolveSources')->willReturnCallback(function () {
			$this->calls[] = 'resolve';
			return [];
		});
		$saved = $this->object(['golden' => 'yes']);
		$saved->setUuid('m-1');
		$this->objects->expects($this->once())->method('saveObject')->willReturnCallback(
			function ($obj, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use ($object, $saved) {
				$this->calls[] = 'save';
				$this->assertSame([$object, 'reg', 'sch', 'm-1'], [$obj, $register, $schema, $uuid]);
				$this->assertSame(
					[
						'id' => 'm-1',
						'name' => 'Gold',
						'ov' => [
							'other' => ['value' => 1],
							'name' => ['value' => 'Steward', 'overriddenBy' => 'steward', 'rationale' => '42'],
						],
					],
					$obj->getObject()
				);
				return $saved;
			}
		);
		$r = $this->controller(['attribute' => 'name', 'value' => 'Steward', 'rationale' => 42])->override('m-1');
		$this->assertSame([200, ['id' => 'm-1', 'golden' => 'yes']], [$r->getStatus(), $r->getData()]);
		$this->assertSame(['find:m-1', 'resolve', 'save'], $this->calls);
	}

	/**
	 * Override clear and default field.
	 *
	 * @return void
	 */
	public function testOverrideClearAndDefaultField(): void {
		$object = $this->object(['attributeOverrides' => ['name' => ['value' => 'x']]]);
		$this->findYields($object);
		$this->schemaConfig(['x-openregister-survivorship' => ['overridesField' => '']]);
		$this->objects->method('saveObject')->willReturnCallback(function ($obj) {
			$this->assertSame(['id' => 'm-1', 'attributeOverrides' => []], $obj->getObject());
			return $obj;
		});
		$r = $this->controller(['attribute' => 'name', 'value' => 'v', 'clear' => true])->override('m-1');
		$this->assertSame([200, ['id' => 'm-1', 'attributeOverrides' => []]], [$r->getStatus(), $r->getData()]);
	}

	/**
	 * Override non array map and missing schema.
	 *
	 * @return void
	 */
	public function testOverrideNonArrayMapAndMissingSchema(): void {
		$object = $this->object(['attributeOverrides' => 'junk']);
		$this->findYields($object);
		$this->schemaConfig(null);
		$this->resolver->expects($this->once())->method('resolveSources')->with($this->anything(), 'm-1', [], 'reg')->willReturn([]);
		$this->objects->method('saveObject')->willReturnArgument(0);
		$r = $this->controller(['attribute' => 'a', 'value' => 0])->override('m-1');
		$this->assertSame(
			['id' => 'm-1', 'attributeOverrides' => ['a' => ['value' => 0, 'overriddenBy' => 'steward', 'rationale' => null]]],
			$r->getData()
		);
	}

	/**
	 * Override refused write is403 and other failures404.
	 *
	 * @return void
	 */
	public function testOverrideRefusedWriteIs403AndOtherFailures404(): void {
		$object = $this->object([]);
		$this->findYields($object);
		$this->schemaConfig([]);
		$this->objects->method('saveObject')->willReturnOnConsecutiveCalls(
			$this->throwException(new NotAuthorizedException('no write')),
			$this->throwException(new RuntimeException('rt')),
			$this->throwException(new \Error('err'))
		);
		$expected = [[403, ['error' => 'no write']], [404, ['error' => 'rt']], [404, ['error' => 'err']]];
		foreach ($expected as $want) {
			$r = $this->controller(['attribute' => 'a', 'value' => 'v'])->override('m-1');
			$this->assertSame($want, [$r->getStatus(), $r->getData()]);
		}
	}

	/**
	 * Override unreadable is not written.
	 *
	 * @return void
	 */
	public function testOverrideUnreadableIsNotWritten(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(null, $this->throwException(new NotAuthorizedException('hidden')));
		$this->objects->expects($this->never())->method('saveObject');
		$r = $this->controller(['attribute' => 'a'])->override('m-1');
		$this->assertSame([404, ['error' => 'Object not found.']], [$r->getStatus(), $r->getData()]);
		$r = $this->controller(['attribute' => 'a'])->override('m-1');
		$this->assertSame([403, ['error' => 'hidden']], [$r->getStatus(), $r->getData()]);
	}
}//end class
