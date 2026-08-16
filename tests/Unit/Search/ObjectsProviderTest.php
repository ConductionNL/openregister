<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Search\ObjectsProvider;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\FilterDefinition;
use OCP\Search\IFilter;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ObjectsProviderTest extends TestCase {
	private ObjectsProvider $provider;
	private IL10N&MockObject $l10n;
	private IURLGenerator&MockObject $urlGenerator;
	private ObjectService&MockObject $objectService;
	private LoggerInterface&MockObject $logger;
	private DeepLinkRegistryService&MockObject $deepLinkRegistry;
	private SchemaMapper&MockObject $schemaMapper;

	protected function setUp(): void {
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(function (string $text, $args = []) {
			if (is_array($args) && count($args) > 0) {
				return vsprintf($text, $args);
			}
			return $text;
		});

		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->deepLinkRegistry = $this->createMock(DeepLinkRegistryService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		// Default: no schema opts out of search.
		$this->schemaMapper->method('findNonSearchableIds')->willReturn([]);
		$this->schemaMapper->method('findSearchableIds')->willReturn([1, 2, 3]);

		$this->provider = new ObjectsProvider(
			$this->l10n,
			$this->urlGenerator,
			$this->objectService,
			$this->logger,
			$this->deepLinkRegistry,
			$this->schemaMapper,
			$this->createMock(RegisterMapper::class)
		);
	}

	/**
	 * Build a mocked ISearchQuery returning the given filters/limit/cursor.
	 *
	 * @param array<string, string> $filters Filter name => value.
	 */
	private function mockQuery(array $filters, int $limit = 25, $cursor = null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getFilter')->willReturnCallback(function (string $name) use ($filters) {
			if (array_key_exists($name, $filters) === false) {
				return null;
			}
			$filter = $this->createMock(IFilter::class);
			$filter->method('get')->willReturn($filters[$name]);
			return $filter;
		});
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);
		return $query;
	}

	// --- Provider identity -------------------------------------------------

	public function testGetId(): void {
		$this->assertSame('openregister_objects', $this->provider->getId());
	}

	public function testGetName(): void {
		$this->assertSame('Open Register Objects', $this->provider->getName());
	}

	public function testGetOrder(): void {
		$this->assertSame(10, $this->provider->getOrder('some.route', []));
	}

	public function testGetSupportedFilters(): void {
		$filters = $this->provider->getSupportedFilters();
		$this->assertContains('term', $filters);
		$this->assertContains('since', $filters);
		$this->assertContains('until', $filters);
		$this->assertContains('person', $filters);
		$this->assertContains('register', $filters);
		$this->assertContains('schema', $filters);
	}

	public function testGetAlternateIds(): void {
		$this->assertSame([], $this->provider->getAlternateIds());
	}

	public function testGetCustomFilters(): void {
		$filters = $this->provider->getCustomFilters();
		$this->assertCount(2, $filters);
		$this->assertInstanceOf(FilterDefinition::class, $filters[0]);
		$this->assertInstanceOf(FilterDefinition::class, $filters[1]);
	}

	// --- Empty / short-circuit --------------------------------------------

	public function testSearchWithNoResults(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$this->objectService->method('searchObjectsPaginated')
			->willReturn(['results' => [], 'total' => 0]);

		$result = $this->provider->search($user, $query);

		$this->assertInstanceOf(SearchResult::class, $result);
	}

	// --- Delegation / RBAC contract ---------------------------------------

	public function testSearchAlwaysDelegatesWithRbacAndMultitenancyTrue(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'test search']);

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return $q['_search'] === 'test search' && $q['_limit'] === 25 && $q['_offset'] === 0;
				}),
				$this->isTrue(),  // _rbac MUST always be true
				$this->isTrue()   // _multitenancy MUST always be true
			)
			->willReturn(['results' => [], 'total' => 0]);

		$this->provider->search($user, $query);
	}

	public function testSearchProviderAppliesNoSecondAccessFilter(): void {
		// The provider must pass results through verbatim — it must not
		// drop objects the pipeline returned (no second access filter).
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'Jansen']);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['title' => 'Client Jansen', '@self' => ['id' => 'c1', 'register' => 1, 'schema' => 2]],
			],
			'total' => 1,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/c1');

		$result = $this->provider->search($user, $query);
		// The granted client object is present; the pipeline already
		// excluded anything the user may not read.
		$this->assertCount(1, $result->jsonSerialize()['entries']);
	}

	public function testSearchFailsSoftOnPipelineError(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'boom']);

		$this->objectService->method('searchObjectsPaginated')
			->willThrowException(new \RuntimeException('register broken'));

		$result = $this->provider->search($user, $query);
		$this->assertInstanceOf(SearchResult::class, $result);
		$this->assertSame([], $result->jsonSerialize()['entries']);
	}

	public function testSearchWithRegisterAndSchemaFilters(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['register' => '1', 'schema' => '2']);

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return ($q['@self']['register'] ?? null) === 1
						&& ($q['@self']['schema'] ?? null) === 2;
				}),
				$this->isTrue(),
				$this->isTrue()
			)
			->willReturn(['results' => [], 'total' => 0]);

		$this->provider->search($user, $query);
	}

	public function testSearchWithDateFilters(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['since' => '2024-01-01', 'until' => '2024-12-31']);

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return ($q['@self']['created']['$gte'] ?? null) === '2024-01-01'
						&& ($q['@self']['created']['$lte'] ?? null) === '2024-12-31';
				}),
				$this->isTrue(),
				$this->isTrue()
			)
			->willReturn(['results' => [], 'total' => 0]);

		$this->provider->search($user, $query);
	}

	public function testSearchWithUntilFilterOnly(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['until' => '2024-12-31']);

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return ($q['@self']['created']['$lte'] ?? null) === '2024-12-31'
						&& !isset($q['@self']['created']['$gte']);
				}),
				$this->isTrue(),
				$this->isTrue()
			)
			->willReturn(['results' => [], 'total' => 0]);

		$this->provider->search($user, $query);
	}

	// --- Schema searchable opt-out ----------------------------------------

	public function testNonSearchableSchemasConstrainedToAllowList(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'x']);

		// Schema 9 opted out; allow-list is 1,2,3.
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findNonSearchableIds')->willReturn([9]);
		$schemaMapper->method('findSearchableIds')->willReturn([1, 2, 3]);

		$provider = new ObjectsProvider(
			$this->l10n,
			$this->urlGenerator,
			$this->objectService,
			$this->logger,
			$this->deepLinkRegistry,
			$schemaMapper,
			$this->createMock(RegisterMapper::class)
		);

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return ($q['@self']['schema'] ?? null) === [1, 2, 3];
				}),
				$this->isTrue(),
				$this->isTrue()
			)
			->willReturn(['results' => [], 'total' => 0]);

		$provider->search($user, $query);
	}

	public function testExplicitSchemaFilterCannotBypassOptOut(): void {
		$user = $this->createMock(IUser::class);
		// Explicitly target schema 17, which is non-searchable.
		$query = $this->mockQuery(['schema' => '17', 'term' => 'reorganisatie']);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findNonSearchableIds')->willReturn([17]);
		$schemaMapper->method('findSearchableIds')->willReturn([1, 2]);

		$provider = new ObjectsProvider(
			$this->l10n,
			$this->urlGenerator,
			$this->objectService,
			$this->logger,
			$this->deepLinkRegistry,
			$schemaMapper,
			$this->createMock(RegisterMapper::class)
		);

		// Opt-out wins: pipeline must NOT be queried at all.
		$this->objectService->expects($this->never())->method('searchObjectsPaginated');

		$result = $provider->search($user, $query);
		$this->assertSame([], $result->jsonSerialize()['entries']);
	}

	public function testDefaultSchemasRemainSearchable(): void {
		// No opt-out: query carries no schema allow-list constraint.
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'x']);

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return isset($q['@self']['schema']) === false;
				}),
				$this->isTrue(),
				$this->isTrue()
			)
			->willReturn(['results' => [], 'total' => 0]);

		$this->provider->search($user, $query);
	}

	// --- Labeling matrix ---------------------------------------------------

	public function testClaimedResultCarriesAppLabelAndRoundedIcon(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['title' => 'Acme', '@self' => ['id' => 'u1', 'register' => 1, 'schema' => 2]],
			],
			'total' => 1,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn('/apps/pipelinq/#/clients/u1');
		$this->deepLinkRegistry->method('resolveIcon')->willReturn('icon-pipelinq');
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn('Pipelinq');

		$result = $this->provider->search($user, $query);
		$entries = $result->jsonSerialize()['entries'];
		$this->assertStringStartsWith('Pipelinq · ', $entries[0]->jsonSerialize()['subline']);
		$this->assertTrue($entries[0]->jsonSerialize()['rounded']);
		$this->assertSame('icon-pipelinq', $entries[0]->jsonSerialize()['icon']);
	}

	public function testDisplayNameDefaultsAreOwnerLabel(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['title' => 'Case 1', '@self' => ['id' => 'u2', 'register' => 1, 'schema' => 2]],
			],
			'total' => 1,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn('/apps/procest/#/cases/u2');
		$this->deepLinkRegistry->method('resolveIcon')->willReturn('icon-procest');
		// No displayName set → service returns appId.
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn('procest');

		$result = $this->provider->search($user, $query);
		$entries = $result->jsonSerialize()['entries'];
		$this->assertStringStartsWith('procest · ', $entries[0]->jsonSerialize()['subline']);
	}

	public function testUnclaimedResultKeepsOpenRegisterLabel(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['title' => 'Audit', '@self' => ['id' => 'u3', 'register' => 1, 'schema' => 5]],
			],
			'total' => 1,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/u3');

		$result = $this->provider->search($user, $query);
		$entries = $result->jsonSerialize()['entries'];
		$serialised = $entries[0]->jsonSerialize();
		$this->assertStringStartsWith('Open Register · ', $serialised['subline']);
		$this->assertSame('icon-openregister', $serialised['icon']);
		$this->assertFalse($serialised['rounded']);
	}

	public function testUnclaimedResultUrlFallsBackToObjectsShow(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['title' => 'Audit', '@self' => ['id' => 'u3', 'register' => 1, 'schema' => 5]],
			],
			'total' => 1,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->expects($this->once())
			->method('linkToRoute')
			->with('openregister.objects.show', $this->anything())
			->willReturn('/objects/u3');

		$result = $this->provider->search($user, $query);
		$this->assertCount(1, $result->jsonSerialize()['entries']);
	}

	public function testMixedPageLabelsEachEntryByItsOwner(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['title' => 'Client', '@self' => ['id' => 'a', 'register' => 1, 'schema' => 2]],
				['title' => 'Case', '@self' => ['id' => 'b', 'register' => 3, 'schema' => 4]],
				['title' => 'Audit', '@self' => ['id' => 'c', 'register' => 3, 'schema' => 5]],
			],
			'total' => 3,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturnCallback(function (int $r, int $s) {
			return $s === 5 ? null : 'icon-app';
		});
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturnCallback(function (int $r, int $s) {
			if ($s === 2) {
				return 'Pipelinq';
			}
			if ($s === 4) {
				return 'procest';
			}
			return null;
		});
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/x');

		$result = $this->provider->search($user, $query);
		$entries = $result->jsonSerialize()['entries'];
		$this->assertStringStartsWith('Pipelinq · ', $entries[0]->jsonSerialize()['subline']);
		$this->assertStringStartsWith('procest · ', $entries[1]->jsonSerialize()['subline']);
		$this->assertStringStartsWith('Open Register · ', $entries[2]->jsonSerialize()['subline']);
	}

	// --- Excerpts ----------------------------------------------------------

	public function testExcerptAroundMatchedTerm(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'Jansen']);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				[
					'title' => 'Obj',
					'notes' => 'afspraak met mevrouw Jansen over de vergunning volgende week',
					'@self' => ['id' => 'e1', 'register' => 1, 'schema' => 2],
				],
			],
			'total' => 1,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/e1');

		$subline = $this->provider->search($user, $query)->jsonSerialize()['entries'][0]->jsonSerialize()['subline'];
		$this->assertStringContainsString('Jansen', $subline);
	}

	public function testExcerptFallsBackToSummaryWhenMatchNotInString(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => '42']);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				[
					'title' => 'Obj',
					'amount' => 42,
					'summary' => 'Kapvergunning eik Kerkstraat',
					'@self' => ['id' => 'e2', 'register' => 1, 'schema' => 2],
				],
			],
			'total' => 1,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/e2');

		$subline = $this->provider->search($user, $query)->jsonSerialize()['entries'][0]->jsonSerialize()['subline'];
		$this->assertStringEndsWith('Kapvergunning eik Kerkstraat', $subline);
	}

	public function testExcerptMultibyteSafe(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'café']);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				[
					'title' => 'Obj',
					'notes' => str_repeat('é', 80) . ' bezoek aan het café in de buurt ' . str_repeat('ü', 80),
					'@self' => ['id' => 'e3', 'register' => 1, 'schema' => 2],
				],
			],
			'total' => 1,
		]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/e3');

		$subline = $this->provider->search($user, $query)->jsonSerialize()['entries'][0]->jsonSerialize()['subline'];
		$this->assertStringContainsString('café', $subline);
		// Ellipsis present on both truncated edges.
		$this->assertStringContainsString('…', $subline);
	}

	// --- Pagination --------------------------------------------------------

	public function testFullFirstPageReturnsCursor(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'x'], 25, null);

		$results = [];
		for ($i = 0; $i < 25; $i++) {
			$results[] = ['title' => 'O' . $i, '@self' => ['id' => 'p' . $i, 'register' => 1, 'schema' => 2]];
		}
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => $results, 'total' => 60]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/p');

		$serialised = $this->provider->search($user, $query)->jsonSerialize();
		$this->assertCount(25, $serialised['entries']);
		$this->assertSame(25, $serialised['cursor']);
		$this->assertTrue($serialised['isPaginated']);
	}

	public function testSecondPageUsesCursorOffset(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'x'], 25, '25');

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return $q['_offset'] === 25 && $q['_limit'] === 25;
				}),
				$this->isTrue(),
				$this->isTrue()
			)
			->willReturn(['results' => [], 'total' => 60]);

		$this->provider->search($user, $query);
	}

	public function testShortPageCompletesResult(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'x'], 25, null);

		$results = [
			['title' => 'O1', '@self' => ['id' => 's1', 'register' => 1, 'schema' => 2]],
		];
		$this->objectService->method('searchObjectsPaginated')->willReturn(['results' => $results, 'total' => 1]);
		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/s1');

		$serialised = $this->provider->search($user, $query)->jsonSerialize();
		$this->assertCount(1, $serialised['entries']);
		$this->assertFalse($serialised['isPaginated']);
	}

	// --- ObjectEntity normalisation + title fallbacks ---------------------

	public function testSearchWithObjectEntityResults(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$objectEntity = $this->createMock(ObjectEntity::class);
		$objectEntity->method('jsonSerialize')->willReturn([
			'title' => 'Entity Object',
			'@self' => ['id' => 'uuid-456', 'register' => 1, 'schema' => 2],
		]);

		$this->objectService->method('searchObjectsPaginated')
			->willReturn(['results' => [$objectEntity], 'total' => 1]);

		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/openregister/objects/uuid-456');

		$result = $this->provider->search($user, $query);
		$entries = $result->jsonSerialize()['entries'];
		$this->assertSame('Entity Object', $entries[0]->jsonSerialize()['title']);
	}

	public function testSearchResultWithNameFallback(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['@self' => ['id' => 'uuid-789', 'register' => 1, 'schema' => 2, 'name' => 'My Object Name']],
			],
			'total' => 1,
		]);

		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/uuid-789');

		$result = $this->provider->search($user, $query);
		$this->assertSame('My Object Name', $result->jsonSerialize()['entries'][0]->jsonSerialize()['title']);
	}

	public function testSearchResultWithUuidFallback(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery([]);

		$this->objectService->method('searchObjectsPaginated')->willReturn([
			'results' => [
				['@self' => ['id' => 'uuid-fallback', 'register' => 1, 'schema' => 2]],
			],
			'total' => 1,
		]);

		$this->deepLinkRegistry->method('resolveUrl')->willReturn(null);
		$this->deepLinkRegistry->method('resolveIcon')->willReturn(null);
		$this->deepLinkRegistry->method('resolveDisplayName')->willReturn(null);
		$this->urlGenerator->method('linkToRoute')->willReturn('/objects/uuid-fallback');

		$result = $this->provider->search($user, $query);
		$this->assertSame('uuid-fallback', $result->jsonSerialize()['entries'][0]->jsonSerialize()['title']);
	}

	/**
	 * The change itself: the provider must ASK for file text.
	 *
	 * Without `_content_search` the pipeline searches object metadata only, so
	 * a term living solely inside an attached PDF finds nothing while
	 * OpenRegister holds it indexed. Asserted on the flag reaching the
	 * pipeline, because that is the whole of the change — the fan-out itself
	 * is ContentSearchHandler's tested behaviour, not the provider's.
	 */
	public function testSearchAsksThePipelineForAttachedFileText(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'aanbesteding']);

		$this->objectService->expects($this->once())
			->method('searchObjectsPaginated')
			->with(
				$this->callback(function (array $q) {
					return ($q['_content_search'] ?? null) === true;
				}),
				$this->isTrue(),
				$this->isTrue()
			)
			->willReturn(['results' => [], 'total' => 0]);

		$this->provider->search($user, $query);
	}

	/**
	 * Widening the MATCH must not widen the ENTITLEMENT.
	 *
	 * `_content_search` is only safe because the chunk fan-out receives the
	 * same `_rbac` / `_multitenancy` flags as the metadata arm — a chunk hit
	 * on an object the caller may not read is filtered by the same pipeline.
	 * If a later edit ever turned content search on while relaxing either
	 * flag, file text would become a side channel around object visibility.
	 * That is the one way this change could become a disclosure, so it is
	 * pinned rather than reasoned about.
	 */
	public function testContentSearchNeverTravelsWithRelaxedGuards(): void {
		$user = $this->createMock(IUser::class);
		$query = $this->mockQuery(['term' => 'aanbesteding']);

		$seen = [];
		$this->objectService->method('searchObjectsPaginated')
			->willReturnCallback(
				function (array $q, bool $rbac = true, bool $multitenancy = true) use (&$seen) {
					$seen = ['content' => ($q['_content_search'] ?? null), 'rbac' => $rbac, 'mt' => $multitenancy];
					return ['results' => [], 'total' => 0];
				}
			);

		$this->provider->search($user, $query);

		$this->assertTrue($seen['content'], 'content search must be on');
		$this->assertTrue($seen['rbac'], 'and RBAC must still be on alongside it');
		$this->assertTrue($seen['mt'], 'and so must multitenancy');
	}

	/**
	 * A schema opted out of search stays opted out with content search on.
	 *
	 * The provider narrows by `searchable = true`. Content search widens the
	 * match, and must not reach around that narrowing — otherwise a schema
	 * excluded from search would still surface through the text of its
	 * attached files.
	 */
	public function testAnOptedOutSchemaIsNotReachableViaFileText(): void {
		// A provider of its own, because setUp() stubs findNonSearchableIds()
		// to [] and a second stub on the same mock does not replace the first.
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findNonSearchableIds')->willReturn([42]);
		$schemaMapper->method('findSearchableIds')->willReturn([1, 2, 3]);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->expects($this->never())->method('searchObjectsPaginated');

		$provider = new ObjectsProvider(
			$this->l10n,
			$this->urlGenerator,
			$objectService,
			$this->logger,
			$this->deepLinkRegistry,
			$schemaMapper,
			$this->createMock(RegisterMapper::class)
		);

		$result = $provider->search(
			$this->createMock(IUser::class),
			$this->mockQuery(['term' => 'aanbesteding', 'schema' => '42'])
		);

		$this->assertSame([], $result->jsonSerialize()['entries']);
	}
}
