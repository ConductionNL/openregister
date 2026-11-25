<?php

/**
 * The entity plane honours an explicit `public` grant, and nothing else, for an anonymous caller.
 *
 * Asserted as a PAIR per verb: the same organisation rule with `public`
 * admits an anonymous caller, without it refuses. A change that refused
 * everybody would pass the refusal half alone. Every way of not declaring
 * `public` (no organisation, no rule, an empty rule, another group, the `*`
 * wildcard, the enforcement flag being off) still refuses, and a refusal is
 * logged once per (entity type, action, cause), not once per row.
 *
 * PHPUnit runs under the CLI SAPI, which the trait trusts, so every anonymous
 * decision here is asked inside AnonymousEvaluationContext.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-an-entity-may-declare-anonymous-access-and-it-must-be-honoured
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Tenancy;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MultiTenancyTrait;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\Rbac\AuthorizationRuleEvaluator;
use OCP\IGroupManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\Tenancy\EntityRbacTrait
 * @uses   \OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait
 * @uses   \OCA\OpenRegister\Service\Rbac\AuthorizationRuleEvaluator
 * @uses   \OCA\OpenRegister\Service\AnonymousEvaluationContext
 * @uses   \OCA\OpenRegister\Service\SystemOperationContext
 */
class EntityPlanePublicGrantTest extends TestCase {

	private const VERBS = ['create', 'read', 'update', 'delete'];

	/** @var OrganisationMapper&MockObject */
	private OrganisationMapper $organisationMapper;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		(new AuthorizationRuleEvaluator())->forgetRecordedRefusals();
		$this->organisationMapper = $this->createMock(OrganisationMapper::class);
		$this->organisationMapper->method('getDefaultOrganisationFromConfig')->willReturn('org-uuid');
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	protected function tearDown(): void {
		(new AuthorizationRuleEvaluator())->forgetRecordedRefusals();
	}

	/**
	 * A host composing the trait exactly as a mapper does, with no session user.
	 */
	private function anonymousHost(): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$host = new class {
			use MultiTenancyTrait;

			public $userSession;
			public $organisationMapper;
			public $groupManager;
			public $logger;

			public function decide(string $action, string $entityType): bool {
				return $this->hasRbacPermission(action: $action, entityType: $entityType);
			}
		};
		$host->userSession = $session;
		$host->organisationMapper = $this->organisationMapper;
		$host->groupManager = $this->createMock(IGroupManager::class);
		$host->logger = $this->logger;

