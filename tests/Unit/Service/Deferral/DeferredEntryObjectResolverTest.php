<?php

declare(strict_types=1);

namespace Unit\Service\Deferral;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stale-safe re-fetch: scoped lookup, gone → null, soft-deleted → null.
 */
class DeferredEntryObjectResolverTest extends TestCase {
	private ObjectService&MockObject $objectService;
	private DeferredEntryObjectResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->resolver = new DeferredEntryObjectResolver(
			objectService: $this->objectService,
			logger: $this->createMock(LoggerInterface::class),
		);
	}

	public function testResolvesWithScopedRawLookup(): void {
		$object = new ObjectEntity();
		$object->setUuid('u1');

		$this->objectService->expects($this->once())->method('find')
			->willReturnCallback(
				function (
					int|string $id,
					?array $_extend = [],
					bool $files = false,
					$register = null,
					$schema = null,
					bool $_rbac = true,
					bool $_multitenancy = true,
					bool $_render = true,
				) use ($object) {
					$this->assertSame('u1', $id);
					// Register + schema forwarded → one magic table, no cross-table scan.
					$this->assertSame('reg', $register);
					$this->assertSame('sch', $schema);
					// Raw entity fetch: the inline listener received the entity directly.
					$this->assertFalse($_rbac);
					$this->assertFalse($_multitenancy);
					$this->assertFalse($_render);
					return $object;
				}
			);

		$resolved = $this->resolver->resolve(entry: ['uuid' => 'u1', 'register' => 'reg', 'schema' => 'sch']);

		$this->assertSame($object, $resolved);
	}

	public function testGoneObjectResolvesToNull(): void {
		$this->objectService->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));

		$this->assertNull($this->resolver->resolve(entry: ['uuid' => 'u1', 'register' => 'r', 'schema' => 's']));
	}

	public function testSoftDeletedObjectResolvesToNull(): void {
		$object = new ObjectEntity();
		$object->setUuid('u1');
		$object->setDeleted(['deleted' => '2026-07-15T00:00:00+00:00', 'deletedBy' => 'alice']);
		$this->objectService->method('find')->willReturn($object);

		$this->assertNull($this->resolver->resolve(entry: ['uuid' => 'u1', 'register' => 'r', 'schema' => 's']));
	}

	public function testMalformedEntrySkipsLookupEntirely(): void {
		$this->objectService->expects($this->never())->method('find');

		$this->assertNull($this->resolver->resolve(entry: ['register' => 'r', 'schema' => 's']));
		$this->assertNull($this->resolver->resolve(entry: ['uuid' => '']));
	}
}
