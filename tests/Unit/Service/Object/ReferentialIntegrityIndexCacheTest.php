<?php
/**
 * Cross-request relation-index cache tests for ReferentialIntegrityService.
 *
 * The relation index answers "does this schema have incoming onDelete
 * references?". A wrong "no" silently skips a RESTRICT and deletes protected
 * data with no error, so the cache's invalidation is safety-critical and is
 * asserted here by SIDE EFFECT — what the service answers and whether it
 * re-read the schema catalogue — never by inspecting the cache payload.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Object\ReferentialIntegrityService
 */
class ReferentialIntegrityIndexCacheTest extends TestCase
{

    /**
     * Shared in-memory cache standing in for the distributed cache.
     *
     * @var ICache
     */
    private ICache $cache;

    /**
     * Cache factory handing out the shared cache.
     *
     * @var ICacheFactory&MockObject
     */
    private ICacheFactory&MockObject $cacheFactory;


    /**
     * Set up a shared in-memory cache that survives across service instances.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new class implements ICache {
            /**
             * @var array<string, mixed>
             */
            public array $store = [];

            /**
             * @var int
             */
            public int $setCalls = 0;

            public function get($key)
            {
                return ($this->store[$key] ?? null);
            }

            public function set($key, $value, $ttl = 0)
            {
                $this->store[$key] = $value;
                $this->setCalls++;
                return true;
            }

            public function hasKey($key)
            {
                return isset($this->store[$key]);
            }

            public function remove($key)
            {
                unset($this->store[$key]);
                return true;
            }

            public function clear($prefix = '')
            {
                $this->store = [];
                return true;
            }

