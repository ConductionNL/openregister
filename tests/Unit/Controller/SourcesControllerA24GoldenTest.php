<?php

/**
 * Golden outcomes of SourcesController's admin gate and error mapping.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use OCA\OpenRegister\Controller\SourcesController;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapper;
use OCA\OpenRegister\Service\Dbal\DatabaseIntrospectionService;
use OCA\OpenRegister\Service\Dbal\DbalConnectionException;
use OCA\OpenRegister\Service\Dbal\DbalConnectionFactory;
use OCA\OpenRegister\Service\Sync\HarvestPipelineService;
use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCA\OpenRegister\Tests\Unit\Controller\Support\SourcesControllerFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins allowed and refused outcomes (status + body) of every SourcesController access path.
 */
class SourcesControllerA24GoldenTest extends TestCase {
	/**
	 * The request of the case.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;
	/**
	 * The sourceMapper of the case.
	 *
	 * @var SourceMapper&MockObject
	 */
	private SourceMapper&MockObject $sourceMapper;
	/**
	 * The crypto of the case.
	 *
	 * @var ICrypto&MockObject
	 */
	private ICrypto&MockObject $crypto;
	/**
	 * The registry of the case.
	 *
	 * @var SourceFetcherRegistry&MockObject
	 */
	private SourceFetcherRegistry&MockObject $registry;
	/**
	 * The pipeline of the case.
	 *
	 * @var HarvestPipelineService&MockObject
	 */
	private HarvestPipelineService&MockObject $pipeline;
	/**
	 * The connections of the case.
	 *
	 * @var DbalConnectionFactory&MockObject
	 */
	private DbalConnectionFactory&MockObject $connections;
	/**
	 * The introspection of the case.
	 *
	 * @var DatabaseIntrospectionService&MockObject
	 */
	private DatabaseIntrospectionService&MockObject $introspection;
	/**
	 * The logger of the case.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	private function controller(bool $isAdmin): SourcesController {
		$this->request = $this->createMock(IRequest::class);
		$this->sourceMapper = $this->createMock(SourceMapper::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->registry = $this->createMock(SourceFetcherRegistry::class);
		$this->pipeline = $this->createMock(HarvestPipelineService::class);
		$this->connections = $this->createMock(DbalConnectionFactory::class);
		$this->introspection = $this->createMock(DatabaseIntrospectionService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return SourcesControllerFactory::create(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->sourceMapper,
			$l10n,
			$session,
			$groups,
			$this->crypto,
			$this->registry,
			$this->pipeline,
			$this->connections,
			$this->introspection,
			$this->logger
		);
	}

	private function databaseSource(): Source {
		$source = new Source();
		$source->setId(5);
		$source->setType('database');
		$source->setDatabaseUrl('');
		$source->setAuthConfig(['driver' => 'pdo_pgsql', 'host' => 'db', 'password' => 'p', 'secret' => 's']);
		return $source;
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, mixed>}>
	 */
	public static function adminOnlyActions(): array {
		return [
			'create'         => ['create', []],
			'update'         => ['update', [3]],
			'patch'          => ['patch', [3]],
			'destroy'        => ['destroy', [3]],
			'syncNow'        => ['syncNow', [3]],
			'testConnection' => ['testConnection', [3]],
			'introspect'     => ['introspect', [3]],
		];
	}

	/**
	 * @param string            $action The action.
	 * @param array<int, mixed> $args   Its arguments.
	 *
	 * @return void
	 *
	 * @dataProvider adminOnlyActions
	 */
	public function testANonAdminIsRefusedBeforeAnySourceIsTouched(string $action, array $args): void {
		$controller = $this->controller(isAdmin: false);
		$this->sourceMapper->expects($this->never())->method('find');
		$this->sourceMapper->expects($this->never())->method('createFromArray');
		$this->sourceMapper->expects($this->never())->method('updateFromArray');
		$this->sourceMapper->expects($this->never())->method('delete');
		$this->request->expects($this->never())->method('getParams');

		$response = $controller->{$action}(...$args);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame(['error' => 'Admin privileges required'], $response->getData());
	}

