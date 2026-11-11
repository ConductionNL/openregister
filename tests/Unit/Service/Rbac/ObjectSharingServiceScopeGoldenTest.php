<?php

/**
 * Golden test for ObjectSharingService::setScope().
 *
 * Pins, for every caller (owner, administrator, stranger, anonymous), what
 * setScope() returns or refuses for a valid and an invalid scope, and what
 * reaches the authorization writer. Allowed and refused outcomes are both
 * pinned here, inline.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ScopeChange;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\AuthorizationAssignment;
use OCA\OpenRegister\Service\Rbac\ObjectGrantRecords;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectShareSurface;
use OCA\OpenRegister\Service\Rbac\ObjectSharingContext;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectSharingService
 */
final class ObjectSharingServiceScopeGoldenTest extends TestCase {

	/**
	 * Callers: null for no session, else [uid, groups].
	 *
	 * @var array<string, array|null>
	 */
	private const CALLERS = [
		'owner' => ['pjansen', ['medewerkers']],
		'admin' => ['root', ['admin']],
		'stranger' => ['other', ['medewerkers']],
		'anonymous' => null,
	];

	/**
	 * The pinned outcome per caller and requested scope.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array}>
	 */
	public static function scenarios(): array {
		$stored = ['read' => ['group' => 'x'], 'scope' => 'private'];
		$denied = ['refused' => ['OCA\OpenRegister\Exception\NotAuthorizedException', 'Only the owner or an administrator may change sharing on this object'], 'writes' => []];
		$badScope = ['refused' => ['InvalidArgumentException', 'Scope must be one of: organisation, private'], 'writes' => []];

		return [
			'owner private' => ['owner', 'private', ['returned' => $stored, 'writes' => [['r', 's', 'u-1', $stored]]]],
			'owner bogus' => ['owner', 'public', $badScope],
			'admin private' => ['admin', 'private', ['returned' => $stored, 'writes' => [['r', 's', 'u-1', $stored]]]],
			'admin bogus' => ['admin', 'public', $badScope],
			'stranger private' => ['stranger', 'private', $denied],
			'stranger bogus' => ['stranger', 'public', $denied],
			'anonymous private' => ['anonymous', 'private', $denied],
		];
	}

	/**
	 * setScope() returns or refuses, and writes, exactly the pinned outcome.
	 *
	 * @param string $caller   The caller.
	 * @param string $scope    The requested scope.
	 * @param array  $expected The pinned outcome.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testScopeGolden(string $caller, string $scope, array $expected): void {
		$writes = [];
		$writer = $this->createMock(ObjectAuthorizationWriter::class);
		$writer->method('writeAuthorizationBlock')->willReturnCallback(
			function (RegisterSchema $table, AuthorizationAssignment $assignment) use (&$writes): void {
				$writes[] = [$table->register->getSlug(), $table->schema->getSlug(), $assignment->objectUuid, $assignment->block];
			}
		);

		$register = new Register();
		$register->setSlug('r');
		$schema = new Schema();
		$schema->setSlug('s');
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setOwner('pjansen');
		$object->setAuthorization(['read' => ['group' => 'x']]);

		try {
			$result = ['returned' => $this->service(caller: $caller, writer: $writer)->setScope(table: new RegisterSchema(register: $register, schema: $schema), change: new ScopeChange(object: $object, scope: $scope))];
		} catch (\Throwable $e) {
			$result = ['refused' => [get_class($e), $e->getMessage()]];
		}

		$this->assertSame($expected, $result + ['writes' => $writes]);
	}

	/**
	 * The service for one caller.
	 *
	 * @param string                    $caller The caller.
	 * @param ObjectAuthorizationWriter $writer The recording writer.
	 *
	 * @return ObjectSharingService
	 */
	private function service(string $caller, ObjectAuthorizationWriter $writer): ObjectSharingService {
		$spec = self::CALLERS[$caller];
		$user = null;
		if ($spec !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($spec[0]);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroupIds')->willReturn($spec[1] ?? []);

		return new ObjectSharingService(
			shares: new ObjectShareSurface(folders: $this->createMock(FolderManagementHandler::class), shareManager: $this->createMock(IManager::class)),
			records: new ObjectGrantRecords(authorizationWriter: $writer, scopeResolver: new ObjectScopeResolver(), grantResolver: $this->createMock(ObjectGrantResolver::class), inheritedGrants: $this->createMock(InheritedGrantLister::class)),
			context: new ObjectSharingContext(userSession: $session, groupManager: $groups, logger: new NullLogger()),
			grantManage: new GrantManageGuard(scopeResolver: new ObjectScopeResolver(), specialRights: $this->createMock(OrganisationSpecialRights::class))
		);
	}
}
