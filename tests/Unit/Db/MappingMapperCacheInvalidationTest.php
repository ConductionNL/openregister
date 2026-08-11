<?php

/**
 * The mapping cache is busted on write — enforced here, and until now untested.
 *
 * `MappingService::getMapping()` is a read-through against a DISTRIBUTED cache,
 * so a stale entry outlives the request that created it. Two properties keep it
 * honest, and both were load-bearing comments rather than checks:
 *
 *  1. ALL THREE KEYS. `MappingMapper::find()` accepts an id, a uuid or a slug,
 *     and `getMapping()` caches under whichever identifier the caller passed —
 *     so one mapping can occupy three cache keys. An invalidation that removed
 *     only the id would look like a flush and leave two live copies.
 *
 *  2. THE SAME PREFIX. The mapper builds its cache handle from its own
 *     `CACHE_PREFIX` constant, annotated "Cache key prefix matching
 *     MappingService". If the two ever drift, the mapper deletes keys in a
 *     namespace nothing reads and the service keeps serving the stale entry —
 *     with no error anywhere, in either class, at any point. A comment asserting
 *     that two constants are equal is a claim, so this asserts it.
 *
 * Filed as #2417 on the theory that the invalidation was missing entirely. It is
 * not: it is present, correct, more complete than the public
 * `MappingService::invalidateMappingCache()`, and simply lives under a different
 * name. What was actually missing is this file.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * Write-path cache invalidation for mappings.
 *
 * @covers \OCA\OpenRegister\Db\MappingMapper
 */
class MappingMapperCacheInvalidationTest extends TestCase
{

    /**
     * The distributed cache, mocked.
     *
     * @var ICache&MockObject
     */
    private ICache&MockObject $cache;

    /**
     * The mapper under test.
     *
     * @var MappingMapper
     */
    private MappingMapper $mapper;

    /**
     * Build the mapper over a cache factory that yields the mock.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = $this->createMock(ICache::class);

        $factory = $this->createMock(ICacheFactory::class);
        $factory->method('createDistributed')->willReturn($this->cache);

        $this->mapper = new MappingMapper(
            $this->createMock(IDBConnection::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $factory,
            new NullLogger()
        );

    }//end setUp()

    /**
     * Invoke the private write-path invalidation.
     *
     * @param Mapping $mapping The mapping being written.
     *
     * @return void
     */
    private function invalidate(Mapping $mapping): void
    {
        $method = new ReflectionMethod(MappingMapper::class, 'invalidateCache');
        $method->setAccessible(true);
        $method->invoke($this->mapper, $mapping);

    }//end invalidate()

    /**
     * Read a class's private constant.
     *
     * @param string $class The class name.
     * @param string $name  The constant name.
     *
     * @return mixed The value.
     */
    private function constant(string $class, string $name): mixed
    {
        return (new ReflectionClass($class))->getConstant($name);

    }//end constant()

    /**
     * PROPERTY 1. Every identifier the mapping can be looked up by is evicted,
     * not just the numeric id. Narrow this to one key and the cache keeps
     * serving the pre-write mapping under the other two.
     *
     * @return void
     */
    public function testAllThreeLookupKeysAreEvicted(): void
    {
        $mapping = new Mapping();
        $mapping->setId(42);
        $mapping->setUuid('bd2a6f2e-0000-4000-8000-000000000001');
        $mapping->setSlug('person-to-contact');

        $removed = [];
        $this->cache->method('remove')->willReturnCallback(
            static function (string $key) use (&$removed): bool {
                $removed[] = $key;

                return true;
            }
        );

        $this->invalidate(mapping: $mapping);

        sort($removed);
        $this->assertSame(
            ['42', 'bd2a6f2e-0000-4000-8000-000000000001', 'person-to-contact'],
            $removed,
            'MappingService::getMapping() caches under whichever identifier the caller passed, '
            .'so all three have to go.'
        );

    }//end testAllThreeLookupKeysAreEvicted()

    /**
     * The DERIVED slug is evicted too, and it has to be.
     *
     * `Mapping::getSlug()` never returns empty: with no stored slug it derives
     * one from the name, falling back to `mapping-{id}`. That derived value is a
     * real lookup key — `MappingMapper::find()` matches on the slug column and
     * `getMapping()` caches under whatever string it was handed — so an
     * invalidation that only used the STORED slug would skip it.
     *
     * Pinned because the obvious "tidy-up" here is to stop evicting a value that
     * looks computed, and that would reintroduce the stale read.
     *
     * @return void
     */
    public function testTheDerivedSlugIsEvictedAsWellAsTheId(): void
    {
        $mapping = new Mapping();
        $mapping->setId(7);

        $removed = [];
        $this->cache->method('remove')->willReturnCallback(
            static function (string $key) use (&$removed): bool {
                $removed[] = $key;

                return true;
            }
        );

        $this->invalidate(mapping: $mapping);

        $this->assertContains('7', $removed);
        $this->assertContains(
            $mapping->getSlug(),
            $removed,
            'Mapping::getSlug() derives a value when none is stored, and that derived value is a '
            .'key MappingService::getMapping() can have cached under.'
        );

    }//end testTheDerivedSlugIsEvictedAsWellAsTheId()