	public function testANonAdminReadsSourcesWithoutDatabaseUrlOrAuthConfig(): void {
		$controller = $this->controller(isAdmin: false);
		$source = $this->databaseSource();
		$this->request->method('getParams')->willReturn([]);
		$this->sourceMapper->method('findList')->willReturn([$source]);
		$this->sourceMapper->method('find')->willReturn($source);

		$listed = $controller->index()->getData()['results'][0];
		$shown = $controller->show('5')->getData();

		foreach ([$listed, $shown] as $data) {
			$this->assertArrayNotHasKey('databaseUrl', $data);
			$this->assertArrayNotHasKey('authConfig', $data);
		}
	}

	public function testAnAdminReadsDatabaseConnectionPartsButNoSecrets(): void {
		$controller = $this->controller(isAdmin: true);
		$this->sourceMapper->method('find')->willReturn($this->databaseSource());

		$data = $controller->show('5')->getData();

		$this->assertArrayHasKey('databaseUrl', $data);
		$this->assertSame(['driver' => 'pdo_pgsql', 'host' => 'db'], $data['authConfig']);
	}

	public function testAnAdminReadOfANonDatabaseSourceKeepsTheSerializedShape(): void {
		$controller = $this->controller(isAdmin: true);
		$source = new Source();
		$source->setType('api');
		$source->setDatabaseUrl('enc');
		$this->sourceMapper->method('find')->willReturn($source);

		$this->assertSame($source->jsonSerialize(), $controller->show('5')->getData());
	}

	public function testShowOfAnUnknownSourceIs404(): void {
		$controller = $this->controller(isAdmin: true);
		$this->sourceMapper->method('find')->willThrowException(new DoesNotExistException('x'));

		$response = $controller->show('5');

		$this->assertSame(404, $response->getStatus());
		$this->assertSame(['error' => 'Not Found'], $response->getData());
	}

	public function testAnAdminCreateStripsInternalKeysAndEncryptsALegacyUrl(): void {
		$controller = $this->controller(isAdmin: true);
		$this->request->method('getParams')->willReturn(['_route' => 'r', 'id' => 9, 'title' => 'T', 'databaseUrl' => 'mysql://x']);
		$this->crypto->method('encrypt')->with('mysql://x')->willReturn('ENC');
		$this->sourceMapper->expects($this->once())->method('createFromArray')
			->with(['title' => 'T', 'databaseUrl' => 'ENC'])
			->willReturn(new Source());

		$this->assertSame(200, $controller->create()->getStatus());
	}

	public function testAnAdminCreateOfADatabaseSourceNeverStoresTheSecret(): void {
		$controller = $this->controller(isAdmin: true);
		$this->request->method('getParams')->willReturn(
			['type' => 'database', 'databaseUrl' => 'x', 'authConfig' => ['host' => 'h', 'password' => 'p', 'secret' => 's']]
		);
		$this->crypto->expects($this->never())->method('encrypt');
		$this->sourceMapper->expects($this->once())->method('createFromArray')
			->with(['type' => 'database', 'databaseUrl' => '', 'authConfig' => ['host' => 'h']])
			->willReturn(new Source());

		$controller->create();
	}

	public function testAnAdminUpdateDropsImmutableFieldsAndEncryptsALegacyUrl(): void {
		$controller = $this->controller(isAdmin: true);
		$this->request->method('getParams')->willReturn(
			['_x' => 1, 'id' => 1, 'organisation' => 'o', 'owner' => 'w', 'created' => 'c', 'title' => 'T', 'databaseUrl' => 'u']
		);
		$this->crypto->method('encrypt')->with('u')->willReturn('ENC');
		$this->sourceMapper->expects($this->once())->method('updateFromArray')
			->with(7, ['title' => 'T', 'databaseUrl' => 'ENC'])
			->willReturn(new Source());

		$this->assertSame(200, $controller->patch(7)->getStatus());
	}

