<?php

/**
 * The contract of the two per-object permission reads, and their guard.
 *
 * Two things are asserted here and they are different in kind. The SHAPE,
 * because a role editor and an audit screen consume it and a renamed field
 * breaks a client that cannot be seen from this repo. And the GUARD, with the
 * least privileged caller who should be refused: reading a dossier is one right
 * and enumerating the case workers on it is another, and a probe that only ran
 * as an administrator would prove neither.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\ObjectPermissionsController;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\ObjectAccessHistory;
use OCA\OpenRegister\Service\Rbac\ObjectAccessReport;
use OCA\OpenRegister\Service\Rbac\ObjectPermissionsResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tasks 7.2 and 7.3, over the wire.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectPermissionsController
 */
class ObjectPermissionsControllerTest extends TestCase {

	/**
	 * The object every case reads.
	 *
	 * @var string
	 */
	private const UUID = 'abcdabcd-0000-0000-0000-000000000009';

	/**
	 * A controller answering for one object, as one caller.
	 *
	 * @param string                 $userId  The caller.
	 * @param boolean                $isAdmin Whether the caller is an administrator.
	 * @param boolean                $manages Whether the caller holds `manage` on the schema.
	 * @param string                 $owner   The object's owner.
	 * @param array<int, AuditTrail> $trail   The object's audit trail.
	 * @param boolean                $schemaDeclaresRules Whether the schema's cascade writes any rules down.
	 * @param boolean                $registerUnset       Whether ObjectService lost its register after resolving the object.
	 * @param LoggerInterface|null   $logger              The logger the controller reports failures to.
	 *
	 * @return ObjectPermissionsController The controller under test.
	 */
	private function controllerFor(
		string $userId,
		bool $isAdmin = false,
		bool $manages = false,
		string $owner = 'bea',
		array $trail = [],
		bool $schemaDeclaresRules = true,
		bool $registerUnset = false,
		?LoggerInterface $logger = null,
	): ObjectPermissionsController {
		$object = new ObjectEntity();
		$object->setUuid(self::UUID);
		$object->setOwner($owner);
		$object->setAuthorization(['read' => ['stagiairs'], 'deny' => ['read' => ['waarnemers']]]);

		$permissionHandler = $this->createMock(originalClassName: PermissionHandler::class);
		$permissionHandler->method('hasPermission')->willReturn($manages);
		// The schema below writes rules down, which is what the guard requires
		// before a `manage` grant counts: on a schema that configures nothing,
		// `manage` resolves default-open for everybody.
		$resolved = [];
		if ($schemaDeclaresRules === true) {
			$resolved = ['roles' => ['behandelaar' => ['behandelaars']]];
		}

		$permissionHandler->method('resolveAuthorization')->willReturn($resolved);

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('getObject')->willReturn($object);
		if ($registerUnset === true) {
			$objectService->method('getRegister')->willThrowException(new \RuntimeException('Register not set in ObjectService.'));
		} else {
			$objectService->method('getRegister')->willReturn(1);
		}
		$objectService->method('getSchema')->willReturn(7);
		$objectService->method('getPermissionHandler')->willReturn($permissionHandler);

		$register = new Register();
		$register->setId(1);
		$register->setSlug('zaken');
		$register->setAuthorization(['read' => ['directie']]);
		$register->setConfiguration(['roles' => [['name' => 'behandelaar', 'actions' => ['read', 'update']]]]);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('zaak');
		$schema->setAuthorization(['roles' => ['behandelaar' => ['behandelaars']]]);

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$auditTrailMapper = $this->createMock(originalClassName: AuditTrailMapper::class);
		$auditTrailMapper->method('findForObjectByAction')->willReturn($trail);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($userId);

		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_STAGING);

		$report = new ObjectAccessReport(
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			auditTrailMapper: $auditTrailMapper,
			holders: new ObjectPermissionsResolver(),
			history: new ObjectAccessHistory(),
			enforcement: new DenyEnforcementMode(appConfig: $appConfig, logger: new NullLogger())
		);

