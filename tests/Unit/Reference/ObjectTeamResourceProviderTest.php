<?php

/**
 * The team resource provider: paging across opted-in schemas, the reader's access, and the belongs-to answer.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Reference
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Reference;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Reference\ObjectTeamResourceProvider;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatterInterface;
use OCP\IL10N;
use OCP\Search\SearchResultEntry;
use OCP\Teams\TeamResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObjectTeamResourceProvider.
 *
 * @covers \OCA\OpenRegister\Reference\ObjectTeamResourceProvider
 * @uses   \OCA\OpenRegister\Reference\TeamResourceDeclaration
 * @uses   \OCA\OpenRegister\Reference\TeamResourcePage
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkBatchDefaults
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\BulkSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SaveActor
 * @uses   \OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy
 */
final class ObjectTeamResourceProviderTest extends TestCase {

	/**
	 * The object search and read.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The schema mapper behind the catalog.
	 *
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface&MockObject $schemas;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Matching objects per schema id, as the search would hold them.
	 *
	 * @var array<int, list<array<string, mixed>>>
	 */
	private array $stored = [];

	/**
	 * Every search query sent.
	 *
	 * @var list<array{query: array<string, mixed>, rbac: bool, multitenancy: bool}>
	 */
	private array $queries = [];

	/**
	 * The provider under test.
	 *
	 * @var ObjectTeamResourceProvider
	 */
	private ObjectTeamResourceProvider $provider;

	/**
	 * Three schemas: 3 and 9 opted in under different properties, 5 declaring nothing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);

		$declared = [
			self::schema(id: 9, block: ['teamProperty' => 'team']),
			self::schema(id: 5, block: null),
			self::schema(id: 3, block: ['teamProperty' => 'afdeling']),
		];
		$this->schemas->method('findAll')->willReturn($declared);
		$this->schemas->method('find')->willReturnCallback(
			static function (string|int $id) use ($declared): Schema {
				foreach ($declared as $schema) {
					if ($schema->getId() === (int)$id) {
						return $schema;
					}
				}

				throw new Exception('Schema not found');
			}
		);

		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('schemas')->willReturn($this->schemas);

		$this->objects->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query=[], bool $_rbac=true, bool $_multitenancy=true): array {
				$this->queries[] = ['query' => $query, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$rows = ($this->stored[$query['@self']['schema']] ?? []);
				return [
					'results' => array_slice($rows, $query['_offset'], $query['_limit']),
					'total' => count($rows),
				];
			}
		);

		$formatter = $this->createMock(ObjectSearchResultFormatterInterface::class);
		$formatter->method('format')->willReturnCallback(
			static function (array|ObjectEntity $result): SearchResultEntry {
				$id = ($result['@self']['id'] ?? '');
				return new SearchResultEntry(
					($id === 'c' ? '' : '/icon/' . $id . '.svg'),
					'Zaak ' . $id,
					'Open Register',
					'/apps/openregister/objects/' . $id
				);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->provider = new ObjectTeamResourceProvider(
			objects: $this->objects,
			catalog: $catalog,
			formatter: $formatter,
			l10n: $l10n,
			logger: $this->logger
		);

		$this->stored = [
			3 => [self::row(id: 'a'), self::row(id: 'b'), self::row(id: 'c')],
			9 => [self::row(id: 'd')],
		];
	}//end setUp()

	/**
	 * A schema with an id and an optional team-page declaration.
	 *
	 * @param int                       $id    The id.
	 * @param array<string, mixed>|null $block The declaration, or null for none.
	 *
	 * @return Schema The schema.
	 */
	private static function schema(int $id, ?array $block): Schema {
		$schema = new Schema();
		$schema->setId($id);
		if ($block !== null) {
			$schema->setConfiguration(['x-openregister-team-resources' => $block]);
		}

		return $schema;
	}//end schema()

	/**
	 * A search row for an object.
	 *
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function row(string $id): array {
		return ['@self' => ['id' => $id]];
	}//end row()

	/**
	 * The ids of resources.
	 *
	 * @param list<TeamResource> $resources The resources.
	 *
	 * @return list<string> Their ids.
	 */
	private static function ids(array $resources): array {
		return array_map(static fn (TeamResource $resource): string => $resource->getId(), $resources);
	}//end ids()

	/**
	 * The provider names itself and carries the app icon.
	 *
	 * @return void
	 */
	public function testTheProviderNamesItself(): void {
		$this->assertSame('openregister', $this->provider->getId());
		$this->assertSame('Open Register', $this->provider->getName());
		$this->assertStringContainsString('<svg', $this->provider->getIconSvg());
	}//end testTheProviderNamesItself()

