<?php

/**
 * Golden test for ObjectSharingService's grant, link and email-invite paths.
 *
 * For every caller (owner, administrator, stranger, anonymous) and folder state,
 * pins what grant(), createLink() and inviteByEmail() return or refuse, the
 * share that reaches the share manager (as the sorted set of its setter calls),
 * and the trail entry the auditor is handed. Allowed and refused outcomes are
 * both pinned, in tests/Unit/Service/Rbac/fixtures/object-sharing-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite it.
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

use OCA\OpenRegister\Service\Rbac\LinkProtection;
use OCA\OpenRegister\Service\Rbac\LinkInvitation;
use OCA\OpenRegister\Service\Rbac\GrantTerms;
use OCA\OpenRegister\Service\Rbac\GrantRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\Rbac\GrantChangeAuditor;
use OCA\OpenRegister\Service\Rbac\GrantManageGuard;
use OCA\OpenRegister\Service\Rbac\InheritedGrantLister;
use OCA\OpenRegister\Service\Rbac\ObjectAuthorizationWriter;
use OCA\OpenRegister\Service\Rbac\ObjectGrantResolver;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCA\OpenRegister\Service\Rbac\ObjectSharingService;
use OCA\OpenRegister\Service\Rbac\OrganisationSpecialRights;
use OCP\Files\Folder;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IAttributes;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectSharingService
 */
final class ObjectSharingServiceGoldenTest extends TestCase {

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
	 * What the share setters received, per call.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * One scenario per caller and folder state.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function scenarios(): array {
		$out = [];
		foreach (array_keys(self::CALLERS) as $caller) {
			$out[$caller . ' with folder'] = [$caller, true];
			$out[$caller . ' without folder'] = [$caller, false];
		}

		return $out;
	}

