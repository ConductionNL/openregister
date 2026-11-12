<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCA\OpenRegister\Service\Federation\ShareTargets;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Which shares a caller may grant.
 *
 * @covers \OCA\OpenRegister\Service\Federation\ShareGrantor
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 * @uses   \OCA\OpenRegister\Db\Organisation
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class ShareGrantorTest extends TestCase {

	/** @var IUserSession&MockObject */
	private IUserSession $session;

	/** @var SchemaManageGate&MockObject */
	private SchemaManageGate $gate;

	/** @var ShareTargets&MockObject */
	private ShareTargets $targets;

	/** @var OrganisationService&MockObject */
	private OrganisationService $organisations;

	private ShareGrantor $grantor;

	private Schema $schema;

	private Register $register;

	protected function setUp(): void {
		$this->session       = $this->createMock(IUserSession::class);
		$this->gate          = $this->createMock(SchemaManageGate::class);
		$this->targets       = $this->createMock(ShareTargets::class);
		$this->organisations = $this->createMock(OrganisationService::class);
		$this->schema        = new Schema();
		$this->register      = new Register();
		$this->targets->method('schema')->willReturn($this->schema);
		$this->targets->method('register')->willReturn($this->register);
		$this->signIn(uid: 'alice');
		$this->grantor = new ShareGrantor($this->session, $this->organisations, $this->gate, $this->targets);
	}

	private function signIn(?string $uid): void {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->session->method('getUser')->willReturn($user);
	}

	private function share(string $scope, string $permissions): FederatedShare {
		return (new FederatedShare())->hydrate(
			object: ['scope' => $scope, 'permissions' => $permissions, 'register' => 'zaken', 'schema' => 'zaak', 'objectUri' => 'zaak-1']
		);
	}

	public function testWithoutASessionNothingIsGranted(): void {
		$this->session = $this->createMock(IUserSession::class);
		$this->signIn(uid: null);
		$grantor = new ShareGrantor($this->session, $this->organisations, $this->gate, $this->targets);
		$this->gate->method('mayManage')->willReturn(true);
		$this->targets->method('callerReadsObject')->willReturn(true);

		$this->assertNotNull($grantor->refusal(share: $this->share(scope: 'object', permissions: 'read')));
		$this->assertNotNull($grantor->refusal(share: $this->share(scope: 'schema', permissions: 'read')));
		$this->assertNull($grantor->userId());
	}

	/** @return array<string, array{string, string}> */
	public static function manageScopes(): array {
		return [
			'schema read' => ['schema', 'read'],
			'schema read-write' => ['schema', 'read-write'],
			'query read' => ['query', 'read'],
			'query read-write' => ['query', 'read-write'],
			'object read-write' => ['object', 'read-write'],
		];
	}

	/** @dataProvider manageScopes */
	public function testAShareOfAWholeSchemaOrAWriteShareNeedsTheManageRight(string $scope, string $permissions): void {
		$this->targets->method('callerReadsObject')->willReturn(true);
		$this->gate->method('mayManage')->willReturnCallback(fn (Schema $s): bool => false);

		$this->assertSame(
			'This share needs the right to manage its schema',
			$this->grantor->refusal(share: $this->share(scope: $scope, permissions: $permissions))
		);
	}

	/** @dataProvider manageScopes */
	public function testASchemaManagerMayGrantIt(string $scope, string $permissions): void {
		$this->gate->expects($this->once())->method('mayManage')->with($this->identicalTo($this->schema))->willReturn(true);

		$this->assertNull($this->grantor->refusal(share: $this->share(scope: $scope, permissions: $permissions)));
	}

	public function testAnUnresolvableSchemaGrantsNothing(): void {
		$targets = $this->createMock(ShareTargets::class);
		$targets->method('schema')->willReturn(null);
		$this->gate->method('mayManage')->willReturn(true);
		$grantor = new ShareGrantor($this->session, $this->organisations, $this->gate, $targets);

		$this->assertNotNull($grantor->refusal(share: $this->share(scope: 'schema', permissions: 'read')));
	}

	public function testARegisterShareNeedsTheRegisterManageRight(): void {
		$this->gate->method('mayManage')->willReturn(true);
		$this->gate->method('mayManageRegister')->willReturn(false);

		$this->assertSame(
			'Sharing a whole register needs the right to manage that register',
			$this->grantor->refusal(share: $this->share(scope: 'register', permissions: 'read'))
		);
	}

	public function testARegisterManagerMayShareTheRegister(): void {
		$this->gate->expects($this->once())->method('mayManageRegister')->with($this->identicalTo($this->register))->willReturn(true);

		$this->assertNull($this->grantor->refusal(share: $this->share(scope: 'register', permissions: 'read-write')));
	}

	public function testAnUnresolvableRegisterGrantsNothing(): void {
		$targets = $this->createMock(ShareTargets::class);
		$targets->method('register')->willReturn(null);
		$this->gate->method('mayManageRegister')->willReturn(true);
		$grantor = new ShareGrantor($this->session, $this->organisations, $this->gate, $targets);

		$this->assertNotNull($grantor->refusal(share: $this->share(scope: 'register', permissions: 'read')));
	}

	public function testAnObjectReadShareNeedsTheCallersOwnRead(): void {
		$this->gate->method('mayManage')->willReturn(true);
		$this->targets->method('callerReadsObject')->willReturn(false);

		$this->assertSame(
			'Sharing an object needs your own read of that object',
			$this->grantor->refusal(share: $this->share(scope: 'object', permissions: 'read'))
		);
	}

	public function testACallerWhoReadsTheObjectMayShareItForReading(): void {
		$this->gate->expects($this->never())->method('mayManage');
		$share = $this->share(scope: 'object', permissions: 'read');
		$this->targets->expects($this->once())->method('callerReadsObject')->with($this->identicalTo($share))->willReturn(true);

		$this->assertNull($this->grantor->refusal(share: $share));
	}

	public function testAnAdministratorMayAlwaysChangeAShare(): void {
		$this->gate->method('callerIsAdministrator')->willReturn(true);
		$this->gate->method('mayManage')->willReturn(false);

		$this->assertNull($this->grantor->changeRefusal(share: $this->share(scope: 'schema', permissions: 'read')));
	}

	public function testAnyoneElseChangesAShareOnlyWithTheRightsItGrants(): void {
		$this->gate->method('callerIsAdministrator')->willReturn(false);
		$this->gate->method('mayManage')->willReturn(false);

		$this->assertNotNull($this->grantor->changeRefusal(share: $this->share(scope: 'schema', permissions: 'read')));
	}

	public function testTheCreatorIsTheSessionUser(): void {
		$this->assertSame('alice', $this->grantor->userId());
	}

	public function testTheOrganisationIsTheActiveOne(): void {
		$org = new Organisation();
		$org->setUuid('org-a');
		$this->organisations->method('getActiveOrganisation')->willReturn($org);

		$this->assertSame('org-a', $this->grantor->organisation());
	}

	public function testWithoutAnActiveOrganisationThereIsNone(): void {
		$this->organisations->method('getActiveOrganisation')->willReturn(null);

		$this->assertNull($this->grantor->organisation());
	}
}
