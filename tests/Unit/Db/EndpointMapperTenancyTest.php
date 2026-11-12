<?php

/**
 * EndpointMapper tenancy and RBAC behaviour tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Exception;
use OCA\OpenRegister\Db\Endpoint;
use OCA\OpenRegister\Db\EndpointMapper;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\Tenancy\TenancyServices;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reads are scoped to the active organisation; writes pass RBAC and the
 * organisation guard before any statement runs.
 */
class EndpointMapperTenancyTest extends TestCase {
	use RecordsQueries;

	/**
	 * The organisation mapper double.
	 *
	 * @var OrganisationMapperInterface&MockObject
	 */
	private OrganisationMapperInterface&MockObject $organisations;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The mapper under test.
	 *
	 * @var EndpointMapper
	 */
	private EndpointMapper $mapper;

	/**
	 * A signed-in non-admin user whose active organisation is org-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->organisations = $this->createMock(OrganisationMapperInterface::class);
		$this->organisations->method('getActiveOrganisationWithFallback')->willReturn('org-1');
		$this->organisations->method('getOrganisationHierarchy')->willReturn(['org-1']);

		$this->groups = $this->createMock(IGroupManager::class);
		$this->groups->method('isAdmin')->willReturn(false);
		$this->groups->method('getUserGroupIds')->willReturn(['staff']);

		$this->mapper = new EndpointMapper($this->recordingDb(), new TenancyServices($this->organisations, $session, $this->groups));
	}//end setUp()

	/**
	 * Turn on entity RBAC with a policy that grants $action on endpoints to $groups.
	 *
	 * @param string       $action The action.
	 * @param list<string> $groups The granted groups.
	 *
	 * @return void
	 */
	private function enforce(string $action, array $groups): void {
		$org = new Organisation();
		$org->setAuthorization(['endpoint' => [$action => $groups]]);
		$this->organisations->method('isEntityRbacEnforcementEnabled')->willReturn(true);
		$this->organisations->method('findByUuid')->willReturn($org);
	}//end enforce()

	/**
	 * Whether any recorded query ran a statement.
	 *
	 * @return bool
	 */
	private function ranStatement(): bool {
		foreach (array_keys($this->queries) as $index) {
			if (str_contains($this->sql($index), 'executeStatement()') === true) {
				return true;
			}
		}

		return false;
	}//end ranStatement()

	/**
	 * findAll() scopes to the active organisation and adds no paging by default.
	 *
	 * @return void
	 */
	public function testFindAllIsScopedToTheActiveOrganisation(): void {
		$this->results[] = [['id' => 1, 'name' => 'Zaken']];

		$endpoints = $this->mapper->findAll();

		$this->assertSame('Zaken', $endpoints[0]->getName());
		$this->assertStringStartsWith('select(*) from(openregister_endpoints)', $this->sql());
		$this->assertStringContainsString('organisation', $this->sql());
		$this->assertContains('org-1', array_merge(...array_map(static fn ($v): array => (array) $v, $this->boundValues())));
		$this->assertStringNotContainsString('setMaxResults', $this->sql());
		$this->assertStringNotContainsString('setFirstResult', $this->sql());
	}//end testFindAllIsScopedToTheActiveOrganisation()

	/**
	 * findAll() pages when a limit and offset are given.
	 *
	 * @return void
	 */
	public function testFindAllPages(): void {
		$this->mapper->findAll(5, 10);

		$this->assertStringEndsWith('setMaxResults(5) setFirstResult(10) executeQuery()', $this->sql());
	}//end testFindAllPages()

