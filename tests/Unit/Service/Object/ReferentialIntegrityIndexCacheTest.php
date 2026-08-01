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
     * Build a DB connection whose catalogue-version query yields the given row.
     *
     * Passing null for $row makes the query throw, standing in for a version
     * token that cannot be computed.
     *
     * @param array|null $row The row returned by the aggregate query.
     *
     * @return IDBConnection&MockObject
     */
    private function dbReturning(?array $row): IDBConnection&MockObject
    {
        $db = $this->createMock(IDBConnection::class);

        if ($row === null) {
            $db->method('getQueryBuilder')->willThrowException(new \RuntimeException('no db'));
            return $db;
        }

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn($row);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('selectAlias')->willReturnSelf();
        $qb->method('createFunction')->willReturnArgument(0);
        $qb->method('from')->willReturnSelf();
        $qb->method('executeQuery')->willReturn($result);

        $db->method('getQueryBuilder')->willReturn($qb);

        return $db;

    }//end dbReturning()


    /**
     * Construct a service over a given schema catalogue and version row.
     *
     * @param array      $schemas     Schemas returned by SchemaMapper::findAll.
     * @param array|null $versionRow  Row for the catalogue-version query.
     * @param int|null   $expectFinds Expected number of findAll calls, or null to not assert.
     *
     * @return ReferentialIntegrityService
     */
    private function service(array $schemas, ?array $versionRow, ?int $expectFinds=null): ReferentialIntegrityService
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
            $this->dbReturning($versionRow),
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
        $row       = [
            'cnt' => '2',
            'mx'  => '2026-01-01 00:00:00',
        ];

        // First instance populates the cache (one findAll).
        $first = $this->service($catalogue, $row, 1);
        $this->assertTrue($first->hasIncomingOnDeleteReferences('1'));

        // Second instance, same version token: must NOT touch the schema mapper.
        $second = $this->service($catalogue, $row, 0);
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
            [
                'cnt' => '2',
                'mx'  => '2026-01-01 00:00:00',
            ],
            1
        );
        $this->assertTrue($first->hasIncomingOnDeleteReferences('1'));

        // Catalogue changed: relation removed AND the version token moved.
        $second = $this->service(
            [$this->schema(1, 'parent')],
            [
                'cnt' => '1',
                'mx'  => '2026-01-02 00:00:00',
            ],
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
            [
                'cnt' => '1',
                'mx'  => '2026-01-01 00:00:00',
            ],
            1
        );
        $this->assertFalse($before->hasIncomingOnDeleteReferences('1'));

        // A referencing schema is added; the token moves because COUNT(*) moved.
        $after = $this->service(
            [
                $this->schema(1, 'parent'),
                $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
            ],
            [
                'cnt' => '2',
                'mx'  => '2026-01-02 00:00:00',
            ],
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
            [
                'cnt' => '2',
                'mx'  => '2026-01-01 00:00:00',
            ],
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
            [
                'cnt' => '2',
                'mx'  => '2026-01-01 00:00:00',
            ],
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
        $row = [
            'cnt' => '2',
            'mx'  => '2026-01-01 00:00:00',
        ];

        // Correct version token, but the index is not an array.
        $this->cache->set(
            'relation_index',
            [
                'version' => '2|2026-01-01 00:00:00',
                'index'   => 'not-an-array',
            ]
        );

        $service = $this->service(
            [
                $this->schema(1, 'parent'),
                $this->schema(2, 'child', ['parent' => $this->relation('parent')]),
            ],
            $row,
            1
        );

        $this->assertTrue(
            $service->hasIncomingOnDeleteReferences('1'),
            'A malformed cache payload must trigger a rebuild'
        );

    }//end testMalformedCachePayloadIsIgnored()


}//end class
