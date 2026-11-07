<?php

/**
 * Pins the schema-scoped search and reference providers on the paths their own tests leave open.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Reference\AbstractSchemaReferenceProvider;
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
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\AppHost\Reference\AbstractSchemaReferenceProvider
 * @covers \OCA\OpenRegister\AppHost\Search\AbstractSchemaSearchProvider
 * @covers \OCA\OpenRegister\AppHost\Search\SchemaSearchPage
 * @covers \OCA\OpenRegister\AppHost\SchemaScopedProvider
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter
 * @uses   \OCA\OpenRegister\Service\WriteCause
 * @uses   \OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter
 */
class SchemaScopedProvidersGoldenTest extends TestCase {
	private const UUID = '550e8400-e29b-41d4-a716-446655440000';

	private SchemaMapper&MockObject $schemaMapper;
	private RegisterMapper&MockObject $registerMapper;
	private ObjectService&MockObject $objects;
	private ObjectSearchResultFormatter $results;
	private ObjectPreviewFormatter $previews;
	private LoggerInterface&MockObject $logger;
	private IURLGenerator&MockObject $urls;

	protected function setUp(): void {
		$this->schemaMapper   = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->objects        = $this->createMock(ObjectService::class);
		$this->logger         = $this->createMock(LoggerInterface::class);
		$this->urls           = $this->createMock(IURLGenerator::class);
		$this->urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud.example.com' . $url);
		$this->urls->method('linkToRoute')->willReturn('/objects/x');
		$this->urls->method('imagePath')->willReturn('/app.svg');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$links = $this->createMock(DeepLinkRegistryService::class);

		$this->previews = new ObjectPreviewFormatter(
			$this->urls,
			$l10n,
			$this->objects,
			$links,
			$this->schemaMapper,
			$this->registerMapper,
			$this->createMock(LoggerInterface::class)
		);
		$this->results  = new ObjectSearchResultFormatter($this->urls, $links, $this->previews);
	}//end setUp()

	private function url(int $register, int $schema): string {
		return "https://cloud.example.com/apps/openregister/#/registers/$register/schemas/$schema/objects/" . self::UUID;
	}//end url()

	private function search(): AbstractSchemaSearchProvider {
		return new class($this->objects, $this->results, $this->registerMapper, $this->schemaMapper, $this->logger) extends AbstractSchemaSearchProvider {
			public function getRegisterSlug(): string {
				return 'crm';
			}

			public function getSchemaSlug(): string {
				return 'lead';
			}
		};
	}//end search()

	private function reference(?string $userId = 'alice'): AbstractSchemaReferenceProvider {
		return new class($this->previews, $this->registerMapper, $this->schemaMapper, $this->logger, $userId) extends AbstractSchemaReferenceProvider {
			public function getRegisterSlug(): string {
				return 'crm';
			}

			public function getSchemaSlug(): string {
				return 'lead';
			}
		};
	}//end reference()

