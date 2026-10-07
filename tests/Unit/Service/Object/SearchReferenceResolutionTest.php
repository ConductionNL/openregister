<?php

/**
 * Unit tests for register/schema reference resolution on the READ path
 * (openregister#3990).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotFoundException;
use OCA\OpenRegister\Service\Object\ContentSearchHandler;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\ViewScopeApplier;
use OCA\OpenRegister\Service\Object\SearchReferenceResolver;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The read path used to int-cast a register or schema reference.
 * `(int)'zaakregister'` is `0`, `0` is not `null`, so the search ran scoped to
 * a register that cannot exist, the lookup threw, the throw was logged, and the
 * caller was handed `0` results. Three apps read that as a fact about their
 * data: filinq unfroze finalised documents, dossiq fed five delete cascades
 * from an empty result, and buildiq published a register it believed it had
 * wiped.
 *
 * The doubles below REPRODUCE that cast instead of stubbing it away: the fake
 * mapper int-casts exactly as MagicMapper does and answers `0` when the cast
 * lands on an id no register carries. So a test that passes here can only pass
 * because the reference was resolved before the mapper saw it.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Object\SearchReferenceResolver
 */
class SearchReferenceResolutionTest extends TestCase {

	/**
	 * The id the slug `zaken` names.
	 *
	 * @var integer
	 */
	private const REGISTER_ID = 19;

	/**
	 * The id the slug `zaak` names.
	 *
	 * @var integer
	 */
	private const SCHEMA_ID = 9476;

	/**
	 * How many objects the register/schema pair really holds.
	 *
	 * @var integer
	 */
	private const REAL_COUNT = 3;

	/**
	 * A register mapper that answers `zaken` and nothing else.
	 *
	 * @return RegisterMapper
	 */
	private function registerMapper(): RegisterMapper {
		// A real entity, not a double: `getId()` is magic on OCP's Entity, so a
		// double cannot answer it, and an entity that answers a wrong id would
		// be the same lie this test exists to catch.
		$register = new Register();
		$register->setId(self::REGISTER_ID);

		$mapper = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$mapper->method('find')->willReturnCallback(
			function (string|int $id) use ($register): Register {
				if ((string)$id === 'zaken' || (string)$id === (string)self::REGISTER_ID) {
					return $register;
				}

				throw new DoesNotExistException('no register named '.$id);
			}
		);

		return $mapper;
	}//end registerMapper()

	/**
	 * A schema mapper that answers `zaak` and nothing else.
	 *
	 * @return SchemaMapper
	 */
	private function schemaMapper(): SchemaMapper {
		$schema = new Schema();
		$schema->setId(self::SCHEMA_ID);

		$mapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$mapper->method('find')->willReturnCallback(
			function (string|int $id) use ($schema): Schema {
				if ((string)$id === 'zaak' || (string)$id === (string)self::SCHEMA_ID) {
					return $schema;
				}

				throw new DoesNotExistException('no schema named '.$id);
			}
		);

		return $mapper;
	}//end schemaMapper()

	/**
	 * The resolver under test, wired to the two mappers above.
	 *
	 * @return SearchReferenceResolver
	 */
	private function resolver(): SearchReferenceResolver {
		return new SearchReferenceResolver(
			$this->registerMapper(),
			$this->schemaMapper(),
			$this->createMock(LoggerInterface::class)
		);
	}//end resolver()

	/**
	 * A mapper double that reproduces MagicMapper::countSearchObjects().
	 *
	 * It reads the reference the way the real mapper reads it, casts it the way
	 * the real mapper casts it, and answers `0` when the cast names no register
	 * or schema, which is the real mapper's logged-and-swallowed branch.
	 *
	 * @return MagicMapper
	 */
	private function countingMapper(): MagicMapper {
		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['countSearchObjects'])
			->getMock();

