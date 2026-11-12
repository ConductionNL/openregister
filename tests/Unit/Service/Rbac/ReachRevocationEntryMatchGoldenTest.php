<?php

/**
 * Characterisation tests for which authorization entries a reach revocation
 * treats as naming the revoked account.
 *
 * Pins, through `revokeAll()`, every entry shape the grammar allows: the bare
 * id and its `user:` form, the constrained form under each of its name keys
 * (`name`, `principal`, `id`, `user`, any of which may match), entries naming
 * somebody else, near-misses (case, padding, another key), and non-string or
 * non-array entries — removed entries go, everything else is kept as written.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use OCA\OpenRegister\Service\Rbac\ReachRevocationLog;
use OCA\OpenRegister\Service\Rbac\ReachRevocationService;
use OCA\OpenRegister\Service\Rbac\ReachSources;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Golden tests for the entry match of a reach revocation.
 */
class ReachRevocationEntryMatchGoldenTest extends TestCase {

	/**
	 * Revoke `anja` from one schema with this authorization block.
	 *
	 * @param array<string, mixed> $authorization The block.
	 *
	 * @return array<int, array<string, mixed>> Every block written back.
	 */
	private function revokeFrom(array $authorization): array {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		$written = [];
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturn([$schema]);
		$schemas->method('update')->willReturnCallback(
			static function (Schema $updated) use (&$written): Schema {
				$written[] = $updated->getAuthorization();
				return $updated;
			}
		);
		$reach = $this->createMock(PrincipalReachService::class);
		$reach->method('listFor')->willReturn(['total' => 0, 'entries' => []]);
		$store = $this->createMock(DerivedGrantStore::class);
		$store->method('grantsFor')->willReturn([]);
		$delegations = $this->createMock(DelegationGrantMapper::class);
		$delegations->method('findHeldBy')->willReturn([]);

		$service = new ReachRevocationService(
			reach: $reach,
			sources: new ReachSources(schemaMapper: $schemas, derived: $store, delegations: $delegations),
			log: new ReachRevocationLog(audit: $this->createMock(AuthorizationAuditService::class), logger: new NullLogger()),
			userSession: $this->createMock(IUserSession::class)
		);
		$service->revokeAll('anja');

		return $written;
	}//end revokeFrom()

	/**
	 * Every entry naming the account goes; every other entry stays as written.
	 *
	 * @return void
	 */
	public function testEveryEntryShapeIsMatchedAsBefore(): void {
		$written = $this->revokeFrom(
			[
				'read' => [
					'anja',
					'user:anja',
					['name' => 'anja', 'until' => '2026-12-31'],
					['principal' => 'user:anja'],
					['id' => 'anja'],
					['user' => 'anja'],
					['name' => 5, 'id' => 'anja'],
					['name' => 'bob', 'user' => 'user:anja'],
					'bob',
					'user:bob',
					'Anja',
					' anja',
					'group:anja',
					['other' => 'anja'],
					['name' => ['anja']],
					['name' => 'bob'],
					7,
					null,
				],
				'update' => ['anja'],
				'delete' => 'anja',
			]
		);

		$this->assertSame(
			[
				[
					'read' => [
						'bob',
						'user:bob',
						'Anja',
						' anja',
						'group:anja',
						['other' => 'anja'],
						['name' => ['anja']],
						['name' => 'bob'],
						7,
						null,
					],
					'update' => [],
					'delete' => 'anja',
				],
			],
			$written
		);
	}//end testEveryEntryShapeIsMatchedAsBefore()

	/**
	 * A block naming only somebody else is never written.
	 *
	 * @return void
	 */
	public function testABlockWithoutTheAccountIsNotWritten(): void {
		$this->assertSame([], $this->revokeFrom(['read' => ['bob', ['name' => 'user:bob'], 7]]));
	}//end testABlockWithoutTheAccountIsNotWritten()
}//end class
