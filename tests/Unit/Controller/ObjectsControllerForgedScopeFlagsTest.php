<?php

/**
 * A non-admin cannot switch RBAC or multitenancy off from the request on any object listing route.
 *
 * `ObjectService::searchObjectsPaginated()` and the magic-mapper searches trust the `_rbac` and
 * `_multitenancy` flags they receive. The only thing that stops a caller from sending them as
 * `false` is the controller deriving them from admin status. These cases send the flags forged
 * through both `getParam()` and `getParams()` and check what the search receives, on the
 * ObjectService route, the magic-table route and the cross-table route of `index()`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- typed mock fixtures; the declaration IS the description.

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\WritePhaseProbe
 * @uses   \OCA\OpenRegister\Support\FilterParams
 */
class ObjectsControllerForgedScopeFlagsTest extends TestCase {

	/**
	 * The scope flags a caller forges, in every spelling the request can carry.
	 */
	private const FORGED = ['_rbac' => 'false', '_multitenancy' => 'false', 'rbac' => 'false', 'multi' => 'false', '_multi' => 'false'];

	/** @var array<int, array<string, mixed>> */
	private array $searches = [];

	public function testTheObjectServiceListingRunsWithRbacAndMultitenancyForANonAdmin(): void {
		$this->listing(groups: ['users'], magic: false, extra: []);

		$this->assertSame([['route' => 'objectService', 'rbac' => true, 'multitenancy' => true]], $this->searches);
	}

	public function testTheMagicTableListingRunsWithRbacAndMultitenancyForANonAdmin(): void {
		$this->listing(groups: ['users'], magic: true, extra: []);

		$this->assertSame([['route' => 'magicTable', 'rbac' => true, 'multitenancy' => true, 'explicit' => false]], $this->searches);
	}

	public function testTheCrossTableListingRunsWithRbacAndMultitenancyForANonAdmin(): void {
		$this->listing(groups: ['users'], magic: true, extra: ['schemas' => 'persoon,besluit']);

		$this->assertSame([['route' => 'crossTable', 'rbac' => true, 'multitenancy' => true]], $this->searches);
	}

	public function testAnAdminListsWithoutRbacOrMultitenancyOnEveryRoute(): void {
		$this->listing(groups: ['users', 'admin'], magic: false, extra: []);
		$this->listing(groups: ['users', 'admin'], magic: true, extra: []);
		$this->listing(groups: ['users', 'admin'], magic: true, extra: ['schemas' => 'persoon,besluit']);

		$this->assertSame(
			[
				['route' => 'objectService', 'rbac' => false, 'multitenancy' => false],
				['route' => 'magicTable', 'rbac' => false, 'multitenancy' => false, 'explicit' => false],
				['route' => 'crossTable', 'rbac' => false, 'multitenancy' => false],
			],
			$this->searches
		);
	}

	/**
	 * Run one GET /objects/zaken/persoon with the forged flags and record what the search received.
	 *
	 * @param array<int, string>   $groups The session user's groups.
	 * @param bool                 $magic  Whether the register magic-maps the schemas.
	 * @param array<string, mixed> $extra  More request parameters.
	 *
	 * @return void
	 */
	private function listing(array $groups, bool $magic, array $extra): void {
		$params = ['register' => 'zaken', 'schema' => 'persoon'] + $extra + self::FORGED;

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mallory');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturnCallback(fn (IUser $asked): array => ($asked === $user ? $groups : []));

		$register = new Register();
		$register->setId(7);
		$register->setSlug('zaken');
		$register->setConfiguration(['schemas' => ['persoon' => ['magicMapping' => $magic], 'besluit' => ['magicMapping' => $magic]]]);

		$schema = new Schema();
		$schema->setId(4);
		$schema->setSlug('persoon');

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getRegister')->willReturn(7);
		$objectService->method('getSchema')->willReturn(4);
		$objectService->method('getCurrentRegisterEntity')->willReturn($register);
		$objectService->method('getCurrentSchemaEntity')->willReturn($schema);
		$objectService->method('buildSearchQuery')->willReturnCallback(fn (array $requestParams = []) => $requestParams);
		$objectService->method('searchObjectsPaginated')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->searches[] = ['route' => 'objectService', 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return ['results' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'limit' => 20, 'offset' => 0];
			}
		);

		$magicMapper = $this->createMock(MagicMapper::class);
		$magicMapper->method('searchObjectsInRegisterSchemaTable')->willReturnCallback(
			function (array $query): array {
				$this->searches[] = ['route' => 'magicTable', 'rbac' => $query['_rbac'], 'multitenancy' => $query['_multitenancy'], 'explicit' => $query['_multitenancy_explicit']];
				return [];
			}
		);
		$magicMapper->method('searchAcrossMultipleTables')->willReturnCallback(
			function (array $query): array {
				$this->searches[] = ['route' => 'crossTable', 'rbac' => $query['_rbac'], 'multitenancy' => $query['_multitenancy']];
				return [];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === MagicMapper::class ? $magicMapper : $this->createMock($id)));

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function ($id) {
				$found = new Schema();
				$found->setId($id === 'besluit' ? 5 : 4);
				$found->setSlug((string) $id);
				return $found;
			}
		);

		$controller = new ObjectsController(
			'openregister',
			$request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$container,
			$registerMapper,
			$schemaMapper,
			$this->createMock(AuditTrailMapper::class),
			$objectService,
			$userSession,
			$groupManager,
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
		);

		try {
			$controller->index(register: 'zaken', schema: 'persoon', objectService: $objectService);
		} catch (\Throwable $e) {
			// The response after the search is not under test; the search arguments are recorded first.
			$this->searches[] = ['after the search' => get_class($e) . ': ' . $e->getMessage()];
		}
	}
}