    /**
     * Invalidation runs AFTER the insert on the create path, and the ordering is
     * load-bearing. `getSlug()`'s last-resort fallback is
     * `mapping-{random hex}` — a DIFFERENT value on every call — so invalidating
     * an entity that has no id yet would evict a key nothing ever wrote.
     * Asserting the entity is identified by the time it is invalidated.
     *
     * @return void
     */
    public function testInvalidationHappensAfterThePersistThatGivesTheEntityItsId(): void
    {
        $source = file_get_contents((new ReflectionClass(MappingMapper::class))->getFileName());
        $this->assertIsString($source);

        $body      = $this->bodyOf(source: $source, method: 'createFromArray');
        $insertPos = strpos($body, '$this->insert(');
        $evictPos  = strpos($body, 'invalidateCache');

        $this->assertNotFalse($insertPos, 'createFromArray() no longer persists through insert().');
        $this->assertNotFalse($evictPos);
        $this->assertLessThan(
            $evictPos,
            $insertPos,
            'invalidateCache() must run after insert(), or the entity has no id and getSlug() '
            .'falls back to a random key that was never cached.'
        );

    }//end testInvalidationHappensAfterThePersistThatGivesTheEntityItsId()

    /**
     * PROPERTY 2, and the one that fails silently in both directions. The mapper
     * deletes keys in the namespace named by its own CACHE_PREFIX; the service
     * writes them into the namespace named by ITS CACHE_PREFIX. The mapper's
     * constant carries the comment "Cache key prefix matching MappingService",
     * which is a claim about another file that nothing checked.
     *
     * Drift them and there is no error at either end: the mapper reports a
     * successful invalidation, and the service goes on serving the stale mapping
     * from a distributed cache, across requests, until the TTL expires.
     *
     * @return void
     */
    public function testTheMapperEvictsInTheSameNamespaceTheServiceWritesTo(): void
    {
        $mapperPrefix  = $this->constant(class: MappingMapper::class, name: 'CACHE_PREFIX');
        $servicePrefix = $this->constant(class: MappingService::class, name: 'CACHE_PREFIX');

        $this->assertNotFalse($mapperPrefix, 'MappingMapper::CACHE_PREFIX has been renamed or removed.');
        $this->assertNotFalse($servicePrefix, 'MappingService::CACHE_PREFIX has been renamed or removed.');
        $this->assertSame(
            $servicePrefix,
            $mapperPrefix,
            'The mapper would be deleting cache keys the service never writes, and the service '
            .'would keep serving stale mappings from a distributed cache with no error anywhere.'
        );

    }//end testTheMapperEvictsInTheSameNamespaceTheServiceWritesTo()

    /**
     * The write paths that must reach the invalidation. Asserted by NAME against
     * the source rather than by executing them, because create/update/delete all
     * require a live database — and the failure being guarded against is someone
     * adding a fourth write path, or removing the call from an existing one.
     *
     * @return void
     */
    public function testEveryWritePathInvalidatesTheCache(): void
    {
        $source = file_get_contents((new ReflectionClass(MappingMapper::class))->getFileName());
        $this->assertIsString($source);

        foreach (['createFromArray', 'updateFromArray', 'delete'] as $method) {
            $body = $this->bodyOf(source: $source, method: $method);
            $this->assertStringContainsString(
                'invalidateCache',
                $body,
                sprintf('MappingMapper::%s() writes a mapping without busting its cache entries.', $method)
            );
        }

    }//end testEveryWritePathInvalidatesTheCache()

    /**
     * The source text of one method, from its signature to the closing marker.
     *
     * @param string $source The file source.
     * @param string $method The method name.
     *
     * @return string The body text.
     */
    private function bodyOf(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method.'(');
        $this->assertNotFalse($start, sprintf('MappingMapper::%s() no longer exists.', $method));

        $end = strpos($source, '}//end '.$method.'()', $start);
        $this->assertNotFalse($end, sprintf('MappingMapper::%s() has no end marker to bound it.', $method));

        return substr($source, $start, ($end - $start));

    }//end bodyOf()
}//end class