	/**
	 * A page holds the limit and a cursor into the same schema when that schema has more.
	 *
	 * @return void
	 */
	public function testAFullPageCarriesACursorIntoTheSameSchema(): void {
		$page = $this->provider->listPage(teamId: 'wijkteam', limit: 2);

		$this->assertSame(['a', 'b'], self::ids($page->resources));
		$this->assertSame('3:2', $page->nextCursor);
	}//end testAFullPageCarriesACursorIntoTheSameSchema()

	/**
	 * The cursor resumes where the last page stopped and fills up from the next opted-in schema.
	 *
	 * @return void
	 */
	public function testTheCursorResumesAndCrossesIntoTheNextSchema(): void {
		$page = $this->provider->listPage(teamId: 'wijkteam', cursor: '3:2', limit: 2);

		$this->assertSame(['c', 'd'], self::ids($page->resources));
		$this->assertNull($page->nextCursor);
		$this->assertSame(
			[[3, 2, 2], [9, 0, 1]],
			array_map(
				static fn (array $sent): array => [$sent['query']['@self']['schema'], $sent['query']['_offset'], $sent['query']['_limit']],
				$this->queries
			)
		);
	}//end testTheCursorResumesAndCrossesIntoTheNextSchema()

	/**
	 * A page ending exactly at a schema's last object points the cursor at the next schema.
	 *
	 * @return void
	 */
	public function testAPageEndingAtASchemaPointsToTheNextSchema(): void {
		$page = $this->provider->listPage(teamId: 'wijkteam', limit: 3);

		$this->assertSame(['a', 'b', 'c'], self::ids($page->resources));
		$this->assertSame('9:0', $page->nextCursor);

		$last = $this->provider->listPage(teamId: 'wijkteam', cursor: $page->nextCursor, limit: 3);
		$this->assertSame(['d'], self::ids($last->resources));
		$this->assertNull($last->nextCursor);
	}//end testAPageEndingAtASchemaPointsToTheNextSchema()

	/**
	 * Each opted-in schema is searched on its own team property, with access applied; the opted-out one never.
	 *
	 * @return void
	 */
	public function testEachSchemaIsSearchedOnItsPropertyWithAccessApplied(): void {
		$this->provider->listPage(teamId: 'wijkteam');

		$this->assertCount(2, $this->queries);
		$this->assertSame('wijkteam', $this->queries[0]['query']['afdeling']);
		$this->assertArrayNotHasKey('team', $this->queries[0]['query']);
		$this->assertSame('wijkteam', $this->queries[1]['query']['team']);
		foreach ($this->queries as $sent) {
			$this->assertNotSame(5, $sent['query']['@self']['schema']);
			$this->assertTrue($sent['rbac']);
			$this->assertTrue($sent['multitenancy']);
		}
	}//end testEachSchemaIsSearchedOnItsPropertyWithAccessApplied()

	/**
	 * Only what the access-applied search returns is listed: an object the reader may not read is absent.
	 *
	 * @return void
	 */
	public function testOnlyReadableObjectsAreListed(): void {
		$this->stored[3] = [self::row(id: 'a')];

		$this->assertSame(['a', 'd'], self::ids($this->provider->getSharedWith(teamId: 'wijkteam')));
	}//end testOnlyReadableObjectsAreListed()

	/**
	 * Each resource carries the object's name, icon and link, and no icon when it has none.
	 *
	 * @return void
	 */
	public function testAResourceCarriesNameIconAndLink(): void {
		$resources = $this->provider->getSharedWith(teamId: 'wijkteam');

		$this->assertSame('Zaak a', $resources[0]->getLabel());
		$this->assertSame('/apps/openregister/objects/a', $resources[0]->getUrl());
		$this->assertSame('/icon/a.svg', $resources[0]->getIconURL());
		$this->assertNull($resources[2]->getIconURL());
		$this->assertSame($this->provider, $resources[0]->getProvider());
	}//end testAResourceCarriesNameIconAndLink()

	/**
	 * A team page is capped at the page limit even when asked for more.
	 *
	 * @return void
	 */
	public function testThePageLimitCapsARequest(): void {
		$this->stored[3] = array_map(static fn (int $n): array => self::row(id: 'o' . $n), range(1, 30));

		$page = $this->provider->listPage(teamId: 'wijkteam', limit: 100);

		$this->assertCount(ObjectTeamResourceProvider::PAGE_LIMIT, $page->resources);
		$this->assertSame('3:25', $page->nextCursor);
	}//end testThePageLimitCapsARequest()

