<?php

/**
 * Degraded-source tests for PrincipalReachService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\DelegationGrantMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Object\PermissionExplanationInterface;
use OCA\OpenRegister\Service\Object\ProvenanceRequest;
use OCA\OpenRegister\Service\Object\VerbListing;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\PrincipalDirectory;
use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use OCA\OpenRegister\Service\Rbac\ReachSources;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When a source cannot be read, the listing shrinks to what could be read and says so
 * in the log; it never invents reach.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\PrincipalReachService
 */
class PrincipalReachServiceDegradedSourcesTest extends TestCase {

	/**
	 * @var PermissionExplanationInterface&MockObject
	 */
	private PermissionExplanationInterface $permissions;

	/**
	 * @var SchemaMapperInterface&MockObject
	 */
	private SchemaMapperInterface $schemas;

	/**
	 * @var DerivedGrantStore&MockObject
	 */
	private DerivedGrantStore $derived;

	/**
	 * @var DelegationGrantMapperInterface&MockObject
	 */
	private DelegationGrantMapperInterface $delegations;

	/**
	 * @var IUserManager&MockObject
	 */
	private IUserManager $users;

	/**
	 * Logged messages.
	 *
	 * @var string[]
	 */
	private array $logged = [];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->permissions = $this->createMock(PermissionExplanationInterface::class);
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->derived     = $this->createMock(DerivedGrantStore::class);
		$this->delegations = $this->createMock(DelegationGrantMapperInterface::class);
		$this->users       = $this->createMock(IUserManager::class);
	}//end setUp()

	/**
	 * List the reach of `ghost`, who has no account.
	 *
	 * @return array<string, mixed>
	 */
	private function list(): array {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = $level . ' ' . $message;
				}
			);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->never())->method('getUserGroupIds');
		$this->users->method('get')->willReturn(null);
		$this->users->method('userExists')->willReturn(false);

		$service = new PrincipalReachService(
			permissions: $this->permissions,
			sources: new ReachSources(schemaMapper: $this->schemas, derived: $this->derived, delegations: $this->delegations),
			directory: new PrincipalDirectory(userManager: $this->users, groupManager: $groups),
			logger: $logger
		);

		return $service->listFor(principal: ' ghost ');
	}//end list()

	/**
	 * Every source failing leaves an empty, fully-zeroed listing and logs each failure.
	 *
	 * @return void
	 */
	public function testEverySourceFailingYieldsAnEmptyListing(): void {
		$this->schemas->method('findAll')->willThrowException(new RuntimeException('db'));
		$this->permissions->expects($this->never())->method('permittedActionsFor');
		$this->derived->method('grantsFor')->willThrowException(new RuntimeException('cache'));
		$this->delegations->method('findHeldBy')->willThrowException(new RuntimeException('db'));

		$listing = $this->list();

		$this->assertSame('ghost', $listing['principal']);
		$this->assertFalse($listing['exists']);
		$this->assertSame(0, $listing['total']);
		$this->assertSame(0, $listing['revocable']);
		$this->assertSame(0, $listing['retained']);
		$this->assertSame([], $listing['entries']);
		$this->assertSame(0, array_sum($listing['bySource']));
		$this->assertSame(
			[
				'error [PrincipalReach] Could not list the schemas, so the reach cannot be answered',
				'warning [PrincipalReach] Could not read the derived grants',
				'warning [PrincipalReach] Could not read the delegations held',
			],
			$this->logged
		);
	}//end testEverySourceFailingYieldsAnEmptyListing()

	/**
	 * A schema granting nothing is not listed; one whose provenance cannot be read is
	 * listed without a `grantedBy`.
	 *
	 * @return void
	 */
	public function testProvenanceFailureKeepsTheGrantWithoutItsRule(): void {
		$none = new Schema();
		$none->setId(1);
		$granting = new Schema();
		$granting->setId(2);
		$granting->setTitle('Cases');
		$this->schemas->method('findAll')->willReturn([$none, $granting]);
		$this->permissions->method('permittedActionsFor')->willReturnCallback(
			static fn (VerbListing $listing): array => $listing->schema->getId() === 2 ? ['read', 'update'] : []
		);
		$this->permissions->expects($this->once())->method('provenanceFor')
			->with(new ProvenanceRequest($granting, ['read', 'update'], 'ghost', null))
			->willThrowException(new RuntimeException('resolver'));
		$this->derived->method('grantsFor')->willReturn([]);
		$this->delegations->method('findHeldBy')->willReturn([]);

		$listing = $this->list();

		$this->assertSame(2, $listing['total']);
		$this->assertSame(2, $listing['revocable']);
		$this->assertSame(2, $listing['bySource']['authorization']);
		$this->assertSame(
			['source' => 'authorization', 'revocable' => true, 'schema' => 2, 'schemaTitle' => 'Cases', 'action' => 'update', 'grantedBy' => null],
			$listing['entries'][1]
		);
		$this->assertSame(['warning [PrincipalReach] Could not read the provenance of a grant'], $this->logged);
	}//end testProvenanceFailureKeepsTheGrantWithoutItsRule()

	/**
	 * A derived grant is listed as revocable reach.
	 *
	 * @return void
	 */
	public function testDerivedGrantsAreListed(): void {
		$this->schemas->method('findAll')->willReturn([]);
		$this->derived->method('grantsFor')->with('ghost')->willReturn([['schema' => 4, 'action' => 'read']]);
		$this->delegations->method('findHeldBy')->willReturn([]);

		$listing = $this->list();

		$this->assertSame(
			[['source' => 'derived', 'revocable' => true, 'grant' => ['schema' => 4, 'action' => 'read']]],
			$listing['entries']
		);
		$this->assertSame(1, $listing['bySource']['derived']);
	}//end testDerivedGrantsAreListed()
}//end class
