<?php

/**
 * Characterisation: the exact refusal ShareGrantor::refusal() gives per branch.
 *
 * Pins the allowed AND refused outcome of every branch with its exact message,
 * and that no right is consulted for a caller without a session, so splitting
 * the method into named steps cannot change who may grant a share.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
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

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCA\OpenRegister\Service\Federation\ShareTargets;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden refusals of ShareGrantor::refusal().
 */
class ShareGrantorRefusalGoldenTest extends TestCase {

	private const SIGNED_OUT = 'Sharing needs a signed-in user';
	private const REGISTER   = 'Sharing a whole register needs the right to manage that register';
	private const OBJECT     = 'Sharing an object needs your own read of that object';
	private const SCHEMA     = 'This share needs the right to manage its schema';

	/**
	 * Every branch, with the rights the caller holds and the exact outcome.
	 *
	 * Columns: scope, permissions, signed in, register resolves, manages register,
	 * reads object, schema resolves, manages schema, expected refusal.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: bool, 5: bool, 6: bool, 7: bool, 8: string|null}>
	 */
	public static function branches(): array {
		return [
			'signed out'                      => ['register', 'read', false, true, true, true, true, true, self::SIGNED_OUT],
			'register, manager'               => ['register', 'read-write', true, true, true, false, false, false, null],
			'register, not manager'           => ['register', 'read', true, true, false, true, true, true, self::REGISTER],
			'register, unresolvable'          => ['register', 'read', true, false, true, true, true, true, self::REGISTER],
			'object read, reader'             => ['object', 'read', true, false, false, true, false, false, null],
			'object read, not reader'         => ['object', 'read', true, true, true, false, true, true, self::OBJECT],
			'object write, schema manager'    => ['object', 'read-write', true, false, false, false, true, true, null],
			'object write, not schema manager' => ['object', 'read-write', true, true, true, true, true, false, self::SCHEMA],
			'schema, schema manager'          => ['schema', 'read', true, false, false, false, true, true, null],
			'schema, not schema manager'      => ['schema', 'read', true, true, true, true, true, false, self::SCHEMA],
			'schema, unresolvable'            => ['schema', 'read', true, true, true, true, false, true, self::SCHEMA],
		];
	}//end branches()

	/**
	 * The refusal of each branch matches the golden outcome.
	 *
	 * @param string      $scope           The share scope.
	 * @param string      $permissions     The share permissions.
	 * @param bool        $signedIn        Whether a user is signed in.
	 * @param bool        $registerFound   Whether the register resolves.
	 * @param bool        $managesRegister Whether the caller manages the register.
	 * @param bool        $readsObject     Whether the caller reads the object.
	 * @param bool        $schemaFound     Whether the schema resolves.
	 * @param bool        $managesSchema   Whether the caller manages the schema.
	 * @param string|null $expected        The refusal, or null when allowed.
	 *
	 * @return void
	 */
	#[DataProvider('branches')]
	public function testTheRefusalMatchesTheGolden(
		string $scope,
		string $permissions,
		bool $signedIn,
		bool $registerFound,
		bool $managesRegister,
		bool $readsObject,
		bool $schemaFound,
		bool $managesSchema,
		?string $expected
	): void {
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$targets = $this->createMock(ShareTargets::class);
		$targets->method('register')->willReturn($registerFound === true ? new Register() : null);
		$targets->method('schema')->willReturn($schemaFound === true ? new Schema() : null);
		$targets->method('callerReadsObject')->willReturn($readsObject);

		$gate = $this->createMock(SchemaManageGate::class);
		$gate->method('mayManageRegister')->willReturn($managesRegister);
		$gate->method('mayManage')->willReturn($managesSchema);
		if ($signedIn === false) {
			$gate->expects($this->never())->method('mayManage');
			$gate->expects($this->never())->method('mayManageRegister');
			$targets->expects($this->never())->method('callerReadsObject');
		}

		$grantor = new ShareGrantor($session, $this->createMock(OrganisationService::class), $gate, $targets);
		$share   = (new FederatedShare())->hydrate(
			object: ['scope' => $scope, 'permissions' => $permissions, 'register' => 'zaken', 'schema' => 'zaak', 'objectUri' => 'zaak-1']
		);

		$this->assertSame($expected, $grantor->refusal(share: $share));
	}//end testTheRefusalMatchesTheGolden()
}//end class
