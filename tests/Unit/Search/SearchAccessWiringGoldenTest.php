<?php

/**
 * Golden allowed/refused outcomes of the map overview, view mapper and objects search provider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Db\ViewMapper;
use OCA\OpenRegister\Search\Db\ViewMapperAccess;
use OCA\OpenRegister\Search\ObjectsProvider;
use OCA\OpenRegister\Search\ObjectsProviderOutput;
use OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Search\Service\MapsOverviewService;
use OCA\OpenRegister\Search\Service\MapsOverviewSources;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Integration\PageWidgetRegistryInterface;
use OCA\OpenRegister\Service\Reference\ObjectPreviewFormatter;
use OCA\OpenRegister\Service\Reference\ObjectPreviewSources;
use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatter;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Search\IFilter;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins who may and who may not, through the services each class receives.
 */
class SearchAccessWiringGoldenTest extends TestCase {
	/**
	 * A session for $uid, or a signed-out session.
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return IUserSession
	 */
	private function session(?string $uid): IUserSession {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * A group manager where only `root` is admin and `$groups` are the user's groups.
	 *
	 * @param array<int, string> $groups The user's groups.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(array $groups = []): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$manager->method('getUserGroupIds')->willReturn($groups);
		return $manager;
	}//end groups()

	/**
	 * The map overview service, recording the scope flags of each read.
	 *
	 * @param string|null              $uid   The signed-in uid.
	 * @param array<int, array<mixed>> $reads Collects [_rbac, _multitenancy] per read.
	 *
	 * @return MapsOverviewService
	 */
	private function maps(?string $uid, array &$reads): MapsOverviewService {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config, bool $_rbac = true, bool $_multitenancy = true) use (&$reads): array {
				$reads[] = [$_rbac, $_multitenancy];
				return [];
			}
		);

		return new MapsOverviewService(
			new MapsOverviewSources(
				$objects,
				$this->createMock(GeoFeatureCollectionBuilder::class),
				$this->createMock(PageWidgetRegistryInterface::class)
			),
			$this->session($uid),
			$this->groupManager()
		);
	}//end maps()

	/**
	 * Registering a map overview needs a signed-in user.
	 *
	 * @return void
	 */
	public function testMapsRegisterGuard(): void {
		$reads = [];
		$this->maps('alice', $reads)->ensureCanRegister();

		$this->expectException(InvalidArgumentException::class);
		$this->maps(null, $reads)->ensureCanRegister();
	}//end testMapsRegisterGuard()

	/**
	 * Points are RBAC- and tenant-scoped for everyone but an admin.
	 *
	 * @return void
	 */
	public function testMapsPointScope(): void {
		$reads = [];
		$this->maps('alice', $reads)->queryPoints(register: 'r', schema: 's');
		$this->maps(null, $reads)->queryPoints(register: 'r', schema: 's');
		$this->maps('root', $reads)->queryPoints(register: 'r', schema: 's');

		$this->assertSame([[true, true], [true, true], [false, false]], $reads);
	}//end testMapsPointScope()

	/**
	 * The view mapper for $uid in $groups, under an organisation that limits view reads to `editors`.
	 *
	 * @param string|null        $uid    The signed-in uid.
	 * @param array<int, string> $groups The user's groups.
	 *
	 * @return ViewMapper
	 */
	private function views(?string $uid, array $groups): ViewMapper {
		$organisation = new Organisation();
		$organisation->setAuthorization(['view' => ['read' => ['editors']]]);

		$organisations = $this->createMock(OrganisationMapperInterface::class);
		$organisations->method('isEntityRbacEnforcementEnabled')->willReturn(true);
		$organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');
		$organisations->method('findByUuid')->willReturn($organisation);

		return new ViewMapper(
			$this->createMock(IDBConnection::class),
			new ViewMapperAccess(
				$organisations,
				$this->createMock(IAppConfig::class),
				$this->session($uid),
				$this->groupManager($groups)
			),
			$this->createMock(IEventDispatcher::class)
		);
	}//end views()

	/**
	 * Whether the mapper grants $action on views.
	 *
	 * @param ViewMapper $mapper The mapper.
	 * @param string     $action The action.
	 *
	 * @return bool
	 */
	private function grants(ViewMapper $mapper, string $action): bool {
		return (bool)(new ReflectionMethod($mapper, 'hasRbacPermission'))->invoke($mapper, $action, 'view');
	}//end grants()

	/**
	 * Admin and group members may read views; others may not; unlisted actions stay open.
	 *
	 * @return void
	 */
	public function testViewRbacOutcomes(): void {
		$this->assertTrue($this->grants($this->views('root', []), 'read'));
		$this->assertTrue($this->grants($this->views('alice', ['editors']), 'read'));
		$this->assertFalse($this->grants($this->views('bob', ['viewers']), 'read'));
		$this->assertTrue($this->grants($this->views('bob', ['viewers']), 'delete'));
	}//end testViewRbacOutcomes()

	/**
	 * The objects provider over `$searchable` schemas, recording the scope flags of each search.
	 *
	 * @param array<int, int>          $searchable    Searchable schema ids.
	 * @param array<int, int>          $nonSearchable Opted-out schema ids.
	 * @param array<int, array<mixed>> $reads         Collects [_rbac, _multitenancy] per search.
	 *
	 * @return ObjectsProvider
	 */
	private function provider(array $searchable, array $nonSearchable, array &$reads): ObjectsProvider {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('searchObjectsPaginated')->willReturnCallback(
			static function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use (&$reads): array {
				$reads[] = [$_rbac, $_multitenancy];
				return ['results' => [], 'total' => 0];
			}
		);

		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findSearchableIds')->willReturn($searchable);
		$schemas->method('findNonSearchableIds')->willReturn($nonSearchable);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturn([]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$logger    = $this->createMock(LoggerInterface::class);
		$urls      = $this->createMock(IURLGenerator::class);
		$deepLinks = $this->createMock(DeepLinkRegistryService::class);
		$preview   = new ObjectPreviewFormatter(
			urlGenerator: $urls,
			l10n: $l10n,
			sources: new ObjectPreviewSources(
				objectService: $objects,
				deepLinkRegistry: $deepLinks,
				schemaMapper: $schemas,
				registerMapper: $registers
			),
			logger: $logger
		);

		return new ObjectsProvider(
			$objects,
			$schemas,
			new ObjectsProviderOutput($l10n, new ObjectSearchResultFormatter($urls, $deepLinks, $preview), $logger),
			$registers
		);
	}//end provider()

	/**
	 * A search query with the given filters.
	 *
	 * @param array<string, mixed> $filters Filter name => value.
	 *
	 * @return ISearchQuery
	 */
	private function query(array $filters): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getFilter')->willReturnCallback(
			function (string $name) use ($filters): ?IFilter {
				if (array_key_exists($name, $filters) === false) {
					return null;
				}

				$filter = $this->createMock(IFilter::class);
				$filter->method('get')->willReturn($filters[$name]);
				return $filter;
			}
		);
		$query->method('getLimit')->willReturn(10);
		$query->method('getCursor')->willReturn(null);
		return $query;
	}//end query()

	/**
	 * Searches run RBAC- and tenant-scoped; no searchable schema, or an opted-out one, searches nothing.
	 *
	 * @return void
	 */
	public function testObjectsSearchScope(): void {
		$user  = $this->createMock(IUser::class);
		$reads = [];
		$this->provider([3, 4], [], $reads)->search($user, $this->query(['term' => 'x']));
		$this->assertSame([[true, true]], $reads);

		$reads   = [];
		$results = $this->provider([], [], $reads)->search($user, $this->query(['term' => 'x']));
		$this->assertSame([], $reads);
		$this->assertSame([], $results->jsonSerialize()['entries']);

		$this->provider([3], [7], $reads)->search($user, $this->query(['term' => 'x', 'schema' => '7']));
		$this->assertSame([], $reads);
	}//end testObjectsSearchScope()
}//end class
