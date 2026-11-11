<?php

/**
 * SET_NULL and SET_DEFAULT deletion actions rewrite, audit and log exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\CascadeDeleteOrigin;
use Exception;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Dto\DeletionEffects;
use OCA\OpenRegister\Service\Archival\ArchivalRetentionGuard;
use OCA\OpenRegister\Service\Object\IntegrityCascadeControls;
use OCA\OpenRegister\Service\Object\IntegrityRelationIndex;
use OCA\OpenRegister\Service\Object\IntegrityStorage;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * Golden record of applyDeletionActions() over SET_NULL targets (scalar, array with and without
 * the array marker, scalar under the array marker, a missing property) and SET_DEFAULT targets,
 * with the lookup or the update failing. Each scenario records the data written, the audit rows
 * inserted and the warnings logged. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 */
class ReferentialIntegrityRewriteGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/referential-integrity-rewrite-golden.json';

	/**
	 * What the doubles saw in the current scenario.
	 *
	 * @var array
	 */
	private array $seen = [];

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => [$data, $analysis, $failure]) {
			$this->seen = ['updated' => [], 'audit' => [], 'warnings' => []];
			$result = $this->service(data: $data, failure: $failure)->applyDeletionActions(
				$analysis,
				new CascadeDeleteOrigin(userId: 'alice', cascadeSource: 'root-uuid', triggerSchemaSlug: 'parent-schema', organisationId: 'org-1')
			);
			$actual[$name] = $this->seen + ['result' => $result];
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * Stored object data, the analysis and which call fails (null: none).
	 *
	 * @return array<string, array{0: array, 1: DeletionAnalysis, 2: ?string}>
	 */
	private function scenarios(): array {
		$null = static fn (array $target): DeletionAnalysis => new DeletionAnalysis(deletable: true, effects: new DeletionEffects(nullifyTargets: [$target]));
		$default = static fn (array $target): DeletionAnalysis => new DeletionAnalysis(deletable: true, effects: new DeletionEffects(defaultTargets: [$target]));
		$scalar = ['objectUuid' => 'dep', 'schema' => '3', 'property' => 'parent', 'isArray' => false, 'sourceUuid' => 'root-uuid'];
		$list = ['parent' => ['a', 'root-uuid', 'b', 'root-uuid'], 'name' => 'n'];
		return [
			'null scalar'               => [['parent' => 'root-uuid', 'name' => 'n'], $null($scalar), null],
			'null array'                => [$list, $null(['isArray' => true] + $scalar), null],
			'null array, no marker'     => [$list, $null($scalar), null],
			'null scalar, array marker' => [['parent' => 'root-uuid'], $null(['isArray' => true] + $scalar), null],
			'null missing property'     => [['name' => 'n'], $null(['isArray' => true] + $scalar), null],
			'null no source, no schema' => [['parent' => 'x'], $null(['objectUuid' => 'dep', 'property' => 'parent']), null],
			'null lookup fails'         => [['parent' => 'root-uuid'], $null($scalar), 'find'],
			'null update fails'         => [['parent' => 'root-uuid'], $null($scalar), 'update'],
			'default'                   => [['parent' => 'root-uuid', 'name' => 'n'], $default(['defaultValue' => 'fallback'] + $scalar), null],
			'default new property'      => [['name' => 'n'], $default(['defaultValue' => ['x' => 1]] + $scalar), null],
			'default null value'        => [['parent' => 'root-uuid'], $default(['defaultValue' => null] + $scalar), null],
			'default lookup fails'      => [['parent' => 'root-uuid'], $default(['defaultValue' => 'f'] + $scalar), 'find'],
			'default update fails'      => [['parent' => 'root-uuid'], $default(['defaultValue' => 'f'] + $scalar), 'update'],
			'both kinds'                => [['parent' => 'root-uuid'], new DeletionAnalysis(deletable: true, effects: new DeletionEffects(nullifyTargets: [$scalar], defaultTargets: [['defaultValue' => 'd'] + $scalar])), null],
		];
	}//end scenarios()

	/**
	 * A service whose doubles record what they are asked to do.
	 *
	 * @param array       $data    The dependent object's stored data.
	 * @param string|null $failure Which call throws: 'find', 'update' or null.
	 *
	 * @return ReferentialIntegrityService
	 */
	private function service(array $data, ?string $failure): ReferentialIntegrityService {
		$objectMapper = $this->createMock(MagicMapper::class);
		$objectMapper->method('findAcrossAllSources')->willReturnCallback(
			function (...$args) use ($data, $failure): array {
				if ($failure === 'find' && ($args['includeDeleted'] ?? false) === false) {
					throw new Exception('not found');
				}

				$object = new ObjectEntity();
				$object->setUuid('dep');
				$object->setObject($data);
				return ['object' => $object, 'register' => new Register(), 'schema' => new Schema()];
			}
		);
		$objectMapper->method('update')->willReturnCallback(
			function (ObjectEntity $entity) use ($failure): ObjectEntity {
				if ($failure === 'update') {
					throw new Exception('write failed');
				}

				$this->seen['updated'][] = $entity->getObject();
				return $entity;
			}
		);

		$auditMapper = $this->createMock(AuditTrailMapper::class);
		$auditMapper->method('insert')->willReturnCallback(
			function (AuditTrail $row): AuditTrail {
				$this->seen['audit'][] = [
					'action'  => $row->getAction(),
					'object'  => $row->getObjectUuid(),
					'schema'  => $row->getSchema(),
					'changed' => $row->getChanged(),
					'user'    => $row->getUser(),
				];
				return $row;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->seen['warnings'][] = [$message, $context];
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

		return new ReferentialIntegrityService(
			index: new IntegrityRelationIndex(catalog: new SchemaCatalog(registers: $this->createMock(RegisterMapper::class), schemas: $this->createMock(SchemaMapper::class), tables: $this->createMock(MagicMapperInterface::class)), cacheFactory: $cacheFactory),
			storage: new IntegrityStorage(objectEntityMapper: $objectMapper, db: $this->createMock(IDBConnection::class)),
			controls: new IntegrityCascadeControls(auditTrailMapper: $auditMapper, archivalGuard: new ArchivalRetentionGuard(new \OCA\OpenRegister\Service\Archival\ArchivalSchemaLookup($this->createMock(SchemaMapper::class), $this->createMock(LoggerInterface::class)))),
			logger: $logger
		);
	}//end service()
}//end class
