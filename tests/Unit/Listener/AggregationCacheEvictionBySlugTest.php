<?php

/**
 * Regression: an object write never evicted its schema's aggregations.
 *
 * Found live on :8099 on 8 October 2026: a pipelinq lead's line-item count
 * answered `"cached": true` with value 0 while the line existed. The listener
 * evicted with the object's register and schema ids ("20", "34"); the cache
 * keys entries and version counters by slug ("pipelinq", "leadProduct"). The
 * bump landed on a counter nothing reads.
 *
 * This test uses the real AggregationCache on an in-memory backend, so a
 * stale entry that survives the write fails it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/aggregations-backend-native/spec.md#requirement-an-object-write-must-evict-the-aggregations-of-its-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AggregationCacheInvalidationListener;
use OCA\OpenRegister\Service\Aggregation\AggregationCache;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Locks that eviction reaches the key the cache writes.
 */
class AggregationCacheEvictionBySlugTest extends TestCase {

	private AggregationCache $cache;

	protected function setUp(): void {
		$store = new class implements ICache {
			/**
			 * @var array<string, mixed>
			 */
			private array $values = [];

			public function get($key) {
				return ($this->values[$key] ?? null);
			}

			public function set($key, $value, $ttl = 0) {
				$this->values[$key] = $value;
				return true;
			}

			public function hasKey($key) {
				return array_key_exists($key, $this->values);
			}

			public function remove($key) {
				unset($this->values[$key]);
				return true;
			}

			public function clear($prefix = '') {
				$this->values = [];
				return true;
			}

			public static function isAvailable(): bool {
				return true;
			}
		};

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($store);

		$this->cache = new AggregationCache(
			$factory,
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(OrganisationService::class)
		);
	}//end setUp()

	/**
	 * A container whose mappers answer register 20 = pipelinq, schema 34 = leadProduct.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$register = new Register();
		$register->setId(20);
		$register->setSlug('pipelinq');
		$schema = new Schema();
		$schema->setId(34);
		$schema->setSlug('leadProduct');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[RegisterMapper::class, $registerMapper],
				[SchemaMapper::class, $schemaMapper],
			]
		);

		return $container;
	}//end container()

	/**
	 * A lead product as the object layer hands it to the event: ids, not slugs.
	 *
	 * @return ObjectEntity The object.
	 */
	private function leadProduct(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('lp-1');
		$object->setRegister('20');
		$object->setSchema('34');
		return $object;
	}//end leadProduct()

	public function testAWriteEvictsTheEntryTheRunnerCachedBySlug(): void {
		$filter = ['metric' => 'count', 'filter' => ['lead' => 'l-1']];
		$this->cache->set('pipelinq', 'leadProduct', 'lineItems', $filter, ['value' => 0]);
		$this->assertSame(['value' => 0], $this->cache->get('pipelinq', 'leadProduct', 'lineItems', $filter));

		$listener = new AggregationCacheInvalidationListener($this->cache, $this->container());
		$listener->handle(new ObjectCreatedEvent($this->leadProduct()));

		$this->assertNull($this->cache->get('pipelinq', 'leadProduct', 'lineItems', $filter));
	}//end testAWriteEvictsTheEntryTheRunnerCachedBySlug()

	public function testAnUpdateEvictsToo(): void {
		$filter = ['metric' => 'count'];
		$this->cache->set('pipelinq', 'leadProduct', 'lineItems', $filter, ['value' => 1]);

		$object = $this->leadProduct();
		(new AggregationCacheInvalidationListener($this->cache, $this->container()))
			->handle(new ObjectUpdatedEvent($object, $object));

		$this->assertNull($this->cache->get('pipelinq', 'leadProduct', 'lineItems', $filter));
	}//end testAnUpdateEvictsToo()

	public function testAnotherSchemaKeepsItsEntry(): void {
		$filter = ['metric' => 'count'];
		$this->cache->set('pipelinq', 'client', 'leads', $filter, ['value' => 5]);

		(new AggregationCacheInvalidationListener($this->cache, $this->container()))
			->handle(new ObjectCreatedEvent($this->leadProduct()));

		$this->assertSame(['value' => 5], $this->cache->get('pipelinq', 'client', 'leads', $filter));
	}//end testAnotherSchemaKeepsItsEntry()
}//end class
