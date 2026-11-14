<?php

/**
 * Minting and revoking a personal API token goes on the audit trail, without the token value.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/capability-rows-verified-c/specs/enhanced-audit-trail/spec.md#requirement-changes-to-authorisation-rules-api-tokens-and-record-types-are-on-the-audit-trail
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Service\OrganisationServiceInterface;
use OCA\OpenRegister\Service\User\ApiTokenRequest;
use OCA\OpenRegister\Service\UserService;
use OCP\Accounts\IAccountManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\UserService
 * @uses   \OCA\OpenRegister\Service\User\ApiTokenAuditRow
 * @uses   \OCA\OpenRegister\Service\User\ApiTokenRequest
 * @uses   \OCA\OpenRegister\Service\Rbac\AuditRowWriter
 * @uses   \OCA\OpenRegister\Db\AuditTrail
 */
final class UserServiceApiTokenAuditTest extends TestCase {

	/**
	 * The token value the random source mints.
	 *
	 * @var string
	 */
	private const VALUE = 'secret-token-value-that-must-never-reach-the-trail-0123456789abcd';

	/**
	 * The rows the audit trail received.
	 *
	 * @var array<int, AuditTrail>
	 */
	private array $written = [];

	/**
	 * The token store, as the user value holds it.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * The logger, to see a failed write reported.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A user with this id and display name.
	 *
	 * @param string $uid  The id.
	 * @param string $name The display name.
	 *
	 * @return IUser
	 */
	private function user(string $uid, string $name): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($name);

		return $user;
	}//end user()

	/**
	 * The service over a recording audit trail and token store, with `beheerder` signed in.
	 *
	 * @param bool $failing Whether the audit trail refuses the write.
	 *
	 * @return UserService
	 */
	private function service(bool $failing = false): UserService {
		$mapper = $this->createMock(AuditTrailMapperInterface::class);
		$mapper->method('insertAuditTrails')->willReturnCallback(
			function (array $entries) use ($failing): int {
				if ($failing === true) {
					throw new RuntimeException('database gone');
				}

				array_push($this->written, ...$entries);
				return count($entries);
			}
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn (): string => $this->stored);
		$config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $app, string $key, string $value): void {
				$this->stored = $value;
			}
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->user(uid: 'beheerder', name: 'Beheerder'));

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(
			fn (int $length): string => $length === 64 ? self::VALUE : 'tokenid000000001'
		);

		$this->logger = $this->createMock(LoggerInterface::class);

		return new UserService(
			$this->createMock(IUserManager::class),
			$session,
			$config,
			$this->createMock(IGroupManager::class),
			$this->createMock(IAccountManager::class),
			$this->logger,
			$this->createMock(OrganisationServiceInterface::class),
			$this->createMock(IEventDispatcher::class),
			$this->createMock(IAvatarManager::class),
			$mapper,
			$random,
			$this->createMock(IDBConnection::class),
			$this->createMock(IFactory::class)
		);
	}//end service()

	/**
	 * A minted token writes one entry: the token's id and name, its owner and the actor.
	 *
	 * @return void
	 */
	public function testMintingATokenWritesOneEntryWithoutItsValue(): void {
		$token = $this->service()->issueApiToken(
			user: $this->user(uid: 'jan', name: 'Jan'),
			request: new ApiTokenRequest(name: 'koppeling-gis', expiresIn: null)
		);

		$this->assertSame(expected: self::VALUE, actual: $token['token']);
		$this->assertCount(expectedCount: 1, haystack: $this->written);
		$row = $this->written[0];
		$this->assertSame(expected: 'api-token.created', actual: $row->getAction());
		$this->assertSame(expected: 'beheerder', actual: $row->getUser());
		$this->assertSame(
			expected: ['token' => ['id' => 'tokenid000000001', 'name' => 'koppeling-gis', 'expires' => null], 'owner' => 'jan'],
			actual: $row->getChanged()
		);
		$this->assertNoSecret(row: $row);
	}//end testMintingATokenWritesOneEntryWithoutItsValue()

	/**
	 * A revoked token writes one entry naming the token it was, its owner and the actor.
	 *
	 * @return void
	 */
	public function testRevokingATokenWritesOneEntryWithoutItsValue(): void {
		$service = $this->service();
		$jan     = $this->user(uid: 'jan', name: 'Jan');
		$service->issueApiToken(user: $jan, request: new ApiTokenRequest(name: 'koppeling-gis', expiresIn: '90d'));
		$this->written = [];

		$result = $service->revokeApiToken(user: $jan, tokenId: 'tokenid000000001');

		$this->assertTrue(condition: $result['success']);
		$this->assertSame(expected: [], actual: json_decode($this->stored, true));
		$this->assertCount(expectedCount: 1, haystack: $this->written);
		$row = $this->written[0];
		$this->assertSame(expected: 'api-token.revoked', actual: $row->getAction());
		$this->assertSame(expected: 'beheerder', actual: $row->getUser());
		$this->assertSame(expected: 'Beheerder', actual: $row->getUserName());
		$this->assertSame(expected: 'koppeling-gis', actual: $row->getChanged()['token']['name']);
		$this->assertSame(expected: 'jan', actual: $row->getChanged()['owner']);
		$this->assertNotNull(actual: $row->getChanged()['token']['expires']);
		$this->assertNoSecret(row: $row);
	}//end testRevokingATokenWritesOneEntryWithoutItsValue()

	/**
	 * Revoking a token the user does not have writes nothing.
	 *
	 * @return void
	 */
	public function testRevokingAnUnknownTokenWritesNothing(): void {
		$service = $this->service();

		try {
			$service->revokeApiToken(user: $this->user(uid: 'jan', name: 'Jan'), tokenId: 'nope');
			$this->fail(message: 'an unknown token was revoked');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 404, actual: $e->getCode());
		}

		$this->assertSame(expected: [], actual: $this->written);
	}//end testRevokingAnUnknownTokenWritesNothing()

	/**
	 * A failed audit write is logged and never fails the token change.
	 *
	 * @return void
	 */
	public function testAFailedAuditWriteKeepsTheToken(): void {
		$service = $this->service(failing: true);
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('Could not record an API token change')
		);

		$token = $service->issueApiToken(
			user: $this->user(uid: 'jan', name: 'Jan'),
			request: new ApiTokenRequest(name: 'koppeling-gis', expiresIn: null)
		);

		$this->assertSame(expected: 'tokenid000000001', actual: $token['id']);
		$this->assertArrayHasKey(key: 'tokenid000000001', array: json_decode($this->stored, true));
	}//end testAFailedAuditWriteKeepsTheToken()

	/**
	 * The row holds neither the value, its hash nor its last four characters.
	 *
	 * @param AuditTrail $row The row.
	 *
	 * @return void
	 */
	private function assertNoSecret(AuditTrail $row): void {
		$json = (string)json_encode($row->getChanged());
		$this->assertStringNotContainsString(needle: self::VALUE, haystack: $json);
		$this->assertStringNotContainsString(needle: hash('sha256', self::VALUE), haystack: $json);
		$this->assertStringNotContainsString(needle: 'preview', haystack: $json);
		$this->assertStringNotContainsString(needle: substr(self::VALUE, -4), haystack: $json);
	}//end assertNoSecret()
}//end class
