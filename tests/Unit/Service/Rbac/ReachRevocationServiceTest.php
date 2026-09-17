<?php

/**
 * Unit tests for the one revocation act.
 *
 * Covers the spec delta's "the revocation is recorded in full": each grant is
 * removed and one record names all of them. Also covers the rule that decides
 * what the act may touch at all (ADR-010): a group-sourced reach is reported as
 * retained with the group named, never quietly counted as removed.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Db\DelegationGrantMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\AuthorizationAuditService;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rbac\PrincipalReach;
use OCA\OpenRegister\Service\Rbac\PrincipalReachService;
use OCA\OpenRegister\Service\Rbac\ReachRevocationService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReachRevocationServiceTest extends TestCase {
	private const PRINCIPAL = 'anja';

	/** @var array<int, string> Every delegation uuid actually revoked. */
	private array $revoked = [];

	/** @var bool Whether the derived grants were forgotten. */
	private bool $forgot = false;

	/** @var array<int, array> Every schema authorization block actually written. */
	private array $written = [];

	protected function setUp(): void {
		$this->revoked = [];
		$this->forgot = false;
		$this->written = [];
	}//end setUp()

	/**
	 * A schema whose authorization block names the principal directly.
	 *
	 * @param array<string, mixed> $authorization The block.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $authorization): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setTitle('Zaak');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * A live delegation held by the principal.
	 *
	 * @return DelegationGrant The grant.
	 */
	private function delegation(): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setUuid('deleg-1');
		$grant->setActingAs('bram');
		$grant->setStatus(DelegationGrant::STATUS_GRANTED);
		$grant->setExpiresAt(new DateTime('+30 days'));

		return $grant;
	}//end delegation()

	/**
	 * Assemble the act over staged collaborators.
	 *
	 * @param array<int, Schema>          $schemas   The schemas.
	 * @param array<int, array>           $derived   Derived grants held.
	 * @param array<int, DelegationGrant> $delegated Delegations held.
	 * @param array<string, mixed>        $listing   What the reach listing returns.
	 *
	 * @return ReachRevocationService The assembled act.
	 */
	private function service(
		array $schemas = [],
		array $derived = [],
		array $delegated = [],
		array $listing = ['total' => 0, 'entries' => []],
	): ReachRevocationService {
		$reach = $this->createMock(PrincipalReachService::class);
		$reach->method('listFor')->willReturn($listing);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findAll')->willReturn($schemas);
		$schemaMapper->method('update')->willReturnCallback(
			function (Schema $schema): Schema {
				$this->written[] = ($schema->getAuthorization() ?? []);

				return $schema;
			}
		);

		$store = $this->createMock(DerivedGrantStore::class);
		$store->method('grantsFor')->willReturn($derived);
		$store->method('forget')->willReturnCallback(
			function (): void {
				$this->forgot = true;
			}
		);

		$delegations = $this->createMock(DelegationGrantMapper::class);
		$delegations->method('findHeldBy')->willReturn($delegated);
		$delegations->method('update')->willReturnCallback(
			function (DelegationGrant $grant): DelegationGrant {
				$this->revoked[] = (string)$grant->getUuid();

				return $grant;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('root');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ReachRevocationService(
			$reach,
			$schemaMapper,
			$store,
			$delegations,
			$this->createMock(AuthorizationAuditService::class),
			$session,
			new NullLogger()
		);
	}//end service()

	public function testTheRevocationIsRecordedInFull(): void {
		$service = $this->service(
			schemas: [$this->schema(['read' => [self::PRINCIPAL, 'redacteuren']])],
			derived: [['register' => 'zaken', 'action' => 'read']],
			delegated: [$this->delegation()]
		);

		$record = $service->revokeAll(self::PRINCIPAL, 'uitdiensttreding');

		// EACH GRANT IS REMOVED.
		self::assertSame([['read' => ['redacteuren']]], $this->written);
		self::assertTrue($this->forgot);
		self::assertSame(['deleg-1'], $this->revoked);

		// AND ONE RECORD NAMES ALL OF THEM.
		self::assertSame(3, $record['removedCount']);
		$sources = array_column($record['removed'], 'source');
		self::assertContains(PrincipalReach::SOURCE_AUTHORIZATION, $sources);
		self::assertContains(PrincipalReach::SOURCE_DERIVED, $sources);
		self::assertContains(PrincipalReach::SOURCE_DELEGATION, $sources);

		self::assertSame(ReachRevocationService::RULE, $record['rule']);
		self::assertSame('uitdiensttreding', $record['reason']);
		self::assertSame('root', $record['revokedBy']);
		self::assertSame(0, $record['failedCount']);
	}//end testTheRevocationIsRecordedInFull()

	public function testAGroupIsReportedAsRetainedRatherThanEdited(): void {
		$service = $this->service(
			listing: [
				'total' => 1,
				'entries' => [
					['source' => PrincipalReach::SOURCE_GROUP, 'group' => 'redacteuren', 'revocable' => false],
				],
			]
		);

		$record = $service->revokeAll(self::PRINCIPAL);

		// ADR-010: the act does not edit groups, and it says so rather than
		// leaving an administrator to believe the access is gone.
		self::assertSame(0, $record['removedCount']);
		self::assertSame(1, $record['retainedCount']);
		self::assertSame('redacteuren', $record['retained'][0]['group']);
		self::assertStringContainsString('does not edit groups', $record['retained'][0]['why']);
	}//end testAGroupIsReportedAsRetainedRatherThanEdited()

	public function testARuleNamingOnlyAGroupIsLeftExactlyAsWritten(): void {
		$service = $this->service(schemas: [$this->schema(['read' => ['redacteuren']])]);

		$record = $service->revokeAll(self::PRINCIPAL);

		// Rewriting it would change what every other member of that group may
		// do, so an untouched block is never written at all.
		self::assertSame([], $this->written);
		self::assertSame(0, $record['removedCount']);
	}//end testARuleNamingOnlyAGroupIsLeftExactlyAsWritten()

	public function testTheConstrainedEntryShapeIsRecognisedToo(): void {
		$service = $this->service(
			schemas: [
				$this->schema(
					['read' => [['name' => self::PRINCIPAL, 'until' => '2026-12-31'], 'redacteuren']]
				),
			]
		);

		$service->revokeAll(self::PRINCIPAL);

		// A grant written in the constrained form is still a grant. Matching
		// only bare strings would leave every expiring grant standing.
		self::assertSame([['read' => ['redacteuren']]], $this->written);
	}//end testTheConstrainedEntryShapeIsRecognisedToo()

	public function testALapsedDelegationIsNotRevokedAgain(): void {
		$lapsed = $this->delegation();
		$lapsed->setExpiresAt(new DateTime('-1 day'));

		$service = $this->service(delegated: [$lapsed]);

		$record = $service->revokeAll(self::PRINCIPAL);

		self::assertSame([], $this->revoked);
		self::assertSame(0, $record['removedCount']);
	}//end testALapsedDelegationIsNotRevokedAgain()
}//end class