	public function testAnAdminDestroyDeletesTheFoundSource(): void {
		$controller = $this->controller(isAdmin: true);
		$source = new Source();
		$this->sourceMapper->method('find')->with(3)->willReturn($source);
		$this->sourceMapper->expects($this->once())->method('delete')->with($source);

		$response = $controller->destroy(3);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testAFailedSyncIsRecordedAndAnswered500(): void {
		$controller = $this->controller(isAdmin: true);
		$source = new Source();
		$source->setType('api');
		$this->sourceMapper->method('find')->willReturn($source);
		$this->registry->method('get')->willReturn($this->createMock(SourceFetcherInterface::class));
		$this->pipeline->method('run')->willThrowException(new RuntimeException('boom'));
		$this->sourceMapper->expects($this->once())->method('update')->with($source);

		$response = $controller->syncNow(5);

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Sync failed', 'message' => 'boom'], $response->getData());
		$this->assertSame('failed', $source->getLastSyncStatus());
	}

	public function testASyncWithoutAStatusIsRecordedAsSuccess(): void {
		$controller = $this->controller(isAdmin: true);
		$source = new Source();
		$source->setType('api');
		$this->sourceMapper->method('find')->willReturn($source);
		$this->registry->method('get')->willReturn($this->createMock(SourceFetcherInterface::class));
		$this->pipeline->method('run')->willReturn(['imported' => 1]);

		$response = $controller->syncNow(5);

		$this->assertSame(['imported' => 1], $response->getData());
		$this->assertSame('success', $source->getLastSyncStatus());
		$this->assertNotNull($source->getLastSyncDate());
	}

	public function testSyncStatusIsReadableByANonAdmin(): void {
		$controller = $this->controller(isAdmin: false);
		$source = new Source();
		$source->setId(5);
		$source->setLastSyncStatus('failed');
		$this->sourceMapper->method('find')->willReturn($source);

		$data = $controller->syncStatus(5)->getData();

		$this->assertSame(['id', 'uuid', 'syncEnabled', 'status', 'lastSyncDate', 'syncInterval'], array_keys($data));
		$this->assertSame('failed', $data['status']);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function databaseActions(): array {
		return ['testConnection' => ['testConnection'], 'introspect' => ['introspect']];
	}

	/**
	 * @param string $action The action.
	 *
	 * @return void
	 *
	 * @dataProvider databaseActions
	 */
	public function testDatabaseActionsAnswer404And422(string $action): void {
		$controller = $this->controller(isAdmin: true);
		$api = new Source();
		$api->setType('api');
		$this->sourceMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new DoesNotExistException('x')),
			$api
		);

		$missing = $controller->{$action}(5);
		$wrongType = $controller->{$action}(5);

		$this->assertSame([404, ['error' => 'Not Found']], [$missing->getStatus(), $missing->getData()]);
		$this->assertSame(
			[422, ['error' => 'Source is not a database source']],
			[$wrongType->getStatus(), $wrongType->getData()]
		);
	}

	public function testTestConnectionMapsConnectAndQueryFailures(): void {
		$controller = $this->controller(isAdmin: true);
		$this->sourceMapper->method('find')->willReturn($this->databaseSource());
		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getDummySelectSQL')->willReturn('SELECT 1');
		$connection = $this->createMock(Connection::class);
		$connection->method('getDatabasePlatform')->willReturn($platform);
		$connection->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('sql')),
			$this->createMock(\Doctrine\DBAL\Result::class)
		);
		$this->connections->method('getConnection')->willReturnOnConsecutiveCalls(
			$this->throwException(new DbalConnectionException('down')),
			$connection,
			$connection
		);

		$down = $controller->testConnection(5);
		$broken = $controller->testConnection(5);
		$fine = $controller->testConnection(5);

		$this->assertSame([503, ['error' => 'Could not connect to the database source']], [$down->getStatus(), $down->getData()]);
		$this->assertSame([502, ['error' => 'The database source returned an error']], [$broken->getStatus(), $broken->getData()]);
		$this->assertSame([200, ['success' => true]], [$fine->getStatus(), $fine->getData()]);
	}

	public function testIntrospectMapsFailuresAndLogsTheUnexpectedOne(): void {
		$controller = $this->controller(isAdmin: true);
		$this->sourceMapper->method('find')->willReturn($this->databaseSource());
		$this->introspection->method('introspect')->willReturnOnConsecutiveCalls(
			$this->throwException(new DbalConnectionException('down')),
			$this->throwException(new RuntimeException('dsn')),
			['register' => 1]
		);
		$this->logger->expects($this->once())->method('error')
			->with('[SourcesController] introspection failed: dsn', $this->arrayHasKey('exception'));

		$down = $controller->introspect(5);
		$failed = $controller->introspect(5);
		$fine = $controller->introspect(5);

		$this->assertSame([503, ['error' => 'Could not connect to the database source']], [$down->getStatus(), $down->getData()]);
		$this->assertSame([502, ['error' => 'Introspection failed']], [$failed->getStatus(), $failed->getData()]);
		$this->assertSame([200, ['register' => 1]], [$fine->getStatus(), $fine->getData()]);
	}
}