		return new ObjectPermissionsController(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			objectService: $objectService,
			report: $report,
			userSession: $userSession,
			groupManager: $groupManager,
			logger: ($logger ?? new NullLogger())
		);
	}//end controllerFor()

	/**
	 * 🔴 The reader of a dossier may not enumerate who else holds rights on it.
	 *
	 * THE LEAST PRIVILEGED PROBE. This caller resolved the object, which means
	 * RBAC let them read it, and is still refused. A superuser success would
	 * prove almost nothing here.
	 *
	 * @return void
	 */
	public function testAnOrdinaryReaderIsRefusedTheAccessSet(): void {
		$response = $this->controllerFor(userId: 'stagiair')->index('zaken', 'zaak', self::UUID);

		$this->assertSame(403, $response->getStatus());
	}//end testAnOrdinaryReaderIsRefusedTheAccessSet()

	/**
	 * The same refusal guards the history.
	 *
	 * @return void
	 */
	public function testAnOrdinaryReaderIsRefusedTheHistory(): void {
		$response = $this->controllerFor(userId: 'stagiair')->history('zaken', 'zaak', self::UUID);

		$this->assertSame(403, $response->getStatus());
	}//end testAnOrdinaryReaderIsRefusedTheHistory()

	/**
	 * The object's owner reads its access set, with the rule behind each grant.
	 *
	 * @return void
	 */
	public function testTheOwnerReadsTheAccessSetWithItsShape(): void {
		$response = $this->controllerFor(userId: 'bea', owner: 'bea')->index('zaken', 'zaak', self::UUID);

		$this->assertSame(200, $response->getStatus());
		$body = $response->getData();

		foreach (['object', 'register', 'schema', 'owner', 'holders', 'denied', 'denyEnforcement'] as $key) {
			$this->assertArrayHasKey($key, $body, sprintf('the response lost its "%s" key', $key));
		}

		$this->assertSame(self::UUID, $body['object']);
		$this->assertSame(DenyEnforcementMode::MODE_STAGING, $body['denyEnforcement']);

		$principals = array_column($body['holders'], 'principal');
		$this->assertSame(['stagiairs', 'behandelaars', 'directie'], $principals);

		// The role grant names the role, and the level it is written at.
		$byPrincipal = array_combine($principals, $body['holders']);
		$this->assertSame('behandelaar', $byPrincipal['behandelaars']['rules'][0]['role']);
		$this->assertSame(['read', 'update'], $byPrincipal['behandelaars']['verbs']);

		// The deny is reported beside the grants, with the mode that says
		// whether it is biting yet.
		$this->assertSame('waarnemers', $body['denied'][0]['principal']);
	}//end testTheOwnerReadsTheAccessSetWithItsShape()

	/**
	 * An access set that cannot be assembled is a translated 500, not a stack trace.
	 *
	 * `ObjectService::getRegister()` throws a RuntimeException when no register
	 * is set. Uncaught, that reached the framework as a raw 500 on an endpoint a
	 * non-admin owner may call. The caller here is the owner, so the guard lets
	 * the request through and the failure is the only thing under test.
	 *
	 * @return void
	 */
	public function testAnAccessSetThatCannotBeAssembledIsReportedAsSuch(): void {
		// The generic message must not be the only trace: the cause is logged.
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$response = $this->controllerFor(userId: 'bea', owner: 'bea', registerUnset: true, logger: $logger)
			->index('zaken', 'zaak', self::UUID);

		$this->assertSame(expected: 500, actual: $response->getStatus());
		$this->assertSame(
			expected: ['message' => 'The access set for this object could not be assembled'],
			actual: $response->getData()
		);
	}//end testAnAccessSetThatCannotBeAssembledIsReportedAsSuch()

	/**
	 * A caller holding `manage` reads it too, without owning the object.
	 *
	 * The control for the refusal above: without it, the 403 could be a 403 for
	 * every caller who is not the owner, which is a different rule.
	 *
	 * @return void
	 */
	public function testAManagerReadsTheAccessSetOfSomebodyElsesObject(): void {
		$response = $this->controllerFor(userId: 'noor', manages: true, owner: 'bea')
			->index('zaken', 'zaak', self::UUID);

		$this->assertSame(200, $response->getStatus());
		$this->assertNotEmpty($response->getData()['holders']);
	}//end testAManagerReadsTheAccessSetOfSomebodyElsesObject()

	/**
	 * 🔴 On a schema that configures nothing, `manage` is not a review right.
	 *
	 * A schema with no rules resolves default-open, so `manage` answers true for
	 * every signed-in caller. Reporting the access set on that basis would hand
	 * back exactly the `authorization` block that a non-admin object read strips
	 * out. Only the owner and an administrator may ask there.
	 *
	 * @return void
	 */
	public function testADefaultOpenSchemaDoesNotMakeEverybodyAnAuditor(): void {
		$response = $this->controllerFor(
			userId: 'noor',
			manages: true,
			owner: 'bea',
			schemaDeclaresRules: false
		)->index('zaken', 'zaak', self::UUID);

		$this->assertSame(403, $response->getStatus());
	}//end testADefaultOpenSchemaDoesNotMakeEverybodyAnAuditor()

	/**
	 * The owner still reads it there, because the object is theirs.
	 *
	 * The control: without it, the refusal above could be a refusal for
	 * everybody on an unconfigured schema, which would make the endpoint useless
	 * exactly where an owner most wants it.
	 *
	 * @return void
	 */
	public function testTheOwnerStillReadsItOnADefaultOpenSchema(): void {
		$response = $this->controllerFor(
			userId: 'bea',
			owner: 'bea',
			schemaDeclaresRules: false
		)->index('zaken', 'zaak', self::UUID);

		$this->assertSame(200, $response->getStatus());
	}//end testTheOwnerStillReadsItOnADefaultOpenSchema()

	/**
	 * The history reports the change, who made it and the set it produced.
	 *
	 * @return void
	 */
	public function testTheHistoryReportsTheChangeAndItsAuthor(): void {
		$entry = new AuditTrail();
		$entry->setAction('update');
		$entry->setUser('ruben');
		$entry->setCreated(new DateTime('2026-03-01T09:00:00+01:00'));
		$entry->setChanged(
			[
				'authorization' => [
					'old' => ['read' => ['behandelaars']],
					'new' => ['read' => ['behandelaars', 'stagiairs']],
				],
			]
		);

		$response = $this->controllerFor(userId: 'noor', manages: true, trail: [$entry])
			->history('zaken', 'zaak', self::UUID, '2026-06-01T00:00:00+02:00');

		$this->assertSame(200, $response->getStatus());
		$body = $response->getData();

		foreach (['object', 'at', 'changes', 'asOf', 'entriesRead', 'limit'] as $key) {
			$this->assertArrayHasKey($key, $body, sprintf('the history lost its "%s" key', $key));
		}

		$this->assertCount(1, $body['changes']);
		$this->assertSame('ruben', $body['changes'][0]['by']);
		$this->assertSame(
			['behandelaars', 'stagiairs'],
			array_column($body['asOf']['holders'], 'principal')
		);
	}//end testTheHistoryReportsTheChangeAndItsAuthor()
}//end class
