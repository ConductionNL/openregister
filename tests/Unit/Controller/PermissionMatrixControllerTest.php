<?php

/**
 * `GET /api/permissions/matrix`: administrator only, audited, streamed (REQ-RAH-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rights-administration-hardening/specs/rbac-scopes/spec.md#requirement-an-administrator-exports-the-full-authorisation-matrix-with-provenance-req-rah-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use DateTime;
use OCA\OpenRegister\Controller\PermissionsAuditController;
use OCA\OpenRegister\Controller\Support\PermissionsAuditMatrix;
use OCA\OpenRegister\Controller\Support\StreamedTextResponse;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Authorization\PermissionMatrixExporter;
use OCA\OpenRegister\Service\Authorization\PermissionMatrixSubjects;
use OCA\OpenRegister\Service\Rbac\AdminGate;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\PermissionReportScope;
use OCA\OpenRegister\Tests\Unit\Controller\Support\PermissionsAuditControllerFactory;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The matrix route as an administrator, a non-administrator and an auditor see it.
 */
#[CoversClass(PermissionsAuditController::class)]
#[CoversClass(StreamedTextResponse::class)]
#[CoversClass(PermissionMatrixSubjects::class)]
class PermissionMatrixControllerTest extends TestCase {

	/**
	 * The audit events dispatched.
	 *
	 * @var array<int, object>
	 */
	private array $dispatched = [];

	/**
	 * A non-administrator gets 403 and no audit row is written.
	 *
	 * @return void
	 */
	public function testANonAdministratorGets403(): void {
		$response = $this->controller(isAdmin: false)->matrix();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(403, $response->getStatus());
		self::assertSame([], $this->dispatched);
	}//end testANonAdministratorGets403()

	/**
	 * The route is administrator only at the framework too: no NoAdminRequired.
	 *
	 * @return void
	 */
	public function testTheRouteCarriesNoNoAdminRequired(): void {
		$method = new ReflectionMethod(PermissionsAuditController::class, 'matrix');

		self::assertSame([], $method->getAttributes(NoAdminRequired::class));
		self::assertStringContainsString(
			"'permissionsAudit#matrix',      'url' => '/api/permissions/matrix'",
			(string)file_get_contents(__DIR__.'/../../../appinfo/routes.php')
		);
	}//end testTheRouteCarriesNoNoAdminRequired()

	/**
	 * The export writes one audit row naming its hash, and the body carries the same hash.
	 *
	 * @return void
	 */
	public function testTheExportWritesAnAuditRow(): void {
		$response = $this->controller(isAdmin: true)->matrix(format: 'csv', level: 'group');

		self::assertInstanceOf(StreamedTextResponse::class, $response);
		$headers = $response->getHeaders();
		self::assertSame('text/csv; charset=utf-8', $headers['Content-Type']);
		self::assertSame('4', $headers['X-Matrix-Rows']);

		self::assertCount(1, $this->dispatched);
		$event = $this->dispatched[0];
		self::assertInstanceOf(CriticalActionPerformedEvent::class, $event);
		self::assertSame($headers['X-Matrix-Sha256'], $event->getParameters()['sha256']);
		self::assertSame('beheerder', $event->getParameters()['user']);
		self::assertSame(4, $event->getParameters()['rows']);

		$body = $this->bodyOf(response: $response);
		self::assertStringContainsString("# generatedBy: beheerder\n", $body);
		self::assertStringContainsString("# generatedAt: 2026-11-24T21:00:00+00:00\n", $body);
		self::assertStringContainsString("# instanceId: oc-instance\n", $body);
		self::assertStringContainsString('# sha256: '.$headers['X-Matrix-Sha256']."\n", $body);
		self::assertStringContainsString("group,redactie,,woo,besluit,,update,false,none,,,false\n", $body);
		self::assertStringContainsString("group,admin,,woo,besluit,,update,true,administrator,,,false\n", $body);
	}//end testTheExportWritesAnAuditRow()

