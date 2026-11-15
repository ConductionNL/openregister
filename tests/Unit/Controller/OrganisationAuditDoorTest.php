<?php

/**
 * OrganisationAuditDoorTest
 *
 * An organisation renamed through OrganisationController::update() reaches
 * the audit trail through the real door: the controller saves through the
 * real OrganisationMapper, whose update announces OrganisationUpdatedEvent
 * through EntityEventPublisher to a dispatcher wired to
 * EntityEditAuditListener, which writes one administrative row.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\OrganisationController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\Support\ConfigServices;
use OCA\OpenRegister\Db\TenantUsageMapper;
use OCA\OpenRegister\Listener\EntityEditAuditListener;
use OCA\OpenRegister\Service\Audit\EntityEditAuditor;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\OrganisationUsageService;
use OCA\OpenRegister\Service\Registers\EntityEventPublisher;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\OrganisationControllerFactory;
use OCA\OpenRegister\Tests\Unit\Db\RecordingQueryBuilderTrait;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The organisation edit door, from the controller to one audit row.
 *
 * @covers \OCA\OpenRegister\Listener\EntityEditAuditListener
 */
final class OrganisationAuditDoorTest extends TestCase {

	use RecordingQueryBuilderTrait;

	/**
	 * The organisation as stored.
	 */
	private const STORED = [
		'id' => 8,
		'uuid' => '9f1c2d3e-0000-4000-8000-000000000003',
		'name' => 'Gemeente Noord',
		'slug' => 'gemeente-noord',
		'description' => 'Gemeente',
	];

	/**
	 * The rows the audit trail received.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $written = [];

	/**
	 * The controller over the real mapper and listener, for an administrator of
	 * the organisation sending the given body.
	 *
	 * @param array<string, mixed> $body  The request body.
	 * @param bool                 $admin Whether the caller administers the organisation.
	 *
	 * @return OrganisationController
	 */
	private function controller(array $body, bool $admin=true): OrganisationController {
		$trail = $this->createMock(AuditTrailMapperInterface::class);
		$trail->method('insertAuditTrails')->willReturnCallback(
			function (array $entries): array {
				array_push($this->written, ...$entries);
				return $entries;
			}
		);
		$listener = new EntityEditAuditListener(
			new EntityEditAuditor($trail, $this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class))
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (Event $event) use ($listener): void {
			$listener->handle($event);
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->recordingBuilder());
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnMap([['dbtableprefix', 'oc_', 'oc_']]);
		$mapper = new OrganisationMapper(
			$db,
			new NullLogger(),
			new EntityEventPublisher(dispatcher: $dispatcher),
			new ConfigServices($this->createMock(IAppConfig::class), $config)
		);

		$service = $this->createMock(OrganisationService::class);
		$service->method('hasAccessToOrganisation')->willReturn(true);
		$service->method('isOrganisationAdmin')->willReturn($admin);
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($body + ['_route' => 'organisation#update']);

		return OrganisationControllerFactory::create(
			'openregister',
			$request,
			$service,
			$mapper,
			new NullLogger(),
			$this->createMock(TenantLifecycleService::class),
			$this->createMock(TenantUsageMapper::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(OrganisationUsageService::class)
		);
	}//end controller()

	/**
	 * A rename through the controller writes exactly one organisation.updated
	 * row, administrative, with the old and new name and slug.
	 *
	 * @return void
	 */
	public function testARenameThroughTheControllerWritesOneRow(): void {
		$controller = $this->controller(['name' => 'Gemeente Noordstad']);
		// The controller's lookup, no other organisation holding the uuid, then
		// the mapper's read of the stored state before the update.
		$this->queuedRows = [[self::STORED], [], [self::STORED]];

		$response = $controller->update(self::STORED['uuid']);

		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
		$this->assertCount(1, $this->written);
		$row = $this->written[0];
		$this->assertSame('organisation.updated', $row->getAction());
		$this->assertSame('administrative', $row->getCategory());
		$this->assertSame(self::STORED['uuid'], $row->getOrganisationId());
		// The controller derives the slug from the new name, so both move.
		$this->assertSame(
			[
				['path' => 'slug', 'old' => 'gemeente-noord', 'new' => 'gemeente-noordstad'],
				['path' => 'name', 'old' => 'Gemeente Noord', 'new' => 'Gemeente Noordstad'],
			],
			$row->getChanged()['fields']
		);
	}//end testARenameThroughTheControllerWritesOneRow()

	/**
	 * A caller who may not change the organisation reaches neither the mapper nor the trail.
	 *
	 * @return void
	 */
	public function testARefusedUpdateWritesNothing(): void {
		$controller = $this->controller(['name' => 'Gemeente Noordstad'], admin: false);
		$this->queuedRows = [[self::STORED], [self::STORED]];

		$response = $controller->update(self::STORED['uuid']);

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([], $this->written);
		$this->assertSame([], $this->queryLog, 'Nothing is read or written for a refused caller.');
	}//end testARefusedUpdateWritesNothing()
}//end class