	private function schema(bool $enabled = true, ?string $title = 'Leads'): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle($title);
		$schema->setSmartPickerEnabled($enabled);
		return $schema;
	}//end schema()

	private function scope(bool $enabled = true, ?string $title = 'Leads'): void {
		$register = new Register();
		$register->setId(3);
		$this->registerMapper->method('find')->willReturn($register);
		$this->schemaMapper->method('find')->willReturn($this->schema(enabled: $enabled, title: $title));
	}//end scope()

	private function query(string $term = 'acme', int $limit = 25, int|string|null $cursor = null): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);
		return $query;
	}//end query()

	/**
	 * The query the search provider hands over, for a given request.
	 *
	 * @return array<string, mixed>
	 */
	private function sentQuery(ISearchQuery $query): array {
		$sent = [];
		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query) use (&$sent): array {
				$sent = $query;
				return ['results' => []];
			}
		);
		$this->search()->search($this->createMock(IUser::class), $query);
		return $sent;
	}//end sentQuery()

	/**
	 * A search names register and schema, widens to file content and pages from the start.
	 */
	public function testTheWholeSearchQuery(): void {
		$this->scope();

		$this->assertSame(
			[
				'_search' => 'acme',
				'@self' => ['register' => 3, 'schema' => 7],
				'_content_search' => true,
				'_limit' => 25,
				'_offset' => 0,
			],
			$this->sentQuery($this->query())
		);
	}//end testTheWholeSearchQuery()

	/**
	 * An empty term searches without `_search`.
	 */
	public function testAnEmptyTermSendsNoSearchKey(): void {
		$this->scope();

		$this->assertArrayNotHasKey('_search', $this->sentQuery($this->query(term: '')));
	}//end testAnEmptyTermSendsNoSearchKey()

	/**
	 * The page size is the query's when that is positive and below the cap, else the cap.
	 *
	 * @dataProvider limits
	 */
	public function testThePageSize(int $asked, int $sent): void {
		$this->scope();

		$this->assertSame($sent, $this->sentQuery($this->query(limit: $asked))['_limit']);
	}//end testThePageSize()

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function limits(): array {
		return ['zero' => [0, 25], 'negative' => [-1, 25], 'small' => [5, 5], 'just below' => [24, 24], 'the cap' => [25, 25], 'above' => [90, 25]];
	}//end limits()

	/**
	 * A numeric cursor is the offset, never below zero; anything else starts at zero.
	 *
	 * @dataProvider cursors
	 */
	public function testTheOffset(int|string|null $cursor, int $offset): void {
		$this->scope();

		$this->assertSame($offset, $this->sentQuery($this->query(cursor: $cursor))['_offset']);
	}//end testTheOffset()

	/**
	 * @return array<string, array{0: int|string|null, 1: int}>
	 */
	public static function cursors(): array {
		return ['none' => [null, 0], 'number' => [50, 50], 'text number' => ['75', 75], 'negative' => [-5, 0], 'word' => ['next', 0]];
	}//end cursors()

	/**
	 * The search runs under RBAC and multitenancy, whoever the provider is.
	 */
	public function testTheSearchKeepsItsGuards(): void {
		$this->scope();
		$this->objects->expects($this->once())->method('searchObjectsPaginated')
			->with($this->anything(), true, true)
			->willReturn(['results' => []]);

		$this->search()->search($this->createMock(IUser::class), $this->query());
	}//end testTheSearchKeepsItsGuards()

	/**
	 * A failed search is an empty complete result with a warning.
	 */
	public function testAFailedSearchIsEmptyAndWarns(): void {
		$this->scope();
		$this->objects->method('searchObjectsPaginated')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[AbstractSchemaSearchProvider] Search failed for schema "{schema}", returning empty result: {error}',
			['schema' => 'lead', 'error' => 'boom']
		);

		$result = $this->search()->search($this->createMock(IUser::class), $this->query())->jsonSerialize();

		$this->assertSame('Leads', $result['name']);
		$this->assertSame([], $result['entries']);
		$this->assertFalse($result['isPaginated']);
	}//end testAFailedSearchIsEmptyAndWarns()

	/**
	 * A result set without results stays empty, and a missing key too.
	 */
	public function testNoResultsIsAnEmptyCompleteResult(): void {
		$this->scope();
		$this->objects->method('searchObjectsPaginated')->willReturnOnConsecutiveCalls(['results' => []], []);

		foreach ([1, 2] as $_) {
			$result = $this->search()->search($this->createMock(IUser::class), $this->query())->jsonSerialize();
			$this->assertSame([], $result['entries']);
		}
	}//end testNoResultsIsAnEmptyCompleteResult()

	/**
	 * Every result becomes an entry; a page below the limit is complete.
	 */
	public function testAShortPageIsComplete(): void {
		$this->scope();
		$this->objects->method('searchObjectsPaginated')->willReturn(['results' => [['id' => 'a', '@self' => ['name' => 'A']], ['id' => 'b', '@self' => ['name' => 'B']]]]);

		$result = $this->search()->search($this->createMock(IUser::class), $this->query(limit: 3))->jsonSerialize();

		$this->assertCount(2, $result['entries']);
		$this->assertFalse($result['isPaginated']);
	}//end testAShortPageIsComplete()

	/**
	 * The excerpt of an entry is cut around the search term.
	 */
	public function testTheEntryExcerptFollowsTheTerm(): void {
		$this->scope();
		$long = str_repeat('filler ', 60) . 'acme ' . str_repeat('filler ', 60);
		$this->objects->method('searchObjectsPaginated')->willReturn(['results' => [['id' => 'a', 'description' => $long]]]);

		$result = $this->search()->search($this->createMock(IUser::class), $this->query())->jsonSerialize();

		$this->assertStringContainsString('acme', $result['entries'][0]->jsonSerialize()['subline']);
	}//end testTheEntryExcerptFollowsTheTerm()

	/**
	 * A full page is paginated, with the next offset as its cursor.
	 */
	public function testAFullPageCarriesTheNextOffset(): void {
		$this->scope();
		$this->objects->method('searchObjectsPaginated')->willReturn(['results' => [['id' => 'a'], ['id' => 'b']]]);

		$result = $this->search()->search($this->createMock(IUser::class), $this->query(limit: 2, cursor: 10))->jsonSerialize();

		$this->assertTrue($result['isPaginated']);
		$this->assertSame(12, $result['cursor']);
	}//end testAFullPageCarriesTheNextOffset()

	/**
	 * An unresolvable register returns nothing and logs why; no search runs.
	 */
	public function testAnUnresolvableRegisterSearchesNothing(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema());
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('no register'));
		$this->objects->expects($this->never())->method('searchObjectsPaginated');
		$this->logger->expects($this->once())->method('debug')->with(
			'[AbstractSchemaSearchProvider] Failed to resolve register slug "{slug}": {error}',
			['slug' => 'crm', 'error' => 'no register']
		);

		$result = $this->search()->search($this->createMock(IUser::class), $this->query())->jsonSerialize();

		$this->assertSame('Leads', $result['name']);
		$this->assertSame([], $result['entries']);
	}//end testAnUnresolvableRegisterSearchesNothing()

	/**
	 * An unresolvable schema is tried once, logged once, and names the result after its slug.
	 */
	public function testAnUnresolvableSchemaIsTriedOnce(): void {
		$this->schemaMapper->expects($this->once())->method('find')->willThrowException(new RuntimeException('no schema'));
		$this->registerMapper->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('searchObjectsPaginated');
		$this->logger->expects($this->once())->method('debug')->with(
			'[AbstractSchemaSearchProvider] Failed to resolve schema slug "{slug}": {error}',
			['slug' => 'lead', 'error' => 'no schema']
		);

		$result = $this->search()->search($this->createMock(IUser::class), $this->query())->jsonSerialize();

		$this->assertSame('lead', $result['name']);
	}//end testAnUnresolvableSchemaIsTriedOnce()

	/**
	 * Slug lookups skip RBAC and the tenant filter, and the ids are read once per provider.
	 */
	public function testSlugsResolveOnceOutsideRbacAndTenancy(): void {
		$register = new Register();
		$register->setId(3);
		$this->registerMapper->expects($this->once())->method('find')
			->with('crm', false, false)->willReturn($register);
		$slugLookups = 0;
		$flags = [];
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string|int $id, ?array $extend, bool $rbac, bool $multitenancy) use (&$slugLookups, &$flags): Schema {
				$flags[] = [$rbac, $multitenancy];
				$slugLookups += (int)($id === 'lead');
				return $this->schema();
			}
		);
		$this->objects->method('searchObjectsPaginated')->willReturn(['results' => []]);

		$provider = $this->search();
		$provider->search($this->createMock(IUser::class), $this->query());
		$provider->search($this->createMock(IUser::class), $this->query());

		$this->assertSame(1, $slugLookups);
		$this->assertSame([[false, false]], array_values(array_unique($flags, SORT_REGULAR)));
	}//end testSlugsResolveOnceOutsideRbacAndTenancy()

	/**
	 * Name and title are the schema's, and the slug when the title is empty or the read fails.
	 */
	public function testTheNameFallsBackToTheSlug(): void {
		$this->schemaMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->schema(),
			$this->schema(title: null),
			$this->schema(title: ''),
			$this->schema(),
			$this->schema(title: ''),
			$this->throwException(new RuntimeException('gone'))
		);

		$search = $this->search();
		$this->assertSame('lead', $search->getName());
		$this->assertSame('lead', $search->getName());

		$reference = $this->reference();
		$this->assertSame('lead', $reference->getTitle());
		$this->assertSame('lead', $reference->getTitle());
	}//end testTheNameFallsBackToTheSlug()

	/**
	 * A flag read that fails counts as off.
	 */
	public function testAFailedFlagReadIsOff(): void {
		$this->schemaMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->schema(),
			$this->throwException(new RuntimeException('gone')),
			$this->schema()
		);
		$this->objects->expects($this->never())->method('searchObjectsPaginated');

		$result = $this->search()->search($this->createMock(IUser::class), $this->query())->jsonSerialize();

		$this->assertSame([], $result['entries']);
	}//end testAFailedFlagReadIsOff()

	/**
	 * The order is 10, whatever the route.
	 */
	public function testTheOrder(): void {
		$this->assertSame(10, $this->search()->getOrder('any', ['a' => 1]));
		$this->assertSame(10, $this->reference()->getOrder());
	}//end testTheOrder()

	/**
	 * Only a URL of this register and schema is looked up; a foreign one, an unparsable one and a closed flag are not.
	 */
	public function testAReferenceResolvesOnlyInItsOwnScope(): void {
		$this->scope();
		$this->objects->expects($this->once())->method('find')->with(self::UUID)->willReturn(null);

		$provider = $this->reference();
		$this->assertNull($provider->resolveReference($this->url(3, 8)));
		$this->assertNull($provider->resolveReference($this->url(4, 7)));
		$this->assertNull($provider->resolveReference('junk'));
		$this->assertNull($provider->resolveReference($this->url(3, 7)));
	}//end testAReferenceResolvesOnlyInItsOwnScope()

	/**
	 * The cache prefix is the parsed triple for an own URL, and the text itself otherwise.
	 */
	public function testTheCachePrefix(): void {
		$this->scope();

		$provider = $this->reference();
		$this->assertSame('3/7/' . self::UUID, $provider->getCachePrefix($this->url(3, 7)));
		$this->assertSame($this->url(3, 8), $provider->getCachePrefix($this->url(3, 8)));
		$this->assertSame('junk', $provider->getCachePrefix('junk'));
	}//end testTheCachePrefix()

	/**
	 * The reference provider's failed lookups carry its own name.
	 */
	public function testTheReferenceProviderNamesItselfInItsLogs(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('no schema'));
		$this->logger->expects($this->once())->method('debug')->with(
			'[AbstractSchemaReferenceProvider] Failed to resolve schema slug "{slug}": {error}',
			['slug' => 'lead', 'error' => 'no schema']
		);

		$this->assertFalse($this->reference()->matchReference('own'));
	}//end testTheReferenceProviderNamesItselfInItsLogs()

	/**
	 * A reference on an unresolvable register never matches, and logs under the provider's name.
	 */
	public function testAReferenceWithoutARegisterDoesNotMatch(): void {
		$this->schemaMapper->method('find')->willReturn($this->schema());
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('no register'));
		$this->logger->expects($this->once())->method('debug')->with(
			'[AbstractSchemaReferenceProvider] Failed to resolve register slug "{slug}": {error}',
			['slug' => 'crm', 'error' => 'no register']
		);

		$this->assertFalse($this->reference()->matchReference($this->url(3, 7)));
	}//end testAReferenceWithoutARegisterDoesNotMatch()

	/**
	 * Without a resolved schema the icon is the app's and no MDI lookup runs.
	 */
	public function testAnUnresolvableSchemaHasTheAppIcon(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('gone'));

		$this->assertSame('/app.svg', $this->reference()->getIconUrl());
	}//end testAnUnresolvableSchemaHasTheAppIcon()
}//end class