		return $host;
	}

	private function organisationWith(array $authorization): void {
		$organisation = new Organisation();
		$organisation->setAuthorization($authorization);
		$this->organisationMapper->method('findByUuid')->with('org-uuid')->willReturn($organisation);
	}

	private function anonymously(object $host, string $action, string $entityType): bool {
		return AnonymousEvaluationContext::run(static fn (): bool => $host->decide($action, $entityType));
	}

	public function testAPublicGrantAdmitsAnAnonymousCallerAndItsAbsenceRefusesPerVerb(): void {
		foreach (self::VERBS as $verb) {
			$this->setUp();
			$this->organisationWith(['register' => [$verb => ['public']], 'schema' => [$verb => ['editors']]]);
			$host = $this->anonymousHost();

			$this->assertTrue($this->anonymously(host: $host, action: $verb, entityType: 'register'), 'register ' . $verb . ' granted to public');
			$this->assertFalse($this->anonymously(host: $host, action: $verb, entityType: 'schema'), 'schema ' . $verb . ' not granted to public');
		}
	}

	public function testAGroupObjectNamingPublicAlsoAdmits(): void {
		$this->organisationWith(['view' => ['read' => [['group' => 'public']]]]);

		$this->assertTrue($this->anonymously(host: $this->anonymousHost(), action: 'read', entityType: 'view'));
	}

	public function testAGrantForOneVerbDoesNotOpenTheOthers(): void {
		$this->organisationWith(['register' => ['read' => ['public']]]);
		$host = $this->anonymousHost();

		$this->assertTrue($this->anonymously(host: $host, action: 'read', entityType: 'register'));
		foreach (['create', 'update', 'delete'] as $verb) {
			$this->assertFalse($this->anonymously(host: $host, action: $verb, entityType: 'register'), 'register ' . $verb . ' must stay fail-closed');
		}
	}

	/**
	 * No entity gains access by this change: the default organisation
	 * authorization declares no `public`, so every entity type, by name, still
	 * refuses every verb.
	 */
	public function testTheDefaultAuthorizationStillRefusesEveryEntityByName(): void {
		$this->organisationMapper->method('findByUuid')->willReturn(new Organisation());
		$host = $this->anonymousHost();

		foreach (['register', 'schema', 'object', 'view', 'agent', 'configuration', 'application', 'webhook', 'source', 'mapping', 'endpoint'] as $entityType) {
			foreach (self::VERBS as $verb) {
				$this->assertFalse($this->anonymously(host: $host, action: $verb, entityType: $entityType), $entityType . ' ' . $verb);
			}
		}
	}

	public function testEveryOtherShapeOfRuleRefuses(): void {
		$this->organisationWith(['register' => ['read' => ['*'], 'update' => [], 'delete' => ['authenticated']]]);
		$host = $this->anonymousHost();

		$this->assertFalse($this->anonymously(host: $host, action: 'read', entityType: 'register'), 'the wildcard is not public');
		$this->assertFalse($this->anonymously(host: $host, action: 'update', entityType: 'register'), 'an empty rule is not public');
		$this->assertFalse($this->anonymously(host: $host, action: 'delete', entityType: 'register'), 'authenticated is not public');
		$this->assertFalse($this->anonymously(host: $host, action: 'create', entityType: 'register'), 'no rule is not public');
	}

	public function testTheEnforcementFlagBeingOffDoesNotOpenAnything(): void {
		$this->organisationMapper->method('isEntityRbacEnforcementEnabled')->willReturn(false);
		$this->organisationWith(['register' => ['read' => ['editors']]]);

		$this->assertFalse($this->anonymously(host: $this->anonymousHost(), action: 'read', entityType: 'register'));
	}

	public function testAnOrganisationThatCannotBeLoadedRefuses(): void {
		$this->organisationMapper->method('findByUuid')->willThrowException(new \RuntimeException('gone'));

		$this->assertFalse($this->anonymously(host: $this->anonymousHost(), action: 'read', entityType: 'register'));
	}

	public function testARefusalIsLoggedOncePerKindNamingTheCause(): void {
		$this->organisationWith(['register' => ['read' => ['editors']]]);
		$logged = [];
		$this->logger->method('info')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[] = [$message, $context];
		});
		$host = $this->anonymousHost();

		for ($row = 0; $row < 5; $row++) {
			$this->anonymously(host: $host, action: 'read', entityType: 'register');
		}
		$this->anonymously(host: $host, action: 'update', entityType: 'register');

		$this->assertCount(2, $logged);
		$this->assertStringContainsString('`public` group', $logged[0][0], 'the message names the remedy');
		$this->assertSame(['entity', 'register', 'read', 'rule-refused', true], [$logged[0][1]['plane'], $logged[0][1]['entityType'], $logged[0][1]['action'], $logged[0][1]['cause'], $logged[0][1]['authorizationDeclared']]);
		$this->assertSame(['update', 'no-rule', false], [$logged[1][1]['action'], $logged[1][1]['cause'], $logged[1][1]['authorizationDeclared']]);
	}

	public function testAGrantIsNotLogged(): void {
		$this->organisationWith(['register' => ['read' => ['public']]]);
		$this->logger->expects($this->never())->method('info');

		$this->assertTrue($this->anonymously(host: $this->anonymousHost(), action: 'read', entityType: 'register'));
	}
}