            public static function isAvailable(): bool
            {
                return true;
            }
        };

        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->cache);

    }//end setUp()


    /**
     * Build a schema entity carrying a single onDelete relation property.
     *
     * @param int    $id       Schema ID.
     * @param string $slug     Schema slug.
     * @param array  $props    Property definitions.
     *
     * @return Schema
     */
    private function schema(int $id, string $slug, array $props=[]): Schema
    {
        $schema = new Schema();
        $schema->setId($id);
        $schema->setSlug($slug);
        $schema->setUuid('uuid-'.$id);
        $schema->setProperties($props);

        return $schema;

    }//end schema()


    /**
     * A property that declares an onDelete relation to a target slug.
     *
     * @param string $targetSlug Target schema slug.
     * @param string $onDelete   The onDelete action.
     *
     * @return array
     */
    private function relation(string $targetSlug, string $onDelete='RESTRICT'): array
    {
        return [
            'type'     => 'string',
            '$ref'     => $targetSlug,
            'onDelete' => $onDelete,
        ];

    }//end relation()


    /**
     * A settled catalogue row: an id/version pair stamped safely in the past.
     *
     * Every row the token reads must have aged past the timestamp(0) settle
     * window, otherwise the service refuses the token by design.
     *
     * @param int    $id      Schema ID.
     * @param string $version Schema version string.
     * @param string $updated Modification stamp.
     *
     * @return array<string, string>
     */
    private function row(int $id, string $version='0.0.1', string $updated='2026-01-01 00:00:00'): array
    {
        return [
            'id'      => (string) $id,
            'version' => $version,
            'updated' => $updated,
        ];

    }//end row()


    /**
     * Build a DB connection whose catalogue-version query yields the given rows.
     *
     * Passing null makes the query throw, standing in for a version token that
     * cannot be computed at all.
     *
     * @param array|null $rows Rows returned by the catalogue-version query.
     *
     * @return IDBConnection&MockObject
     */
    private function dbReturning(?array $rows): IDBConnection&MockObject
    {
        $db = $this->createMock(IDBConnection::class);

        if ($rows === null) {
            $db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no db'));
            return $db;
        }

        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn($rows);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        $db->method('getQueryBuilder')->willReturn($qb);

        return $db;

    }//end dbReturning()


    /**
     * Construct a service over a given schema catalogue and version rows.
     *
     * @param array      $schemas     Schemas returned by SchemaMapper::findAll.
     * @param array|null $versionRows Rows for the catalogue-version query.
     * @param int|null   $expectFinds Expected number of findAll calls, or null to not assert.
     *
     * @return ReferentialIntegrityService
     */
    private function service(array $schemas, ?array $versionRows, ?int $expectFinds=null): ReferentialIntegrityService
    {
        $schemaMapper = $this->createMock(SchemaMapper::class);

        if ($expectFinds === null) {
            $schemaMapper->method('findAll')->willReturn($schemas);
        } else {
            $schemaMapper->expects($this->exactly($expectFinds))
                ->method('findAll')
                ->willReturn($schemas);
        }

        return new ReferentialIntegrityService(
            $schemaMapper,
            $this->createMock(RegisterMapper::class),
            $this->createMock(MagicMapper::class),
            $this->createMock(AuditTrailMapper::class),
            $this->createMock(LoggerInterface::class),
            $this->dbReturning($versionRows),
            $this->cacheFactory
        );

    }//end service()


    /**
     * An unchanged catalogue reuses the cached index without re-reading schemas.
     *
     * Asserted by side effect: the second service instance must answer
     * correctly while SchemaMapper::findAll is never called on it.
     *
     * @return void
     */
    public function testUnchangedVersionReusesCachedIndex(): void
    {
        $catalogue = [
            $this->schema(1, 'parent'),
            $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
        ];
        $rows      = [$this->row(1), $this->row(2)];

        // First instance populates the cache (one findAll).
        $first = $this->service($catalogue, $rows, 1);
        $this->assertTrue($first->hasIncomingOnDeleteReferences('1'));

        // Second instance, same version token: must NOT touch the schema mapper.
        $second = $this->service($catalogue, $rows, 0);
        $this->assertTrue(
            $second->hasIncomingOnDeleteReferences('1'),
            'Cached index must still report the incoming RESTRICT reference'
        );

    }//end testUnchangedVersionReusesCachedIndex()


    /**
     * A changed catalogue version busts the cache and rebuilds the index.
     *
     * The second instance sees a different version token and a catalogue in
     * which the relation is gone, so it must answer false — proving it did not
     * serve the cached "true".
     *
     * @return void
     */
    public function testChangedVersionBustsCache(): void
    {
        $withRelation = [
            $this->schema(1, 'parent'),
            $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
        ];

        $first = $this->service(
            $withRelation,
            [$this->row(1), $this->row(2)],
            1
        );
        $this->assertTrue($first->hasIncomingOnDeleteReferences('1'));

        // Catalogue changed: relation removed AND the version token moved.
        $second = $this->service(
            [$this->schema(1, 'parent')],
            [$this->row(1)],
            1
        );
        $this->assertFalse(
            $second->hasIncomingOnDeleteReferences('1'),
            'A moved version token must rebuild the index, not serve the stale one'
        );

    }//end testChangedVersionBustsCache()


    /**
     * A newly ADDED incoming RESTRICT must be seen once the token moves.
     *
     * This is the direction that loses data when it goes wrong: the cache says
     * "no incoming references", so the RESTRICT is skipped and the parent is
     * deleted even though a child still points at it.
     *
     * @return void
     */
    public function testNewlyAddedRestrictIsSeenAfterVersionMoves(): void
    {
        // Cache is primed while nothing references schema 1.
        $before = $this->service(
            [$this->schema(1, 'parent')],
            [$this->row(1)],
            1
        );
        $this->assertFalse($before->hasIncomingOnDeleteReferences('1'));

        // A referencing schema is added; the token moves because the row set did.
        $after = $this->service(
            [
                $this->schema(1, 'parent'),
                $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
            ],
            [$this->row(1), $this->row(2)],
            1
        );
        $this->assertTrue(
            $after->hasIncomingOnDeleteReferences('1'),
            'A newly added incoming RESTRICT must be visible, not masked by the cached "no references"'
        );

    }//end testNewlyAddedRestrictIsSeenAfterVersionMoves()


    /**
     * An uncomputable version token bypasses the cache entirely.
     *
     * Failing safe means rebuilding from the catalogue rather than trusting an
     * index that cannot be proven current.
     *
     * @return void
     */
    public function testUncomputableVersionBypassesCache(): void
    {
        $catalogue = [
            $this->schema(1, 'parent'),
            $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
        ];

        // Prime the cache with a healthy token.
        $primed = $this->service(
            $catalogue,
            [$this->row(1), $this->row(2)],
            1
        );
        $this->assertTrue($primed->hasIncomingOnDeleteReferences('1'));
        $this->assertNotSame([], $this->cache->store, 'cache should be primed');

        $setCallsBefore = $this->cache->setCalls;

        // Token cannot be computed: must rebuild rather than read the cache.
        $degraded = $this->service($catalogue, null, 1);
        $this->assertTrue($degraded->hasIncomingOnDeleteReferences('1'));

        $this->assertSame(
            $setCallsBefore,
            $this->cache->setCalls,
            'An unverifiable index must not be written back to the cache'
        );

    }//end testUncomputableVersionBypassesCache()


    /**
     * A cache entry whose stored version does not match is not trusted.
     *
     * Guards the entry-shape check: a payload from an older deploy, or one
     * without a version, must be discarded rather than used.
     *
     * @return void
     */
    public function testForeignCachePayloadIsIgnored(): void
    {
        // Plant an index claiming schema 1 has NO incoming references, under a
        // version token that will not match.
        $this->cache->set(
            'relation_index',
            [
                'version' => 'stale-token',
                'index'   => [],
            ]
        );

        $service = $this->service(
            [
                $this->schema(1, 'parent'),
                $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
            ],
            [$this->row(1), $this->row(2)],
            1
        );

        $this->assertTrue(
            $service->hasIncomingOnDeleteReferences('1'),
            'A cache entry under a different version token must never be trusted'
        );

    }//end testForeignCachePayloadIsIgnored()


    /**
     * A malformed cache payload must not be trusted either.
     *
     * @return void
     */
    public function testMalformedCachePayloadIsIgnored(): void
    {
        $catalogue = [
            $this->schema(1, 'parent'),
            $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
        ];
        $rows      = [$this->row(1), $this->row(2)];

        // Prime through the service so the stored token is genuinely current,
        // rather than hard-coding a digest the implementation is free to change.
        $this->assertTrue($this->service($catalogue, $rows, 1)->hasIncomingOnDeleteReferences('1'));

        // Corrupt only the payload, leaving the (correct) version in place.
        $entry          = $this->cache->get('relation_index');
        $entry['index'] = 'not-an-array';
        $this->cache->set('relation_index', $entry);

        $service = $this->service($catalogue, $rows, 1);

        $this->assertTrue(
            $service->hasIncomingOnDeleteReferences('1'),
            'A malformed cache payload must trigger a rebuild'
        );

    }//end testMalformedCachePayloadIsIgnored()


    /**
     * Editing an EXISTING schema must invalidate the index.
     *
     * This is the regression test for the data-loss bug. The row count does not
     * move on an edit, and `updated` is whole-second, so a token built from
     * those two alone can compare equal across an edit that adds an incoming
     * RESTRICT — the cache then answers "no incoming references", the RESTRICT
     * is skipped, and a protected parent is deleted with no error.
     *
     * Here the schema is edited in place: same id, same row count, same
     * `updated` stamp. Only `version` moves, exactly as
     * SchemaMapper::updateFromArray() bumps it. The new RESTRICT must be seen.
     *
     * @return void
     */
    public function testEditedSchemaVersionInvalidatesIndex(): void
    {
        // Cache is primed while schema 2 does not reference schema 1.
        $before = $this->service(
            [$this->schema(1, 'parent'), $this->schema(2, 'child')],
            [$this->row(1), $this->row(2, '0.0.1')],
            1
        );
        $this->assertFalse($before->hasIncomingOnDeleteReferences('1'));

        // Schema 2 is EDITED to add the relation. Count unchanged, `updated`
        // unchanged (same clock second); only the version bumped.
        $after = $this->service(
            [
                $this->schema(1, 'parent'),
                $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
            ],
            [$this->row(1), $this->row(2, '0.0.2')],
            1
        );

        $this->assertTrue(
            $after->hasIncomingOnDeleteReferences('1'),
            'A RESTRICT added by EDITING an existing schema must invalidate the cached index'
        );

    }//end testEditedSchemaVersionInvalidatesIndex()


    /**
     * A catalogue edited within the settle window is never cached.
     *
     * `updated` is timestamp(0), so while the newest stamp is still inside the
     * current clock second a second edit can land on the same value and be
     * invisible. The token must be refused outright for that window: the index
     * is rebuilt from the catalogue and nothing is written back.
     *
     * @return void
     */
    public function testRecentlyEditedCatalogueBypassesCacheEntirely(): void
    {
        $catalogue = [
            $this->schema(1, 'parent'),
            $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
        ];

        // A schema was just edited: its stamp is this very second.
        $rows = [
            $this->row(1),
            $this->row(2, '0.0.2', date('Y-m-d H:i:s')),
        ];

        $setCallsBefore = $this->cache->setCalls;

        $service = $this->service($catalogue, $rows, 1);
        $this->assertTrue($service->hasIncomingOnDeleteReferences('1'));

        $this->assertSame(
            $setCallsBefore,
            $this->cache->setCalls,
            'An index built over an unsettled catalogue must not be cached'
        );
        $this->assertSame(
            [],
            $this->cache->store,
            'Nothing may be written while the newest schema edit is still inside the timestamp(0) window'
        );

    }//end testRecentlyEditedCatalogueBypassesCacheEntirely()


    /**
     * A stamp in the future is treated as unsettled, not as safely old.
     *
     * Clock skew, or a caller that supplied its own `updated`, must not be able
     * to hand the cache a token it cannot vouch for.
     *
     * @return void
     */
    public function testFutureStampBypassesCache(): void
    {
        $catalogue = [
            $this->schema(1, 'parent'),
            $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
        ];

        $rows = [
            $this->row(1),
            $this->row(2, '0.0.1', date('Y-m-d H:i:s', (time() + 3600))),
        ];

        $service = $this->service($catalogue, $rows, 1);
        $this->assertTrue($service->hasIncomingOnDeleteReferences('1'));

        $this->assertSame(
            [],
            $this->cache->store,
            'A future modification stamp must disable the cache, not be read as settled'
        );

    }//end testFutureStampBypassesCache()


}//end class