		$mapper->method('countSearchObjects')->willReturnCallback(
			function (array $query = []): int {
				$registerId = ($query['@self']['register'] ?? $query['_register'] ?? null);
				$schemaId = ($query['@self']['schema'] ?? $query['_schema'] ?? null);

				if ($registerId === null || $schemaId === null) {
					return 0;
				}

				// MagicMapper's own cast, reproduced. A slug, a uuid and an
				// empty string all land on 0, find(0) throws, the throw is
				// logged, and the count is 0.
				if ((int)$registerId !== self::REGISTER_ID || (int)$schemaId !== self::SCHEMA_ID) {
					return 0;
				}

				return self::REAL_COUNT;
			}
		);

		return $mapper;
	}//end countingMapper()

	/**
	 * A query handler over the counting mapper, with the resolver wired.
	 *
	 * @param MagicMapper                  $mapper   The object mapper double.
	 * @param SearchReferenceResolver|null $resolver The resolver, or null for the pre-fix wiring.
	 *
	 * @return QueryHandler
	 */
	private function queryHandler(MagicMapper $mapper, ?SearchReferenceResolver $resolver): QueryHandler {
		return new QueryHandler(
			$mapper,
			$this->createMock(GetObject::class),
			$this->createMock(RenderObject::class),
			$this->createMock(SearchQueryHandler::class),
			$this->createMock(FacetHandler::class),
			$this->createMock(PerformanceOptimizationHandler::class),
			$this->createMock(ContentSearchHandler::class),
			$this->createMock(IAppContainer::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRequest::class),
			null,
			null,
			$resolver
		);
	}//end queryHandler()

	/**
	 * A search query handler with the resolver wired.
	 *
	 * @return SearchQueryHandler
	 */
	private function searchQueryHandler(): SearchQueryHandler {
		return new SearchQueryHandler(
			$this->createMock(ViewScopeApplier::class),
			$this->schemaMapper(),
			$this->createMock(SettingsService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IRequest::class),
			$this->createMock(SearchTrailService::class),
			null,
			null,
			null,
			$this->resolver()
		);
	}//end searchQueryHandler()

	/**
	 * The defect, asserted from the caller: a count over a slug-referenced
	 * register and schema returns the real count, not zero.
	 *
	 * dossiq persisted a usage right as `false` from exactly this zero.
	 *
	 * @return void
	 */
	public function testACountOverSlugReferencesCountsTheObjects(): void {
		$count = $this->queryHandler(
			mapper: $this->countingMapper(),
			resolver: $this->resolver()
		)->countSearchObjects(
			query: ['@self' => ['register' => 'zaken', 'schema' => 'zaak']],
			_multitenancy: false
		);

		$this->assertSame(
			self::REAL_COUNT,
			$count,
			'a slug must count the objects that are there, never report zero of them'
		);
	}//end testACountOverSlugReferencesCountsTheObjects()

	/**
	 * The same call without the resolver is the bug, which proves the double
	 * can still produce it and is therefore able to fail.
	 *
	 * @return void
	 */
	public function testWithoutTheResolverTheSameCallStillCountsZero(): void {
		$count = $this->queryHandler(
			mapper: $this->countingMapper(),
			resolver: null
		)->countSearchObjects(
			query: ['@self' => ['register' => 'zaken', 'schema' => 'zaak']],
			_multitenancy: false
		);

		$this->assertSame(0, $count, 'the double must reproduce the int-cast, not stub it away');
	}//end testWithoutTheResolverTheSameCallStillCountsZero()

	/**
	 * A reference that names no register is refused, not answered with zero.
	 *
	 * @return void
	 */
	public function testAnUnresolvableRegisterReferenceIsRefused(): void {
		$this->expectException(RegisterNotFoundException::class);

		$this->queryHandler(
			mapper: $this->countingMapper(),
			resolver: $this->resolver()
		)->countSearchObjects(
			query: ['@self' => ['register' => 'no-such-register', 'schema' => 'zaak']],
			_multitenancy: false
		);
	}//end testAnUnresolvableRegisterReferenceIsRefused()

	/**
	 * A schema reference that names nothing is refused by name too.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaReferenceIsRefused(): void {
		$this->expectException(SchemaNotFoundException::class);

		$this->queryHandler(
			mapper: $this->countingMapper(),
			resolver: $this->resolver()
		)->countSearchObjects(
			query: ['@self' => ['register' => 19, 'schema' => 'no-such-schema']],
			_multitenancy: false
		);
	}//end testAnUnresolvableSchemaReferenceIsRefused()

	/**
	 * A numeric id is passed through without asking the database anything, so
	 * the hot path costs no extra query.
	 *
	 * @return void
	 */
	public function testANumericIdCostsNoLookup(): void {
		$registerMapper = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$registerMapper->expects($this->never())->method('find');

		$schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemaMapper->expects($this->never())->method('find');

		$resolver = new SearchReferenceResolver(
			$registerMapper,
			$schemaMapper,
			$this->createMock(LoggerInterface::class)
		);

		$query = $resolver->normaliseQuery(
			query: ['@self' => ['register' => self::REGISTER_ID, 'schema' => (string)self::SCHEMA_ID]]
		);

		$this->assertSame(self::REGISTER_ID, $query['@self']['register']);
		$this->assertSame(self::SCHEMA_ID, $query['@self']['schema'], 'a numeric string is an id, not a slug');
	}//end testANumericIdCostsNoLookup()

	/**
	 * An empty reference says nothing, so it filters nothing and the global
	 * fallbacks stay reachable. That is the one case where `0` and `null`
	 * differ and `null` was always what was meant.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceDropsTheFilterRatherThanScopingToZero(): void {
		$query = $this->resolver()->normaliseQuery(
			query: ['@self' => ['register' => '  '], '_search' => 'vergunning']
		);

		$this->assertArrayNotHasKey(
			'register',
			$query['@self'],
			'an empty register reference must not become a filter on register 0'
		);
		$this->assertSame('vergunning', $query['_search']);
	}//end testAnEmptyReferenceDropsTheFilterRatherThanScopingToZero()

	/**
	 * A list of schema references resolves each member, and refuses the one it
	 * cannot resolve rather than silently dropping it from the search.
	 *
	 * @return void
	 */
	public function testAListResolvesEveryMemberAndRefusesTheOneItCannot(): void {
		$query = $this->resolver()->normaliseQuery(query: ['_schemas' => ['zaak', self::SCHEMA_ID]]);
		$this->assertSame([self::SCHEMA_ID, self::SCHEMA_ID], $query['_schemas']);

		$this->expectException(SchemaNotFoundException::class);
		$this->resolver()->normaliseQuery(query: ['_schemas' => ['zaak', 'no-such-schema']]);
	}//end testAListResolvesEveryMemberAndRefusesTheOneItCannot()

	/**
	 * A list key spelled as a single string is left exactly as it is.
	 *
	 * `_schemas=1,2` from a URL is a shape the mapper ignores today. Resolving
	 * it would refuse a request that used to be answered, and that is a
	 * separate decision from this one.
	 *
	 * @return void
	 */
	public function testAListKeySpelledAsAStringIsLeftAlone(): void {
		$query = $this->resolver()->normaliseQuery(query: ['_schemas' => '1,2']);

		$this->assertSame('1,2', $query['_schemas']);
	}//end testAListKeySpelledAsAStringIsLeftAlone()

	/**
	 * The builder, which is where the cast lived: a slug reaches `@self` as the
	 * id it names.
	 *
	 * @return void
	 */
	public function testTheBuilderResolvesASlugToItsId(): void {
		$query = $this->searchQueryHandler()->buildSearchQuery(
			requestParams: ['_limit' => '20'],
			register: 'zaken',
			schema: 'zaak'
		);

		$this->assertSame(self::REGISTER_ID, $query['@self']['register']);
		$this->assertSame(self::SCHEMA_ID, $query['@self']['schema']);
	}//end testTheBuilderResolvesASlugToItsId()

	/**
	 * The builder refuses a reference that names nothing, instead of building a
	 * query scoped to register 0 and letting the caller read the empty page as
	 * an answer.
	 *
	 * @return void
	 */
	public function testTheBuilderRefusesAReferenceThatNamesNothing(): void {
		$this->expectException(RegisterNotFoundException::class);

		$this->searchQueryHandler()->buildSearchQuery(
			requestParams: [],
			register: 'no-such-register',
			schema: 'zaak'
		);
	}//end testTheBuilderRefusesAReferenceThatNamesNothing()
}//end class
