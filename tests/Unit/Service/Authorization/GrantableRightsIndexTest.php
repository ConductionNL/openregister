<?php

/**
 * The grantable-rights index: the menu of rights that exist to give.
 *
 * The property that matters here is not that the index is correct once, but
 * that it is never STALE. A stale permission index fails silently — a right
 * that was revoked still reads as grantable, and nothing about the answer looks
 * wrong to whoever is reading it. So the tests below spend most of their effort
 * on invalidation and on the failure modes, not on the happy path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Authorization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Authorization;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Authorization\GrantableRightsIndex;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Coverage metadata is deliberately absent — see the note in
 * GrantableRightsInvalidationListenerTest. With the annotations in place this
 * suite exercised GrantableRightsIndex thoroughly and the coverage report still
 * read 0.0%, which is how the coverage ratchet failed a change that had tests.
 */
class GrantableRightsIndexTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private ICacheFactory&MockObject $cacheFactory;

	/**
	 * Stand-in for the distributed cache, backed by an array so the tests can
	 * observe what was stored and what was removed.
	 *
	 * @var array<string, mixed>
	 */
	private array $cacheStore = [];

	/**
	 * Wire an index over a cache that actually stores things.
	 *
	 * A cache mock that returns null forever would make every test pass
	 * whether or not caching works, which is the wrong instrument for a suite
	 * whose subject IS the cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->cacheStore = [];

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			fn (string $key) => ($this->cacheStore[$key] ?? null)
		);
		$cache->method('set')->willReturnCallback(
			function (string $key, $value): bool {
				$this->cacheStore[$key] = $value;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->cacheStore[$key]);
				return true;
			}
		);

		$this->cacheFactory->method('createDistributed')->willReturn($cache);
	}//end setUp()

	/**
	 * Build the index under test.
	 *
	 * @return GrantableRightsIndex The subject.
	 */
	private function index(): GrantableRightsIndex {
		return new GrantableRightsIndex(
			$this->schemaMapper,
			$this->registerMapper,
			$this->cacheFactory,
			$this->createMock(LoggerInterface::class)
		);
	}//end index()

	/**
	 * Build a schema.
	 *
	 * @param int        $id            The schema id.
	 * @param string     $slug          The schema slug.
	 * @param array|null $authorization The authorization block.
	 * @param array|null $configuration The configuration block.
	 *
	 * @return Schema The schema.
	 */
	private function schema(int $id, string $slug, ?array $authorization = null, ?array $configuration = null): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug($slug);
		$schema->setAuthorization($authorization);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	/**
	 * Build a register containing the given schema ids.
	 *
	 * @param int             $id      The register id.
	 * @param array<int, int> $schemas The schema ids it contains.
	 *
	 * @return Register The register.
	 */
	private function register(int $id, array $schemas): Register {
		$register = new Register();
		$register->setId($id);
		$register->setSchemas($schemas);

		return $register;
	}//end register()

	/**
	 * An action offered to `mcp` in the authorization block appears in the
	 * menu, attributed to that source.
	 *
	 * @return void
	 */
	public function testAnAuthorizationOfferIsIndexed(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[$this->schema(id: 1, slug: 'invoice', authorization: ['read' => ['mcp', 'staff']])]
		);
		$this->registerMapper->method('findAll')->willReturn([$this->register(id: 9, schemas: [1])]);

		$this->assertSame(
			[
				[
					'register' => 9,
					'schema' => 'invoice',
					'schemaId' => 1,
					'action' => 'read',
					'source' => GrantableRightsIndex::SOURCE_AUTHORIZATION,
				],
			],
			$this->index()->getIndex()
		);
	}//end testAnAuthorizationOfferIsIndexed()

	/**
	 * The `x-openregister-mcp` dialect is the OTHER source of offers, and it is
	 * the one that emits live tools. An index knowing only the authorization
	 * block would present itself as the complete menu while missing half of it.
	 *
	 * @return void
	 */
	public function testTheMcpDialectIsAlsoIndexed(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[
				$this->schema(
					id: 2,
					slug: 'contact',
					configuration: [
						'x-openregister-mcp' => [
							'enabled' => true,
							'tools' => ['search' => [], 'get' => []],
						],
					]
				),
			]
		);
		$this->registerMapper->method('findAll')->willReturn([$this->register(id: 9, schemas: [2])]);

		$actions = array_column($this->index()->getIndex(), 'action');
		sort($actions);

		$this->assertSame(['get', 'search'], $actions);
		$this->assertSame(
			GrantableRightsIndex::SOURCE_MCP_DIALECT,
			$this->index()->getIndex()[0]['source']
		);
	}//end testTheMcpDialectIsAlsoIndexed()

	/**
	 * A dialect block that is present but NOT enabled offers nothing. `enabled`
	 * is the opt-in gate, and reading the tool list past it would offer rights
	 * for a surface the schema deliberately switched off.
	 *
	 * @return void
	 */
	public function testADisabledDialectOffersNothing(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[
				$this->schema(
					id: 3,
					slug: 'secret',
					configuration: [
						'x-openregister-mcp' => [
							'enabled' => false,
							'tools' => ['search' => []],
						],
					]
				),
			]
		);
		$this->registerMapper->method('findAll')->willReturn([]);

		$this->assertSame([], $this->index()->getIndex());
	}//end testADisabledDialectOffersNothing()

	/**
	 * A schema with no offer at all contributes nothing — the index is a menu
	 * of what CAN be granted, not a listing of every schema.
	 *
	 * @return void
	 */
	public function testASchemaThatOffersNothingIsAbsent(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[$this->schema(id: 4, slug: 'ledger', authorization: ['read' => ['staff']])]
		);
		$this->registerMapper->method('findAll')->willReturn([$this->register(id: 9, schemas: [4])]);

		$this->assertSame([], $this->index()->getIndex());
	}//end testASchemaThatOffersNothingIsAbsent()

	/**
	 * A schema in several registers yields an entry per register. "May be
	 * offered" is a question about a register's data, so collapsing them would
	 * hide which register a right actually reaches.
	 *
	 * @return void
	 */
	public function testASchemaInTwoRegistersYieldsAnEntryPerRegister(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[$this->schema(id: 5, slug: 'case', authorization: ['read' => ['mcp']])]
		);
		$this->registerMapper->method('findAll')->willReturn(
			[$this->register(id: 10, schemas: [5]), $this->register(id: 11, schemas: [5])]
		);

		$this->assertSame([10, 11], array_column($this->index()->getIndex(), 'register'));
	}//end testASchemaInTwoRegistersYieldsAnEntryPerRegister()

	/**
	 * 🔴 The property the whole design depends on: a revoked right stops being
	 * offered. The index must not be able to outlive the schema that justified
	 * it.
	 *
	 * @return void
	 */
	public function testARevokedRightStopsBeingOffered(): void {
		$offering = $this->schema(id: 6, slug: 'quote', authorization: ['read' => ['mcp']]);
		$revoked = $this->schema(id: 6, slug: 'quote', authorization: ['read' => ['staff']]);

		$this->schemaMapper->method('findAll')->willReturnOnConsecutiveCalls([$offering], [$revoked]);
		$this->registerMapper->method('findAll')->willReturn([$this->register(id: 9, schemas: [6])]);

		$index = $this->index();
		$this->assertCount(1, $index->getIndex(), 'precondition: the right must be offered before it is revoked');

		$index->invalidate();

		$this->assertSame(
			[],
			$index->getIndex(),
			'A revoked right was still served as grantable — the index outlived the schema that justified it.'
		);
	}//end testARevokedRightStopsBeingOffered()

	/**
	 * ⚠️ Invalidation must clear the per-request memo as well as the shared
	 * cache. A memo that survives its own invalidation is a stale index with a
	 * shorter lifetime, which is not the same thing as a fresh one.
	 *
	 * @return void
	 */
	public function testInvalidationClearsTheCacheItself(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[$this->schema(id: 7, slug: 'lead', authorization: ['read' => ['mcp']])]
		);
		$this->registerMapper->method('findAll')->willReturn([$this->register(id: 9, schemas: [7])]);

		$index = $this->index();
		$index->getIndex();

		$this->assertNotSame([], $this->cacheStore, 'precondition: the built index must have been cached');

		$index->invalidate();

		$this->assertSame([], $this->cacheStore, 'invalidate() left the shared cache entry in place.');
	}//end testInvalidationClearsTheCacheItself()

	/**
	 * A cache hit must not re-walk the schemas. This is the entire reason the
	 * index exists — 406 registers and 1,000+ schemas is not a per-request
	 * query — so it is asserted rather than assumed.
	 *
	 * @return void
	 */
	public function testAWarmIndexDoesNotWalkTheSchemasAgain(): void {
		$this->schemaMapper->expects($this->once())
			->method('findAll')
			->willReturn([$this->schema(id: 8, slug: 'order', authorization: ['read' => ['mcp']])]);
		$this->registerMapper->method('findAll')->willReturn([$this->register(id: 9, schemas: [8])]);

		$first = $this->index();
		$first->getIndex();

		// A SECOND instance, so the per-request memo cannot be what satisfies
		// this — the shared cache has to.
		$this->assertCount(1, $this->index()->getIndex());
	}//end testAWarmIndexDoesNotWalkTheSchemasAgain()

	/**
	 * 🔴 A build failure serves an EMPTY index and caches nothing.
	 *
	 * A partial permission menu is a wrong answer that looks like a right one.
	 * Caching it would make one transient failure permanent, so the next read
	 * has to get to try again.
	 *
	 * @return void
	 */
	public function testABuildFailureServesEmptyAndCachesNothing(): void {
		$this->schemaMapper->method('findAll')->willThrowException(new \RuntimeException('database gone'));
		$this->registerMapper->method('findAll')->willReturn([]);

		$this->assertSame([], $this->index()->getIndex());
		$this->assertSame([], $this->cacheStore, 'A failed build was cached, making a transient failure permanent.');
	}//end testABuildFailureServesEmptyAndCachesNothing()

	/**
	 * A register's schema list only ever holds bare ids: `setSchemas()` filters
	 * its input down to ints and strings. Pinned because the index once carried
	 * a defensive branch for hydrated schema arrays, and this is what proved it
	 * unreachable — a hydrated entry never survives the setter to reach it.
	 *
	 * @return void
	 */
	public function testARegisterSchemaListHoldsBareIdsOnly(): void {
		$register = new Register();
		$register->setId(31);
		$register->setSchemas([['id' => 20, 'slug' => 'claim'], 20]);

		$this->assertSame(
			[20],
			array_values($register->getSchemas()),
			'setSchemas() stopped filtering to scalars; the index needs its normalising branch back.'
		);
	}//end testARegisterSchemaListHoldsBareIdsOnly()

	/**
	 * A malformed dialect block must not throw. A schema with a bad annotation
	 * should cost that schema its entries, never the whole menu — an exception
	 * here would empty the index for every other schema too.
	 *
	 * @return void
	 */
	public function testAMalformedDialectIsIgnoredNotFatal(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[
				$this->schema(
					id: 21,
					slug: 'broken',
					configuration: ['x-openregister-mcp' => ['enabled' => true, 'tools' => 'not-an-object']]
				),
				$this->schema(id: 22, slug: 'fine', authorization: ['read' => ['mcp']]),
			]
		);
		$this->registerMapper->method('findAll')->willReturn([]);

		$this->assertSame(['fine'], array_column($this->index()->getIndex(), 'schema'));
	}//end testAMalformedDialectIsIgnoredNotFatal()

	/**
	 * A dialect block that is not an object at all is ignored the same way.
	 *
	 * @return void
	 */
	public function testANonObjectDialectIsIgnored(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[$this->schema(id: 23, slug: 'odd', configuration: ['x-openregister-mcp' => 'yes please'])]
		);
		$this->registerMapper->method('findAll')->willReturn([]);

		$this->assertSame([], $this->index()->getIndex());
	}//end testANonObjectDialectIsIgnored()

	/**
	 * Repeated reads within one request do not re-hit the distributed cache.
	 *
	 * @return void
	 */
	public function testTheMemoServesRepeatedReadsInOneRequest(): void {
		$this->schemaMapper->expects($this->once())
			->method('findAll')
			->willReturn([$this->schema(id: 24, slug: 'memo', authorization: ['read' => ['mcp']])]);
		$this->registerMapper->method('findAll')->willReturn([]);

		$index = $this->index();

		$this->assertSame($index->getIndex(), $index->getIndex());
	}//end testTheMemoServesRepeatedReadsInOneRequest()

	/**
	 * A schema belonging to no register still appears, with a null register.
	 * Dropping it would hide a real offer; inventing a register would be worse.
	 *
	 * @return void
	 */
	public function testASchemaInNoRegisterIsStillListed(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[$this->schema(id: 12, slug: 'orphan', authorization: ['read' => ['mcp']])]
		);
		$this->registerMapper->method('findAll')->willReturn([]);

		$entries = $this->index()->getIndex();
		$this->assertCount(1, $entries);
		$this->assertNull($entries[0]['register']);
	}//end testASchemaInNoRegisterIsStillListed()
}//end class
