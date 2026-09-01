<?php

declare(strict_types=1);

namespace Unit\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Quality\DuplicateDetectionService;
use OCA\OpenRegister\Service\Quality\SimilarityCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DuplicateDetectionServiceTest extends TestCase {
	/** @var ObjectService&MockObject */
	private $objectService;

	/** @var SchemaMapper&MockObject */
	private $schemaMapper;

	private $registerMapper;

	private DuplicateDetectionService $service;

	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->service = new DuplicateDetectionService(
			$this->objectService,
			$this->schemaMapper,
			$this->registerMapper,
			new SimilarityCalculator(),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Stub the register-scoped resolution path the annotation lookup now takes.
	 *
	 * The annotation is no longer read via a GLOBAL SchemaMapper::find(): the
	 * register named in the route is the boundary, so the schema ref is matched
	 * only among the ids that register carries (SchemaMapper::findInIds()).
	 *
	 * @param mixed $schema The schema the scoped lookup should resolve to.
	 *
	 * @return void
	 */
	private function stubScopedSchema($schema): void {
		$register = new Register();
		$register->setId(1);
		$register->setSlug('reg');
		$register->setSchemas([1]);

		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('findInIds')->willReturn($schema);
	}//end stubScopedSchema()

	/**
	 * Build an ObjectEntity carrying the given uuid + payload.
	 *
	 * @param string $uuid Object uuid.
	 * @param array<string, mixed> $payload Object data.
	 *
	 * @return ObjectEntity
	 */
	private function makeObject(string $uuid, array $payload): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($payload);

		return $object;
	}

	public function testFindsNearDuplicatePair(): void {
		$a = $this->makeObject('a', ['name' => 'Acme BV', 'email' => 'info@acme.test']);
		$b = $this->makeObject('b', ['name' => 'ACME  bv', 'email' => 'info@acme.test']);
		$c = $this->makeObject('c', ['name' => 'Globex', 'email' => 'hi@globex.test']);

		$this->objectService->method('findAll')->willReturn([$a, $b, $c]);

		$rules = [
			['field' => 'email', 'method' => 'exact', 'weight' => 0.5],
			['field' => 'name', 'method' => 'normalized', 'weight' => 0.5],
		];

		$pairs = $this->service->findDuplicates(1, 1, $rules, 0.85);

		$this->assertCount(1, $pairs);
		$this->assertSame(1.0, $pairs[0]['score']);
		$matched = [$pairs[0]['objectA'], $pairs[0]['objectB']];
		sort($matched);
		$this->assertSame(['a', 'b'], $matched);
		$this->assertContains('email', $pairs[0]['matchedOn']);
		$this->assertContains('name', $pairs[0]['matchedOn']);
	}

	public function testBelowThresholdReturnsEmpty(): void {
		$a = $this->makeObject('a', ['name' => 'Acme', 'email' => 'a@x.test']);
		$b = $this->makeObject('b', ['name' => 'Globex', 'email' => 'b@y.test']);

		$this->objectService->method('findAll')->willReturn([$a, $b]);

		$rules = [
			['field' => 'email', 'method' => 'exact'],
			['field' => 'name', 'method' => 'normalized'],
		];

		$this->assertSame([], $this->service->findDuplicates(1, 1, $rules, 0.85));
	}

	public function testEmptyWhenFewerThanTwoObjects(): void {
		$this->objectService->method('findAll')->willReturn([$this->makeObject('a', ['name' => 'x'])]);
		$rules = [['field' => 'name', 'method' => 'exact']];
		$this->assertSame([], $this->service->findDuplicates(1, 1, $rules, 0.85));
	}

	public function testRulesFromAnnotationWhenNoneSupplied(): void {
		$a = $this->makeObject('a', ['name' => 'Acme', 'email' => 'same@x.test']);
		$b = $this->makeObject('b', ['name' => 'acme', 'email' => 'same@x.test']);

		$this->objectService->method('findAll')->willReturn([$a, $b]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn([
			'x-openregister-dedup' => [
				'matchRules' => [
					['field' => 'email', 'method' => 'exact', 'weight' => 1],
				],
				'threshold' => 0.9,
			],
		]);
		$this->stubScopedSchema($schema);

		// No caller rules — service must fall back to annotation rules.
		$pairs = $this->service->findDuplicates(1, 1, null);

		$this->assertCount(1, $pairs);
		$this->assertSame(1.0, $pairs[0]['score']);
	}

	public function testBlockingKeyPartitionsCandidates(): void {
		// Same name, but different blocking key => never compared.
		$a = $this->makeObject('a', ['name' => 'Acme', 'postalCode' => '1011']);
		$b = $this->makeObject('b', ['name' => 'Acme', 'postalCode' => '9999']);

		$this->objectService->method('findAll')->willReturn([$a, $b]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn([
			'x-openregister-dedup' => [
				'blockingKeys' => ['postalCode'],
				'matchRules' => [['field' => 'name', 'method' => 'exact', 'weight' => 1]],
				'threshold' => 0.85,
			],
		]);
		$this->stubScopedSchema($schema);

		$this->assertSame([], $this->service->findDuplicates(1, 1, null));
	}

	public function testNoRulesAnywhereReturnsEmpty(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn([]);
		$this->stubScopedSchema($schema);

		$this->assertSame([], $this->service->findDuplicates(1, 1, null));
	}

	public function testNestedDotPathMatchRuleFindsDuplicate(): void {
		// Nested under `goldenRecord`; top-level `email` differs (or is absent)
		// so a pass would only find the match by resolving the nested path.
		$a = $this->makeObject('a', ['goldenRecord' => ['email' => 'same@x.test', 'name' => 'Acme BV']]);
		$b = $this->makeObject(
			'b',
			[
				'email' => 'different@x.test',
				'goldenRecord' => ['email' => 'same@x.test', 'name' => 'ACME  bv'],
			]
		);

		$this->objectService->method('findAll')->willReturn([$a, $b]);

		$rules = [
			['field' => 'goldenRecord.email', 'method' => 'exact', 'weight' => 0.5],
			['field' => 'goldenRecord.name', 'method' => 'normalized', 'weight' => 0.5],
		];

		$pairs = $this->service->findDuplicates(1, 1, $rules, 0.85);

		$this->assertCount(1, $pairs);
		$this->assertSame(1.0, $pairs[0]['score']);
		$this->assertContains('goldenRecord.email', $pairs[0]['matchedOn']);
		$this->assertContains('goldenRecord.name', $pairs[0]['matchedOn']);
	}

	public function testNestedDotPathBlockingKeyPartitionsCandidates(): void {
		// Same nested name, but different nested blocking key => never compared.
		$a = $this->makeObject('a', ['goldenRecord' => ['name' => 'Acme', 'postalCode' => '1011']]);
		$b = $this->makeObject('b', ['goldenRecord' => ['name' => 'Acme', 'postalCode' => '9999']]);

		$this->objectService->method('findAll')->willReturn([$a, $b]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn([
			'x-openregister-dedup' => [
				'blockingKeys' => ['goldenRecord.postalCode'],
				'matchRules' => [['field' => 'goldenRecord.name', 'method' => 'exact', 'weight' => 1]],
				'threshold' => 0.85,
			],
		]);
		$this->stubScopedSchema($schema);

		$this->assertSame([], $this->service->findDuplicates(1, 1, null));
	}

	public function testMissingNestedSegmentResolvesToNullWithoutThrowing(): void {
		// `a` has no `goldenRecord` key at all; `b` has one but with a
		// non-array value under it. Neither should throw; the field
		// resolves to null and contributes 0.0 similarity.
		$a = $this->makeObject('a', ['name' => 'Acme']);
		$b = $this->makeObject('b', ['name' => 'Acme', 'goldenRecord' => 'not-an-array']);

		$this->objectService->method('findAll')->willReturn([$a, $b]);

		$rules = [
			['field' => 'goldenRecord.email', 'method' => 'exact', 'weight' => 1],
		];

		$pairs = $this->service->findDuplicates(1, 1, $rules, 0.85);

		$this->assertSame([], $pairs);
	}

	public function testPlainTopLevelFieldBackwardCompatUnaffected(): void {
		// Re-run the original near-duplicate scenario to lock in that a
		// plain, dot-free field/key still behaves exactly as before.
		$a = $this->makeObject('a', ['name' => 'Acme BV', 'email' => 'info@acme.test']);
		$b = $this->makeObject('b', ['name' => 'ACME  bv', 'email' => 'info@acme.test']);

		$this->objectService->method('findAll')->willReturn([$a, $b]);

		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn([
			'x-openregister-dedup' => [
				'blockingKeys' => ['email'],
				'matchRules' => [
					['field' => 'email', 'method' => 'exact', 'weight' => 0.5],
					['field' => 'name', 'method' => 'normalized', 'weight' => 0.5],
				],
				'threshold' => 0.85,
			],
		]);
		$this->stubScopedSchema($schema);

		$pairs = $this->service->findDuplicates(1, 1, null);

		$this->assertCount(1, $pairs);
		$this->assertSame(1.0, $pairs[0]['score']);
	}
}
