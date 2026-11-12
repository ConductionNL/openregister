<?php

/**
 * Golden outcomes of the authorization-model reports, allowed and refused.
 *
 * Pins status and full body of denyPreview, scopeAudit and compareRoles for
 * an administrator over registers and schemas with every deny and role shape
 * the reports read, and the 403 / 404 refusals. Recorded in
 * fixtures/permissions-audit-a24-golden.json from the code before the A24
 * restructuring; UPDATE_GOLDEN=1 re-records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\PermissionsAuditController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\AdminGate;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\PermissionReportScope;
use OCA\OpenRegister\Service\Rbac\ScopeAudit;
use OCA\OpenRegister\Tests\Unit\Controller\Support\PermissionsAuditControllerFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Allowed and refused outcomes of the permissions audit reports.
 */
final class PermissionsAuditControllerA24GoldenTest extends TestCase {

	/**
	 * The recording.
	 *
	 * @var string
	 */
	private const FIXTURE = __DIR__ . '/fixtures/permissions-audit-a24-golden.json';

	private function register(): Register {
		$register = new Register();
		$register->setId(1);
		$register->setTitle('Zaken');
		$register->setSlug('zaken');
		$register->setAuthorization(
			[
				'read' => ['behandelaars'],
				'deny' => [
					'read' => ['waarnemers', ['group' => 'stagiairs'], ['role' => ''], ['user' => 'piet', 'match' => ['status' => 'geheim']], 42],
					'delete' => [['principal' => 'iedereen'], ['nothing' => 'here']],
					'not-a-verb' => ['x'],
					'update' => 'not a list',
					7 => ['numeric action'],
				],
			]
		);
		$register->setConfiguration(
			[
				'roles' => [
					['name' => 'behandelaar', 'actions' => ['read', 'update', 'not-a-verb', 5]],
					['name' => 'senior', 'actions' => ['read', 'update', 'delete']],
					['name' => 'leeg', 'actions' => 'not a list'],
					['actions' => ['read']],
					'not an array',
				],
			]
		);

		return $register;
	}//end register()

	private function bareRegister(): Register {
		$register = new Register();
		$register->setId(2);
		$register->setTitle('Zonder slug');
		$register->setAuthorization(['deny' => ['read' => ['extern']]]);
		$register->setConfiguration(['roles' => 'not a list']);

		return $register;
	}//end bareRegister()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Zaak');
		$schema->setSlug('zaak');
		$schema->setAuthorization(['create' => ['behandelaars'], 'deny' => ['create' => [['group' => 'extern', 'match' => ['a' => 1]]]]]);

		return $schema;
	}//end schema()

	private function controller(bool $isAdmin, array $registers): PermissionsAuditController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn(DenyEnforcementMode::MODE_STAGING);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn($registers);
		$registerMapper->method('find')->willReturnCallback(
			static function () use ($registers) {
				if ($registers === []) {
					throw new \RuntimeException('no such register');
				}

				return $registers[0];
			}
		);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn([$this->schema()]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return PermissionsAuditControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(IRequest::class),
			catalogue: new PermissionCatalogue(),
			enforcement: new DenyEnforcementMode(appConfig: $appConfig, logger: new NullLogger()),
			denyResolver: new DenyResolver(new DenyEntryMatcher()),
			scope: new PermissionReportScope(registerMapper: $registerMapper, schemaMapper: $schemaMapper),
			audit: new ScopeAudit(),
			adminGate: new AdminGate(userSession: $session, groupManager: $groups)
		);
	}//end controller()

	private function outcome($response): array {
		return ['status' => $response->getStatus(), 'data' => $response->getData()];
	}//end outcome()

	public function testTheReportsAreRecorded(): void {
		$full = [$this->register(), $this->bareRegister()];
		$actual = [
			'denyPreview' => $this->outcome($this->controller(true, $full)->denyPreview()),
			'scopeAudit' => $this->outcome($this->controller(true, $full)->scopeAudit()),
			'compareAll' => $this->outcome($this->controller(true, $full)->compareRoles('1')),
			'compareTwo' => $this->outcome($this->controller(true, $full)->compareRoles('1', ' senior , behandelaar,,ghost ')),
			'compareBlank' => $this->outcome($this->controller(true, $full)->compareRoles('1', '  ')),
			'compareBare' => $this->outcome($this->controller(true, [$this->bareRegister()])->compareRoles('2', 'x')),
			'compareMissing' => $this->outcome($this->controller(true, [])->compareRoles('geen')),
			'refusedPreview' => $this->outcome($this->controller(false, $full)->denyPreview()),
			'refusedAudit' => $this->outcome($this->controller(false, $full)->scopeAudit()),
			'refusedCompare' => $this->outcome($this->controller(false, $full)->compareRoles('1')),
		];
		$actual = json_decode((string)json_encode($actual), true);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
		}

		$this->assertSame(json_decode((string)file_get_contents(self::FIXTURE), true), $actual);
	}//end testTheReportsAreRecorded()

	public function testANonAdminIsRefusedBeforeAnythingIsRead(): void {
		$response = $this->controller(false, [$this->register()])->denyPreview('zaken', 'zaak');

		$this->assertSame(403, $response->getStatus());
		$this->assertSame([403, 403], [$response->getStatus(), $this->controller(false, [])->compareRoles('geen')->getStatus()]);
	}//end testANonAdminIsRefusedBeforeAnythingIsRead()
}//end class