	/**
	 * find() binds the id as an integer inside the organisation scope.
	 *
	 * @return void
	 */
	public function testFindBindsTheIdWithinTheScope(): void {
		$this->results[] = [['id' => 4, 'organisation' => 'org-1']];

		$this->assertSame(4, $this->mapper->find(4)->getId());
		$this->assertStringStartsWith('select(*) from(openregister_endpoints) where(eq(id, :p0))', $this->sql());
		$this->assertStringContainsString('organisation', $this->sql());
		$this->assertSame([4, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindBindsTheIdWithinTheScope()

	/**
	 * createFromArray() mints a uuid, stamps both dates and claims the active organisation.
	 *
	 * @return void
	 */
	public function testCreateFromArrayClaimsTheActiveOrganisation(): void {
		$endpoint = $this->mapper->createFromArray(['name' => 'Zaken', 'organisation' => 'org-other']);

		$this->assertSame(36, strlen((string) $endpoint->getUuid()));
		$this->assertSame('org-1', $endpoint->getOrganisation());
		$this->assertSame($endpoint->getCreated(), $endpoint->getUpdated());
		$this->assertSame(42, $endpoint->getId());
		$this->assertStringStartsWith('insert(openregister_endpoints)', $this->sql());
		$this->assertContains('org-1', $this->boundValues());
	}//end testCreateFromArrayClaimsTheActiveOrganisation()

	/**
	 * createFromArray() keeps a supplied uuid and replaces an empty one.
	 *
	 * @return void
	 */
	public function testCreateFromArrayKeepsASuppliedUuid(): void {
		$kept  = $this->mapper->createFromArray(['uuid' => 'e-kept']);
		$blank = $this->mapper->createFromArray(['uuid' => '']);

		$this->assertSame('e-kept', $kept->getUuid());
		$this->assertSame(36, strlen((string) $blank->getUuid()));
	}//end testCreateFromArrayKeepsASuppliedUuid()

	/**
	 * A user outside the groups the policy grants may not create, and nothing is inserted.
	 *
	 * @return void
	 */
	public function testCreateFromArrayIsRefusedOutsideThePolicy(): void {
		$this->enforce('create', ['editors']);

		try {
			$this->mapper->createFromArray(['name' => 'Zaken']);
			$this->fail('A create outside the RBAC policy must be refused.');
		} catch (Exception $refusal) {
			$this->assertSame(403, $refusal->getCode());
			$this->assertStringContainsString('create endpoint', $refusal->getMessage());
		}

		$this->assertFalse($this->ranStatement());
	}//end testCreateFromArrayIsRefusedOutsideThePolicy()

	/**
	 * A user in a granted group may create under the same policy.
	 *
	 * @return void
	 */
	public function testCreateFromArrayIsAllowedInsideThePolicy(): void {
		$this->enforce('create', ['staff']);

		$endpoint = $this->mapper->createFromArray(['name' => 'Zaken']);

		$this->assertSame(42, $endpoint->getId());
		$this->assertTrue($this->ranStatement());
	}//end testCreateFromArrayIsAllowedInsideThePolicy()

	/**
	 * updateFromArray() never lets the caller change uuid, organisation or created.
	 *
	 * @return void
	 */
	public function testUpdateFromArrayProtectsIdentityFields(): void {
		$this->results[] = [['id' => 7, 'uuid' => 'e-7', 'organisation' => 'org-1', 'name' => 'Old']];

		$endpoint = $this->mapper->updateFromArray(7, ['name' => 'New', 'uuid' => 'hijack', 'organisation' => 'org-2', 'created' => '2000-01-01']);

		$this->assertSame('New', $endpoint->getName());
		$this->assertSame('e-7', $endpoint->getUuid());
		$this->assertSame('org-1', $endpoint->getOrganisation());
		$this->assertStringStartsWith('update(openregister_endpoints)', $this->sql(1));
		$this->assertStringNotContainsString('set(uuid', $this->sql(1));
		$this->assertStringNotContainsString('set(organisation', $this->sql(1));
	}//end testUpdateFromArrayProtectsIdentityFields()

	/**
	 * An endpoint of another organisation cannot be updated.
	 *
	 * @return void
	 */
	public function testUpdateFromArrayRefusesAnotherOrganisation(): void {
		$this->results[] = [['id' => 7, 'organisation' => 'org-2']];

		try {
			$this->mapper->updateFromArray(7, ['name' => 'New']);
			$this->fail('A cross-organisation update must be refused.');
		} catch (Exception $refusal) {
			$this->assertSame(403, $refusal->getCode());
		}

		$this->assertFalse($this->ranStatement());
	}//end testUpdateFromArrayRefusesAnotherOrganisation()

	/**
	 * delete() removes an endpoint of the active organisation.
	 *
	 * @return void
	 */
	public function testDeleteRemovesOwnEndpoint(): void {
		$endpoint = new Endpoint();
		$endpoint->setId(3);
		$endpoint->setOrganisation('org-1');

		$this->assertSame($endpoint, $this->mapper->delete($endpoint));
		$this->assertSame('delete(openregister_endpoints) where(eq(id, :p0)) executeStatement()', $this->sql());
	}//end testDeleteRemovesOwnEndpoint()

	/**
	 * delete() refuses an endpoint of another organisation.
	 *
	 * @return void
	 */
	public function testDeleteRefusesAnotherOrganisation(): void {
		$endpoint = new Endpoint();
		$endpoint->setId(3);
		$endpoint->setOrganisation('org-2');

		try {
			$this->mapper->delete($endpoint);
			$this->fail('A cross-organisation delete must be refused.');
		} catch (Exception $refusal) {
			$this->assertSame(403, $refusal->getCode());
		}

		$this->assertFalse($this->ranStatement());
	}//end testDeleteRefusesAnotherOrganisation()

	/**
	 * delete() is refused by the RBAC policy before the organisation check.
	 *
	 * @return void
	 */
	public function testDeleteIsRefusedOutsideThePolicy(): void {
		$this->enforce('delete', ['admins-only']);
		$endpoint = new Endpoint();
		$endpoint->setId(3);
		$endpoint->setOrganisation('org-1');

		try {
			$this->mapper->delete($endpoint);
			$this->fail('A delete outside the RBAC policy must be refused.');
		} catch (Exception $refusal) {
			$this->assertStringContainsString('delete endpoint', $refusal->getMessage());
		}

		$this->assertFalse($this->ranStatement());
	}//end testDeleteIsRefusedOutsideThePolicy()
}//end class