	/**
	 * A user-level JSON export lists users with their groups.
	 *
	 * @return void
	 */
	public function testAUserLevelJsonExportListsUsersWithTheirGroups(): void {
		$response = $this->controller(isAdmin: true)->matrix(format: 'json', level: 'user');

		$json = json_decode($this->bodyOf(response: $response), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame('user', $json['level']);
		self::assertSame(2, $json['rowCount']);
		self::assertSame(['sanne', 'redactie', 'read', 'true', 'schema-rule'], [
			$json['rows'][0]['subject'],
			$json['rows'][0]['groups'],
			$json['rows'][0]['action'],
			$json['rows'][0]['granted'],
			$json['rows'][0]['rule'],
		]);
	}//end testAUserLevelJsonExportListsUsersWithTheirGroups()

	/**
	 * An unknown format or level is 400, and nothing is audited.
	 *
	 * @return void
	 */
	public function testAnUnknownFormatOrLevelIs400(): void {
		$controller = $this->controller(isAdmin: true);

		self::assertSame(400, $controller->matrix(format: 'xlsx')->getStatus());
		self::assertSame(400, $controller->matrix(level: 'organisation')->getStatus());
		self::assertSame([], $this->dispatched);
	}//end testAnUnknownFormatOrLevelIs400()

	/**
	 * The body a streamed response writes.
	 *
	 * @param mixed $response The response.
	 *
	 * @return string The body.
	 */
	private function bodyOf(mixed $response): string {
		self::assertInstanceOf(StreamedTextResponse::class, $response);
		$body = '';
		$output = $this->createMock(IOutput::class);
		$output->method('setOutput')->willReturnCallback(static function (string $chunk) use (&$body): void {
			$body .= $chunk;
		});
		$response->callback($output);

		return $body;
	}//end bodyOf()

	/**
	 * The controller over one register, one schema, groups `admin` and `redactie`, and user `sanne`.
	 *
	 * @param bool $isAdmin Whether the caller is an administrator.
	 *
	 * @return PermissionsAuditController The controller.
	 */
	private function controller(bool $isAdmin): PermissionsAuditController {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('besluit');
		$schema->setAuthorization(['read' => ['redactie']]);
		$register = new Register();
		$register->setSlug('woo');
		$register->setSchemas([1]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$schema]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('enforcing');

		$catalogue = $this->createMock(PermissionCatalogueInterface::class);
		$catalogue->method('verbs')->willReturn(['read', 'update']);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn($isAdmin);
		$groupManager->method('search')->willReturn([$this->group(gid: 'redactie'), $this->group(gid: 'admin')]);
		$groupManager->method('getUserGroupIds')->willReturn(['redactie']);

		$sanne = $this->createMock(IUser::class);
		$sanne->method('getUID')->willReturn('sanne');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForAllUsers')->willReturnCallback(static function (\Closure $callback) use ($sanne): void {
			$callback($sanne);
		});

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->dispatched[] = $event;
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnMap([['instanceid', '', 'oc-instance']]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-24T21:00:00+00:00'));

		return PermissionsAuditControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			catalogue: new \OCA\OpenRegister\Service\Rbac\PermissionCatalogue(),
			enforcement: new DenyEnforcementMode(appConfig: $appConfig, logger: new NullLogger()),
			denyResolver: new DenyResolver(new DenyEntryMatcher()),
			scope: new PermissionReportScope(registerMapper: $registerMapper, schemaMapper: $schemaMapper),
			audit: new \OCA\OpenRegister\Service\Rbac\ScopeAudit(),
			adminGate: new AdminGate(userSession: $session, groupManager: $groupManager),
			matrix: new PermissionsAuditMatrix(
				exporter: new PermissionMatrixExporter(catalogue: $catalogue),
				subjects: new PermissionMatrixSubjects(groupManager: $groupManager, userManager: $userManager),
				dispatcher: $dispatcher,
				userSession: $session,
				config: $config,
				time: $time
			)
		);
	}//end controller()

	/**
	 * A group.
	 *
	 * @param string $gid The group id.
	 *
	 * @return IGroup The group.
	 */
	private function group(string $gid): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);

		return $group;
	}//end group()
}//end class
