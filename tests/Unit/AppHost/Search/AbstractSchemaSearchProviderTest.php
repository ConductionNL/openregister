<?php

/**
 * Unit tests for AbstractSchemaSearchProvider.
 *
 * Exercised via a minimal concrete test-double subclass — the only
 * configuration surface a consuming app has: getRegisterSlug()/
 * getSchemaSlug().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Search;

use OCA\OpenRegister\AppHost\Search\AbstractSchemaSearchProvider;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Minimal concrete subclass — the only configuration surface a consuming
 * app has: two slug methods, nothing else.
 */
final class TestLeadSearchProvider extends AbstractSchemaSearchProvider {
	public function getRegisterSlug(): string {
		return 'pipelinq';
	}//end getRegisterSlug()

	public function getSchemaSlug(): string {
		return 'lead';
	}//end getSchemaSlug()
}//end class

/**
 * Tests for AbstractSchemaSearchProvider.
 *
 * @covers \OCA\OpenRegister\AppHost\Search\AbstractSchemaSearchProvider
 */
class AbstractSchemaSearchProviderTest extends TestCase {

	/**
	 * Mock object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objectService;

	/**
	 * Mock schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * Mock register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper $registerMapper;

	/**
	 * The result formatter used by the provider under test.
	 *
	 * @var ObjectSearchResultFormatter
	 */
	private ObjectSearchResultFormatter $resultFormatter;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $url) => 'https://cloud.example.com' . ($url === '/' ? '/' : $url));
		$urlGenerator->method('linkToRoute')->willReturn('/objects/x');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text) => $text);

		$deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
		$deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$deepLinkRegistry->method('resolveDisplayName')->willReturn(null);

		$this->objectService = $this->createMock(ObjectService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);

		$previewFormatter = new ObjectPreviewFormatter(
			$urlGenerator,
			$l10n,
			$this->objectService,
			$deepLinkRegistry,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(LoggerInterface::class)
		);

		$this->resultFormatter = new ObjectSearchResultFormatter($urlGenerator, $deepLinkRegistry, $previewFormatter);
	}//end setUp()

	/**
	 * Build the provider under test.
	 *
	 * @return TestLeadSearchProvider
	 */
	private function buildProvider(): TestLeadSearchProvider {
		return new TestLeadSearchProvider(
			$this->objectService,
			$this->resultFormatter,
			$this->registerMapper,
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class)
		);
	}//end buildProvider()

	/**
	 * Build a Register entity with a fixed id.
	 *
	 * @param int $id The register id.
	 *
	 * @return Register
	 */
	private function makeRegister(int $id): Register {
		$register = new Register();
		$register->setId($id);
		return $register;
	}//end makeRegister()

	/**
	 * Build a Schema entity with a fixed id, title, and smartPickerEnabled flag.
	 *
	 * @param int $id The schema id.
	 * @param bool $smartPickerEnabled The smartPickerEnabled flag.
	 * @param string $title The schema title.
	 *
	 * @return Schema
	 */
	private function makeSchema(int $id, bool $smartPickerEnabled, string $title = 'Lead'): Schema {
		$schema = new Schema();
		$schema->setSmartPickerEnabled($smartPickerEnabled);
		$schema->setTitle($title);
		$schema->setId($id);
		return $schema;
	}//end makeSchema()

	/**
	 * Build a mocked ISearchQuery.
	 *
	 * @param string $term The search term.
	 * @param int $limit The page limit.
	 * @param int|string|null $cursor The pagination cursor.
	 *
	 * @return ISearchQuery
	 */
	private function mockQuery(string $term = '', int $limit = 25, $cursor = null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);
		return $query;
	}//end mockQuery()

	// --- Computed id ------------------------------------------------------------

	/**
	 * Test getId() derives the id from the slugs.
	 *
	 * @return void
	 */
	public function testGetIdIsDerivedFromSlugs(): void {
		$this->assertSame('openregister_objects_pipelinq_lead', $this->buildProvider()->getId());
	}//end testGetIdIsDerivedFromSlugs()

	/**
	 * Test getName() reads the schema's current title live.
	 *
	 * @return void
	 */
	public function testGetNameReadsSchemaTitleLive(): void {
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, true, 'Lead'));
		$this->assertSame('Lead', $this->buildProvider()->getName());
	}//end testGetNameReadsSchemaTitleLive()

	// --- Results confined to the configured schema -------------------------------

	/**
	 * Test search() forces @self.register and @self.schema into the query.
	 *
	 * @return void
	 */
	public function testSearchForcesRegisterAndSchemaIntoQuery(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, true));

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return ($q['@self']['register'] ?? null) === 5
						&& ($q['@self']['schema'] ?? null) === 12;
				}),
				$this->isTrue(),
				$this->isTrue()
			)
			->willReturn(['results' => [], 'total' => 0]);

		$this->buildProvider()->search($this->createMock(IUser::class), $this->mockQuery('lead term'));
	}//end testSearchForcesRegisterAndSchemaIntoQuery()

	/**
	 * Test search() results only ever belong to the configured schema — the
	 * formatter is fed rows the pipeline already scoped, and the provider
	 * applies no second access filter.
	 *
	 * @return void
	 */
	public function testSearchResultsAreScopedToConfiguredSchema(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, true));

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['title' => 'Lead A', '@self' => ['id' => 'a', 'register' => 5, 'schema' => 12]],
			],
			'total' => 1,
		]);

		$result = $this->buildProvider()->search($this->createMock(IUser::class), $this->mockQuery());
		$entries = $result->jsonSerialize()['entries'];

		$this->assertCount(1, $entries);
		$this->assertSame('Lead A', $entries[0]->jsonSerialize()['title']);
	}//end testSearchResultsAreScopedToConfiguredSchema()

	// --- RBAC / multitenancy parity with the generic provider --------------------

	/**
	 * Test search() always delegates with _rbac and _multitenancy true,
	 * matching ObjectsProvider's contract exactly.
	 *
	 * @return void
	 */
	public function testSearchAlwaysDelegatesWithRbacAndMultitenancyTrue(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, true));

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with($this->anything(), $this->isTrue(), $this->isTrue())
			->willReturn(['results' => [], 'total' => 0]);

		$this->buildProvider()->search($this->createMock(IUser::class), $this->mockQuery('term'));
	}//end testSearchAlwaysDelegatesWithRbacAndMultitenancyTrue()

	// --- smartPickerEnabled gate --------------------------------------------------

	/**
	 * Test search() returns an empty but completed SearchResult, rather than
	 * an error, when smartPickerEnabled is false.
	 *
	 * @return void
	 */
	public function testDisabledFlagReturnsEmptyCompletedResult(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, false));

		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$result = $this->buildProvider()->search($this->createMock(IUser::class), $this->mockQuery('term'));
		$serialised = $result->jsonSerialize();

		$this->assertSame([], $serialised['entries']);
		$this->assertFalse($serialised['isPaginated']);
	}//end testDisabledFlagReturnsEmptyCompletedResult()

	/**
	 * Test flipping the flag back on restores normal search behavior.
	 *
	 * @return void
	 */
	public function testFlagBackOnRestoresSearchBehavior(): void {
		$this->registerMapper->method('find')->willReturn($this->makeRegister(5));

		// First call: disabled.
		$this->schemaMapper->method('find')->willReturn($this->makeSchema(12, false));
		$disabledResult = $this->buildProvider()->search($this->createMock(IUser::class), $this->mockQuery());
		$this->assertSame([], $disabledResult->jsonSerialize()['entries']);

		// Second call, fresh provider instance (flag now enabled) behaves normally.
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->makeSchema(12, true));

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('searchObjectsPaginated')->willReturn(['results' => [], 'total' => 0]);

		$provider = new TestLeadSearchProvider(
			$objectService,
			$this->resultFormatter,
			$this->registerMapper,
			$schemaMapper,
			$this->createMock(LoggerInterface::class)
		);

		$enabledResult = $provider->search($this->createMock(IUser::class), $this->mockQuery());
		$this->assertInstanceOf(\OCP\Search\SearchResult::class, $enabledResult);
	}//end testFlagBackOnRestoresSearchBehavior()
}//end class