	/**
	 * An unreadable cursor starts at the beginning, and an empty team lists nothing without searching.
	 *
	 * @return void
	 */
	public function testABadCursorRestartsAndAnEmptyTeamListsNothing(): void {
		$this->assertSame(['a', 'b'], self::ids($this->provider->listPage(teamId: 'wijkteam', cursor: 'nonsense', limit: 2)->resources));

		$this->queries = [];
		$empty = $this->provider->listPage(teamId: ' ');
		$this->assertSame([], $empty->resources);
		$this->assertNull($empty->nextCursor);
		$this->assertSame([], $this->queries);
	}//end testABadCursorRestartsAndAnEmptyTeamListsNothing()

	/**
	 * A schema whose search fails is logged and skipped; the others still answer.
	 *
	 * @return void
	 */
	public function testAFailingSchemaIsSkipped(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query=[]): array {
				if ($query['@self']['schema'] === 3) {
					throw new Exception('table missing');
				}

				return ['results' => [self::row(id: 'd')], 'total' => 1];
			}
		);
		$this->logger->expects($this->once())->method('error');

		$provider = $this->providerOver(objects: $objects);

		$this->assertSame(['d'], self::ids($provider->getSharedWith(teamId: 'wijkteam')));
	}//end testAFailingSchemaIsSkipped()

	/**
	 * An object belongs to the teams its declared property names, and to no other.
	 *
	 * @return void
	 */
	public function testBelongingIsReadFromTheProperty(): void {
		$object = new ObjectEntity();
		$object->setSchema('3');
		$object->setObject(['afdeling' => ['wijkteam', 'vergunningen'], 'team' => 'ignored']);
		$this->objects->expects($this->atLeastOnce())->method('find')
			->with('zaak-1', null, true, true)
			->willReturn($object);

		$this->assertSame(['wijkteam', 'vergunningen'], $this->provider->getTeamsForResource(resourceId: 'zaak-1'));
		$this->assertTrue($this->provider->isSharedWithTeam(teamId: 'wijkteam', resourceId: 'zaak-1'));
		$this->assertFalse($this->provider->isSharedWithTeam(teamId: 'ignored', resourceId: 'zaak-1'));
	}//end testBelongingIsReadFromTheProperty()

	/**
	 * An object the reader may not read, one of an opted-out schema, or one without a schema, belongs to no team.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrUndeclaredObjectBelongsToNoTeam(): void {
		$optedOut = new ObjectEntity();
		$optedOut->setSchema('5');
		$optedOut->setObject(['afdeling' => 'wijkteam']);
		$this->objects->method('find')->willReturnCallback(
			static function (string|int $id) use ($optedOut): ?ObjectEntity {
				return match ($id) {
					'hidden' => throw new Exception('Not authorized'),
					'out' => $optedOut,
					'loose' => new ObjectEntity(),
					default => null,
				};
			}
		);
		$this->logger->expects($this->once())->method('debug');

		$this->assertFalse($this->provider->isSharedWithTeam(teamId: 'wijkteam', resourceId: 'hidden'));
		$this->assertSame([], $this->provider->getTeamsForResource(resourceId: 'out'));
		$this->assertSame([], $this->provider->getTeamsForResource(resourceId: 'loose'));
		$this->assertSame([], $this->provider->getTeamsForResource(resourceId: 'gone'));
	}//end testAnUnreadableOrUndeclaredObjectBelongsToNoTeam()

	/**
	 * When the schemas cannot be read, nothing is listed and the failure is logged.
	 *
	 * @return void
	 */
	public function testUnreadableSchemasListNothing(): void {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willThrowException(new Exception('db down'));
		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('schemas')->willReturn($schemas);
		$this->logger->expects($this->once())->method('error');

		$provider = new ObjectTeamResourceProvider(
			objects: $this->objects,
			catalog: $catalog,
			formatter: $this->createMock(ObjectSearchResultFormatterInterface::class),
			l10n: $this->createMock(IL10N::class),
			logger: $this->logger
		);

		$this->assertSame([], $provider->getSharedWith(teamId: 'wijkteam'));
		$this->assertSame([], $this->queries);
	}//end testUnreadableSchemasListNothing()

	/**
	 * A provider over other object operations and this test's schemas.
	 *
	 * @param ObjectOperationsInterface $objects The object operations.
	 *
	 * @return ObjectTeamResourceProvider The provider.
	 */
	private function providerOver(ObjectOperationsInterface $objects): ObjectTeamResourceProvider {
		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('schemas')->willReturn($this->schemas);
		$formatter = $this->createMock(ObjectSearchResultFormatterInterface::class);
		$formatter->method('format')->willReturn(new SearchResultEntry('', 'Zaak', '', '/x'));

		return new ObjectTeamResourceProvider(
			objects: $objects,
			catalog: $catalog,
			formatter: $formatter,
			l10n: $this->createMock(IL10N::class),
			logger: $this->logger
		);
	}//end providerOver()
}//end class