	/**
	 * Every call's outcome for one caller and folder state equals the pinned one.
	 *
	 * @param string $caller    The caller.
	 * @param bool   $hasFolder Whether the object folder resolves.
	 *
	 * @return void
	 */
	#[DataProvider('scenarios')]
	public function testSharingGolden(string $caller, bool $hasFolder): void {
		$trail   = [];
		$sharing = $this->service(caller: $caller, hasFolder: $hasFolder, trail: $trail);
		$object  = new ObjectEntity();
		$object->setUuid('710d44a4-0000-4000-8000-000000000001');
		$object->setOwner('pjansen');

		$calls = [
			'grant group' => fn () => $sharing->grantWithTerms(
				object: $object,
				request: new \OCA\OpenRegister\Service\Rbac\GrantRequest(
					type: 'group',
					shareWith: ' juristen ',
					terms: new \OCA\OpenRegister\Service\Rbac\GrantTerms(permissions: 31, verbs: ['read', '', 'read', 'export'])
				)
			),
			'grant user' => fn () => $sharing->grantWithTerms(object: $object, request: new GrantRequest(type: 'user', shareWith: 'tvisser', terms: new GrantTerms(permissions: 1))),
			'grant bad type' => fn () => $sharing->grantWithTerms(object: $object, request: new GrantRequest(type: 'circle', shareWith: 'x', terms: new GrantTerms(permissions: 1))),
			'grant no principal' => fn () => $sharing->grantWithTerms(object: $object, request: new GrantRequest(type: 'group', shareWith: '  ', terms: new GrantTerms(permissions: 1))),
			'link' => fn () => $sharing->createLink(object: $object, invitation: new LinkInvitation(recipient: null, permissions: 31, protection: new LinkProtection(password: 'geheim', expiration: '2026-12-01T00:00:00+00:00'))),
			'link bad expiry' => fn () => $sharing->createLink(object: $object, invitation: new LinkInvitation(recipient: null, permissions: 1, protection: new LinkProtection(password: null, expiration: 'not a date'))),
			'email' => fn () => $sharing->inviteByEmail(object: $object, invitation: new LinkInvitation(recipient: ' a@example.nl ', permissions: 3, protection: new LinkProtection(password: null, expiration: null))),
			'email bad address' => fn () => $sharing->inviteByEmail(object: $object, invitation: new LinkInvitation(recipient: 'nope', permissions: 1, protection: new LinkProtection(password: null, expiration: null))),
		];

		$actual = [];
		foreach ($calls as $name => $call) {
			$this->calls = [];
			$trail = [];
			try {
				$result = ['returned' => $call()];
			} catch (\Throwable $e) {
				$result = ['refused' => [get_class($e), $e->getMessage()]];
			}

			sort($this->calls);
			$actual[$name] = $result + ['share' => $this->calls, 'trail' => $trail];
		}

		$actual = json_decode(json_encode($actual, JSON_UNESCAPED_UNICODE), true);
		$file = __DIR__ . '/fixtures/object-sharing-golden.json';
		$key  = $caller . ($hasFolder === true ? ' with folder' : ' without folder');
		$all  = [];
		if (is_file($file) === true) {
			$all = json_decode(file_get_contents($file), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$key] = $actual;
			ksort($all);
			if (is_dir(dirname($file)) === false) {
				mkdir(dirname($file));
			}

			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$this->assertSame($all[$key], $actual);
	}

	/**
	 * The service for one caller, with a recording share and auditor.
	 *
	 * @param string $caller    The caller.
	 * @param bool   $hasFolder Whether the object folder resolves.
	 * @param array  $trail     The audit trail, by reference.
	 *
	 * @return ObjectSharingService
	 */
	private function service(string $caller, bool $hasFolder, array &$trail): ObjectSharingService {
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
		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getObjectFolder')->willReturn($hasFolder === true ? $this->createMock(Folder::class) : null);

		$shares = $this->createMock(IManager::class);
		$shares->method('newShare')->willReturnCallback(fn (): IShare => $this->recordingShare());
		$shares->method('createShare')->willReturnArgument(0);

		$auditor = $this->createMock(GrantChangeAuditor::class);
		$auditor->method('recordGrant')->willReturnCallback(
			function (ObjectEntity $object, array $grant, ?string $admittedAs) use (&$trail): void {
				$trail[] = [$grant, $admittedAs];
			}
		);

		return new ObjectSharingService(
			shares: new \OCA\OpenRegister\Service\Rbac\ObjectShareSurface(folders: $folders, shareManager: $shares, sharerAccess: $this->createMock(\OCA\OpenRegister\Service\Rbac\SharerFolderAccess::class)),
			records: new \OCA\OpenRegister\Service\Rbac\ObjectGrantRecords(authorizationWriter: $this->createMock(ObjectAuthorizationWriter::class), scopeResolver: new ObjectScopeResolver(), grantResolver: $this->createMock(ObjectGrantResolver::class), inheritedGrants: $this->createMock(InheritedGrantLister::class)),
			context: new \OCA\OpenRegister\Service\Rbac\ObjectSharingContext(userSession: $session, groupManager: $groups, logger: new NullLogger(), auditor: $auditor),
			grantManage: new GrantManageGuard(
				scopeResolver: new ObjectScopeResolver(),
				specialRights: $this->createMock(OrganisationSpecialRights::class)
			)
		);
	}

	/**
	 * A share whose setters are recorded and whose getters answer what was set.
	 *
	 * @return IShare
	 */
	private function recordingShare(): IShare {
		$state = [];
		$share = $this->createMock(IShare::class);
		$setters = ['setNode', 'setShareType', 'setSharedWith', 'setSharedBy', 'setPermissions', 'setExpirationDate', 'setPassword', 'setAttributes'];
		foreach ($setters as $setter) {
			$share->method($setter)->willReturnCallback(
				function (mixed $value) use ($setter, $share, &$state): IShare {
					$state[$setter] = $value;
					$shown = $value;
					if (is_object($value) === true) {
						$shown = ($value instanceof \DateTimeInterface) ? $value->format('Y-m-d') : 'object';
					}

					$this->calls[] = $setter . '=' . json_encode($shown);
					return $share;
				}
			);
		}

		$attributes = $this->createMock(IAttributes::class);
		$attributes->method('setAttribute')->willReturnCallback(
			function (string $scope, string $key, mixed $value) use ($attributes): IAttributes {
				$this->calls[] = 'attribute=' . json_encode([$scope, $key, $value]);
				return $attributes;
			}
		);
		$share->method('getAttributes')->willReturn(null);
		$share->method('newAttributes')->willReturn($attributes);
		$share->method('getFullId')->willReturn('ocinternal:41');
		$share->method('getId')->willReturn('41');
		$share->method('getToken')->willReturn('tok');
		$share->method('getNodeId')->willReturn(9);
		$share->method('getPermissions')->willReturnCallback(function () use (&$state): int {
			return ($state['setPermissions'] ?? 0);
		});
		$share->method('getSharedWith')->willReturnCallback(function () use (&$state): ?string {
			return ($state['setSharedWith'] ?? null);
		});
		$share->method('getSharedBy')->willReturnCallback(function () use (&$state): string {
			return ($state['setSharedBy'] ?? '');
		});
		$share->method('getShareType')->willReturnCallback(function () use (&$state): int {
			return ($state['setShareType'] ?? 0);
		});
		$share->method('getPassword')->willReturnCallback(function () use (&$state): ?string {
			return ($state['setPassword'] ?? null);
		});
		$share->method('getExpirationDate')->willReturnCallback(function () use (&$state): ?\DateTime {
			return ($state['setExpirationDate'] ?? null);
		});

		return $share;
	}
}
