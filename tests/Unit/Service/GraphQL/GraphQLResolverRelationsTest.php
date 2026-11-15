<?php
/**
 * Relation fields of GraphQLResolver: deferred batch loading of related
 * objects, the `_usedBy` lookup and the per-request reset.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\GraphQL;

use DateTime;
use GraphQL\Deferred;
use GraphQL\Executor\Promise\Adapter\SyncPromise;
use GraphQL\Executor\Promise\Adapter\SyncPromiseQueue;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolver;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverObjects;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverRights;
use OCA\OpenRegister\Service\GraphQL\GraphQLResolverShaping;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use OCA\OpenRegister\Service\Object\RelationLookup;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\GraphQL\GraphQLResolver
 * @uses   \OCA\OpenRegister\Service\GraphQL\GraphQLResolverObjects
 * @uses   \OCA\OpenRegister\Service\GraphQL\GraphQLResolverRights
 * @uses   \OCA\OpenRegister\Service\GraphQL\GraphQLResolverShaping
 * @uses   \OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator
 * @uses   \OCA\OpenRegister\Service\Object\RelationLookup
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 * @uses   \OCA\OpenRegister\Service\Object\PermissionCheckOptions
 * @uses   \OCA\OpenRegister\Service\Object\ReadScope
 */
class GraphQLResolverRelationsTest extends TestCase {

	/**
	 * The relation handler double.
	 *
	 * @var RelationHandler&MockObject
	 */
	private $relations;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * The resolver under test.
	 *
	 * @var GraphQLResolver
	 */
	private GraphQLResolver $resolver;

	/**
	 * Build the resolver over doubles; only the relation handler matters here.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->relations = $this->createMock(RelationHandler::class);
		$this->logger    = $this->createMock(LoggerInterface::class);

		$this->resolver = new GraphQLResolver(
			new GraphQLResolverObjects(
				$this->createMock(GetObject::class),
				$this->createMock(ObjectService::class),
				$this->relations,
				$this->createMock(RegisterMapper::class)
			),
			new GraphQLResolverRights(
				$this->createMock(PermissionHandler::class),
				$this->createMock(PropertyRbacHandler::class)
			),
			new GraphQLResolverShaping(
				$this->createMock(TranslationHandler::class),
				$this->createMock(AggregationRunner::class),
				new TimeseriesRequestValidator()
			),
			$this->logger
		);
	}//end setUp()

	/**
	 * Run the deferred queue and return what a deferred resolved to.
	 *
	 * @param Deferred $deferred The deferred value.
	 *
	 * @return mixed The resolved value.
	 */
	private function settle(Deferred $deferred): mixed {
		SyncPromiseQueue::run();
		$this->assertSame(SyncPromise::FULFILLED, $deferred->state);

		return $deferred->result;
	}//end settle()

	/**
	 * A stored object with metadata.
	 *
	 * @param string $uuid The uuid.
	 * @param array  $data The object body.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		$entity->setRegister('3');
		$entity->setSchema('9');
		$entity->setOwner('alice');
		$entity->setCreated(new DateTime('2026-01-02T03:04:05+00:00'));

		return $entity;
	}//end entity()

	/**
	 * Every relation buffered before the first resolution loads in one batch,
	 * and each resolves to its object with the metadata fields added.
	 *
	 * @return void
	 */
	public function testBufferedRelationsLoadInOneBatch(): void {
		$this->relations->expects($this->once())->method('bulkLoadRelationshipsBatched')
			->with(['uuid-a', 'uuid-b'])
			->willReturn(
				[
					'uuid-a' => $this->entity(uuid: 'uuid-a', data: ['name' => 'A']),
					'uuid-b' => $this->entity(uuid: 'uuid-b', data: ['name' => 'B']),
				]
			);

		$first  = $this->resolver->resolveRelation('uuid-a');
		$second = $this->resolver->resolveRelation('uuid-b');

		$this->assertSame(
			[
				'id' => 'uuid-a',
				'name' => 'A',
				'_uuid' => 'uuid-a',
				'_register' => '3',
				'_schema' => '9',
				'_created' => '2026-01-02T03:04:05+00:00',
				'_updated' => null,
				'_owner' => 'alice',
			],
			$this->settle($first)
		);
		$this->assertSame('B', $this->settle($second)['name']);
	}//end testBufferedRelationsLoadInOneBatch()

	/**
	 * A relation the batch does not return resolves to null.
	 *
	 * @return void
	 */
	public function testAMissingRelationResolvesToNull(): void {
		$this->relations->method('bulkLoadRelationshipsBatched')->willReturn([]);

		$this->assertNull($this->settle($this->resolver->resolveRelation('uuid-gone')));
	}//end testAMissingRelationResolvesToNull()

	/**
	 * A failing batch load is logged and the relation resolves to null, not an error.
	 *
	 * @return void
	 */
	public function testAFailingBatchLoadIsLoggedAndResolvesToNull(): void {
		$this->relations->method('bulkLoadRelationshipsBatched')
			->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('GraphQL relation batch load failed: database gone');

		$this->assertNull($this->settle($this->resolver->resolveRelation('uuid-a')));
	}//end testAFailingBatchLoadIsLoggedAndResolvesToNull()

	/**
	 * A cached relation is not loaded again; after reset() it is.
	 *
	 * @return void
	 */
	public function testACachedRelationIsReusedUntilReset(): void {
		$this->relations->expects($this->exactly(2))->method('bulkLoadRelationshipsBatched')
			->with(['uuid-a'])
			->willReturn(['uuid-a' => $this->entity(uuid: 'uuid-a', data: ['name' => 'A'])]);

		$this->assertSame('A', $this->settle($this->resolver->resolveRelation('uuid-a'))['name']);
		// Cached: the second batch call only happens after reset().
		$this->assertSame('A', $this->settle($this->resolver->resolveRelation('uuid-a'))['name']);

		$this->resolver->reset();
		$this->assertSame('A', $this->settle($this->resolver->resolveRelation('uuid-a'))['name']);
		$this->assertSame([], $this->resolver->getPartialErrors());
	}//end testACachedRelationIsReusedUntilReset()

	/**
	 * `_usedBy` returns the results of the relation handler's lookup for that object.
	 *
	 * @return void
	 */
	public function testUsedByReturnsTheLookupResults(): void {
		$rows = [['_uuid' => 'ref-1'], ['_uuid' => 'ref-2']];
		$this->relations->expects($this->once())->method('getUsedBy')
			->with($this->callback(static fn (RelationLookup $lookup): bool => $lookup->objectId === 'obj-1'))
			->willReturn(['results' => $rows, 'total' => 2]);

		$this->assertSame($rows, $this->resolver->resolveUsedBy('obj-1'));
	}//end testUsedByReturnsTheLookupResults()
}//end class
