<?php

/**
 * The contract of the three permission reads.
 *
 * These are the endpoints a role editor and an auditor call, so the shape is
 * the product. A field renamed here breaks a consumer that cannot be seen from
 * this repo, which is why the assertions name keys rather than counting them.
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

use OCA\OpenRegister\Controller\PermissionsController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rbac\DenyEnforcementMode;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogue;
use OCA\OpenRegister\Service\Rbac\ScopeAudit;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The catalogue, the staged deny report and the role comparison, over the wire.
 *
 * @spec openspec/changes/permission-provenance-and-deny/specs/rbac-scopes/spec.md
 */
class PermissionsControllerTest extends TestCase {

	/**
	 * A controller reading one register and one schema, in one deny mode.
	 *
	 * @param string       $mode      One of DenyEnforcementMode::MODES.
	 * @param Register[]   $registers The registers the mappers answer with.
	 * @param Schema[]     $schemas   The schemas the mappers answer with.
	 *
	 * @return PermissionsController The controller under test.
	 */
	private function controllerFor(
		string $mode,
		array $registers = [],
		array $schemas = [],
	): PermissionsController {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($mode);

		$registerMapper = $this->createMock(originalClassName: RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn($registers);
		$registerMapper->method('find')->willReturnCallback(
			static function () use ($registers) {
				if ($registers === []) {
					throw new \RuntimeException('no such register');
				}

				return $registers[0];
			}
		);

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn($schemas);

		return new PermissionsController(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			catalogue: new PermissionCatalogue(),
			enforcement: new DenyEnforcementMode(appConfig: $appConfig, logger: new NullLogger()),
			denyResolver: new DenyResolver(new DenyEntryMatcher()),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			audit: new ScopeAudit()
		);
	}//end controllerFor()

	/**
	 * A register carrying an authorization block and a role set.
	 *
	 * @param array|null $authorization  The block.
	 * @param array      $configuration  The register configuration.
	 *
	 * @return Register The register.
	 */
	private function registerWith(?array $authorization, array $configuration = []): Register {
		$register = new Register();
		$register->setId(1);
		$register->setTitle('Zaken');
		$register->setSlug('zaken');
		$register->setAuthorization($authorization);
		$register->setConfiguration($configuration);

		return $register;
	}//end registerWith()

	/**
	 * A schema carrying an authorization block.
	 *
	 * @param array|null $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schemaWith(?array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Zaak');
		$schema->setSlug('zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schemaWith()

	/**
	 * 🔴 The scope audit names the rule behind every principal, not only the set.
	 *
	 * The audit answered per schema and per action before this: which groups
	 * hold `read` on `zaak`. A reviewer who finds a group they did not expect
	 * then has to discover WHERE it was granted, across four levels, and that
	 * search is the expensive half of an access review.
	 *
	 * @return void
	 */
	public function testTheScopeAuditReportsThePrincipalsWithTheirRules(): void {
		$register = $this->registerWith(
			['read' => ['directie']],
			['roles' => [['name' => 'behandelaar', 'actions' => ['read', 'update']]]]
		);
		$register->setSchemas([7]);

		$schema = $this->schemaWith(
			[
				'roles' => ['behandelaar' => ['behandelaars']],
				'deny' => ['update' => ['waarnemers']],
			]
		);

		$response = $this->controllerFor(
			mode: DenyEnforcementMode::MODE_STAGING,
			registers: [$register],
			schemas: [$schema]
		)->scopeAudit();

		$this->assertSame(200, $response->getStatus());
		$body = $response->getData();

		foreach (['denyEnforcement', 'schemaCount', 'scopes'] as $key) {
			$this->assertArrayHasKey($key, $body, sprintf('the audit lost its "%s" key', $key));
		}

		$this->assertSame(1, $body['schemaCount']);
		$row = $body['scopes'][0];
		$this->assertSame('zaken', $row['register']);
		$this->assertSame('zaak', $row['schema']);

		// Per rule: the role grant names the role and the level it is written
		// at, which is what the old per-action answer could not say.
		$byPrincipal = [];
		foreach ($row['holders'] as $holder) {
			$byPrincipal[$holder['principal']] = $holder;
		}

		$this->assertSame(['behandelaars', 'directie'], array_keys($byPrincipal));
		$this->assertSame('behandelaar', $byPrincipal['behandelaars']['rules'][0]['role']);
		$this->assertSame('schema', $byPrincipal['behandelaars']['rules'][0]['level']);
		$this->assertSame('register', $byPrincipal['directie']['rules'][0]['level']);

		// Per schema and per action, still: the shape the audit answered in
		// before, so nothing reading it has to change.
		$this->assertSame(['behandelaars', 'directie'], $row['byAction']['read']);
		$this->assertSame(['behandelaars'], $row['byAction']['update']);

		// And the deny beside the grants, with the mode that says whether it
		// is biting yet.
		$this->assertSame('waarnemers', $row['denied'][0]['principal']);
		$this->assertSame('update', $row['denied'][0]['action']);
		$this->assertSame(DenyEnforcementMode::MODE_STAGING, $body['denyEnforcement']);
	}//end testTheScopeAuditReportsThePrincipalsWithTheirRules()

	/**
	 * A schema that belongs to no register in the report is not reported.
	 *
	 * The control: without it, the case above could be passing because the
	 * audit reports every schema it can see against every register it can see.
	 *
	 * @return void
	 */
	public function testTheAuditOnlyReportsSchemasOfTheRegisterItIsReading(): void {
		$register = $this->registerWith(['read' => ['directie']]);
		$register->setSchemas([99]);

		$response = $this->controllerFor(
			mode: DenyEnforcementMode::MODE_STAGING,
			registers: [$register],
			schemas: [$this->schemaWith(['read' => ['behandelaars']])]
		)->scopeAudit();

		$this->assertSame(0, $response->getData()['schemaCount']);
	}//end testTheAuditOnlyReportsSchemasOfTheRegisterItIsReading()

	/**
	 * The catalogue publishes the seven canonical verbs with their descriptions.
	 *
	 * @return void
	 */
	public function testTheCatalogueIsPublishedWithItsShape(): void {
		$response = $this->controllerFor(mode: DenyEnforcementMode::MODE_STAGING)->index();

		$this->assertSame(200, $response->getStatus());
		$body = $response->getData();

		$this->assertArrayHasKey('permissions', $body);
		$this->assertArrayHasKey('denyEnforcement', $body);
		$this->assertSame(DenyEnforcementMode::MODE_STAGING, $body['denyEnforcement']);

		$verbs = array_column($body['permissions'], 'verb');
		$this->assertSame(
			expected: ['read', 'create', 'update', 'delete', 'destroy', 'list', 'export', 'manage'],
			actual: $verbs
		);

		foreach ($body['permissions'] as $entry) {
			foreach (['verb', 'app', 'description', 'levels', 'canonical'] as $key) {
				$this->assertArrayHasKey($key, $entry, sprintf('a catalogue entry lost its "%s" key', $key));
			}
		}
	}//end testTheCatalogueIsPublishedWithItsShape()

	/**
	 * 🔴 The preview reports a deny that has never fired.
	 *
	 * The whole reason it reads the rules rather than a log: a deny nobody has
	 * exercised is exactly the one that surprises an administrator on the day
	 * they flip the switch, and an accumulated log cannot contain it.
	 *
	 * @return void
	 */
	public function testTheDenyPreviewReportsARuleNobodyHasHit(): void {
		$controller = $this->controllerFor(
			mode: DenyEnforcementMode::MODE_STAGING,
			registers: [$this->registerWith(['read' => ['behandelaars']])],
			schemas: [$this->schemaWith(['read' => ['behandelaars'], 'deny' => ['read' => ['waarnemers']]])]
		);

		$body = $controller->denyPreview()->getData();

		$this->assertSame(DenyEnforcementMode::MODE_STAGING, $body['denyEnforcement']);
		$this->assertFalse($body['enforcing']);
		$this->assertSame(1, $body['ruleCount']);

		$rule = $body['rules'][0];
		$this->assertSame('schema', $rule['level']);
		$this->assertSame('zaak', $rule['subject']);
		$this->assertSame('read', $rule['action']);
		$this->assertSame('waarnemers', $rule['principal']);
		$this->assertFalse($rule['conditional']);
		$this->assertTrue($rule['declared']);
	}//end testTheDenyPreviewReportsARuleNobodyHasHit()

	/**
	 * A register with no deny reports an empty preview, not an error.
	 *
	 * The control for the case above: without it, a report of one rule could be
	 * a report of every rule.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoDenyPreviewsNothing(): void {
		$controller = $this->controllerFor(
			mode: DenyEnforcementMode::MODE_STAGING,
			registers: [$this->registerWith(['read' => ['behandelaars']])],
			schemas: [$this->schemaWith(['read' => ['behandelaars']])]
		);

		$body = $controller->denyPreview()->getData();

		$this->assertSame(0, $body['ruleCount']);
		$this->assertSame([], $body['rules']);
	}//end testAnInstanceWithNoDenyPreviewsNothing()

	/**
	 * A conditional deny is reported with its clause, not summarised away.
	 *
	 * "Some rows" is a different promise from "every row", and the difference
	 * is invisible in a count.
	 *
	 * @return void
	 */
	public function testAConditionalDenyKeepsItsMatchClauseInTheReport(): void {
		$controller = $this->controllerFor(
			mode: DenyEnforcementMode::MODE_ENFORCING,
			registers: [$this->registerWith(null)],
			schemas: [
				$this->schemaWith(
					['deny' => ['read' => [['group' => 'waarnemers', 'match' => ['status' => 'geheim']]]]]
				),
			]
		);

		$body = $controller->denyPreview()->getData();

		$this->assertTrue($body['enforcing']);
		$this->assertSame(1, $body['ruleCount']);
		$this->assertTrue($body['rules'][0]['conditional']);
		$this->assertSame(['status' => 'geheim'], $body['rules'][0]['match']);
		$this->assertSame('waarnemers', $body['rules'][0]['principal']);
	}//end testAConditionalDenyKeepsItsMatchClauseInTheReport()

	/**
	 * 🔴 Two roles compared name the verbs only one of them holds.
	 *
	 * The auditor's question. Diffing two role definitions by eye is how a role
	 * quietly acquires a verb nobody meant it to have.
	 *
	 * @return void
	 */
	public function testTwoRolesAreComparedAgainstTheCatalogue(): void {
		$controller = $this->controllerFor(
			mode: DenyEnforcementMode::MODE_STAGING,
			registers: [
				$this->registerWith(
					null,
					[
						'roles' => [
							['name' => 'behandelaar', 'actions' => ['read', 'update']],
							['name' => 'senior', 'actions' => ['read', 'update', 'delete', 'approve']],
						],
					]
				),
			]
		);

		$body = $controller->compareRoles(register: 'zaken')->getData();

		$this->assertSame(['read', 'update'], $body['shared']);
		$this->assertSame([], $body['onlyIn']['behandelaar']);
		$this->assertSame(['delete', 'approve'], array_values($body['onlyIn']['senior']));

		// `approve` is in the role and in no app's declaration, so it is
		// reported as undeclared rather than as a right the senior holds.
		$this->assertSame(['approve'], $body['roles']['senior']['undeclared']);
		$this->assertSame([], $body['roles']['behandelaar']['undeclared']);
	}//end testTwoRolesAreComparedAgainstTheCatalogue()

	/**
	 * A role nobody declared is named as unknown rather than silently dropped.
	 *
	 * @return void
	 */
	public function testAnUnknownRoleNameIsReportedBack(): void {
		$controller = $this->controllerFor(
			mode: DenyEnforcementMode::MODE_STAGING,
			registers: [
				$this->registerWith(null, ['roles' => [['name' => 'behandelaar', 'actions' => ['read']]]]),
			]
		);

		$body = $controller->compareRoles(register: 'zaken', roles: 'behandelaar,directeur')->getData();

		$this->assertSame(['directeur'], $body['unknownRoles']);
		$this->assertArrayHasKey('behandelaar', $body['roles']);
		$this->assertArrayNotHasKey('directeur', $body['roles']);
	}//end testAnUnknownRoleNameIsReportedBack()

	/**
	 * An unknown register answers 404 rather than an empty comparison.
	 *
	 * An empty comparison and a missing register read identically on a screen,
	 * and only one of them is the caller's mistake.
	 *
	 * @return void
	 */
	public function testAnUnknownRegisterIsRefusedWith404(): void {
		$response = $this->controllerFor(mode: DenyEnforcementMode::MODE_STAGING)
			->compareRoles(register: 'geen-register');

		$this->assertSame(404, $response->getStatus());
		$this->assertStringContainsString('geen-register', $response->getData()['error']);
	}//end testAnUnknownRegisterIsRefusedWith404()
}//end class
