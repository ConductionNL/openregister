<?php

/**
 * Behaviour tests for DelegationNotifier: when a grant prompts its acting user, and
 * how a prompt is withdrawn.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Delegation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Delegation;


use OCA\OpenRegister\Db\DelegationGrant;
use OCA\OpenRegister\Service\Delegation\DelegationNotifier;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behaviour of the consent-prompt notifications of a delegation grant.
 */
class DelegationNotifierBehaviourTest extends TestCase {

	/** @var IManager&MockObject */
	private IManager $manager;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var INotification&MockObject */
	private INotification $notification;

	protected function setUp(): void {
		$this->manager      = $this->createMock(IManager::class);
		$this->logger       = $this->createMock(LoggerInterface::class);
		$this->notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$this->notification->method($setter)->willReturnSelf();
		}
	}//end setUp()

	private function notifier(): DelegationNotifier {
		return new DelegationNotifier($this->manager, $this->logger);
	}//end notifier()

	private static function grant(string $status=DelegationGrant::STATUS_PENDING, string $actingAs=' bob ', string $principal='alice', string $uuid='g-1'): DelegationGrant {
		$grant = new DelegationGrant();
		$grant->setUuid($uuid);
		$grant->setActingAs($actingAs);
		$grant->setPrincipal($principal);
		$grant->setStatus($status);
		return $grant;
	}//end grant()

	/**
	 * @return array<string, array{string}>
	 */
	public static function promptingStatuses(): array {
		return ['pending' => [DelegationGrant::STATUS_PENDING], 'requested' => [DelegationGrant::STATUS_REQUESTED]];
	}//end promptingStatuses()

	/**
	 * @dataProvider promptingStatuses
	 */
	public function testAnOpenGrantPromptsTheActingUser(string $status): void {
		$this->manager->method('createNotification')->willReturn($this->notification);
		$this->notification->expects($this->once())->method('setApp')->with('openregister')->willReturnSelf();
		$this->notification->expects($this->once())->method('setUser')->with('bob')->willReturnSelf();
		$this->notification->expects($this->once())->method('setObject')->with('delegation', 'g-1')->willReturnSelf();
		$this->notification->expects($this->once())->method('setSubject')
			->with('delegation_consent_requested', ['principal' => 'alice', 'actingAs' => 'bob', 'grantUuid' => 'g-1'])
			->willReturnSelf();
		$this->manager->expects($this->once())->method('notify')->with($this->notification);

		$this->notifier()->requested(self::grant(status: $status));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function closedStatuses(): array {
		return [
			'granted' => [DelegationGrant::STATUS_GRANTED],
			'denied' => [DelegationGrant::STATUS_DENIED],
			'revoked' => [DelegationGrant::STATUS_REVOKED],
		];
	}//end closedStatuses()

	/**
	 * @dataProvider closedStatuses
	 */
	public function testAClosedGrantPromptsNobody(string $status): void {
		$this->manager->expects($this->never())->method('createNotification');

		$this->notifier()->requested(self::grant(status: $status));
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function incompleteGrants(): array {
		return [
			'no acting user' => ['  ', 'alice', 'g-1'],
			'no principal' => ['bob', '', 'g-1'],
			'no uuid' => ['bob', 'alice', ' '],
		];
	}//end incompleteGrants()

	/**
	 * @dataProvider incompleteGrants
	 */
	public function testAnIncompleteGrantPromptsNobody(string $actingAs, string $principal, string $uuid): void {
		$this->manager->expects($this->never())->method('createNotification');

		$this->notifier()->requested(self::grant(actingAs: $actingAs, principal: $principal, uuid: $uuid));
	}

	public function testAFailedPromptIsLoggedNotThrown(): void {
		$this->manager->method('createNotification')->willReturn($this->notification);
		$this->manager->method('notify')->willThrowException(new \RuntimeException('no app'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[DelegationNotifier] Could not send the consent prompt: no app',
			$this->callback(static fn (array $ctx): bool => $ctx['grant'] === 'g-1' && $ctx['actingAs'] === 'bob')
		);

		$this->notifier()->requested(self::grant());
	}

	public function testAnAnsweredGrantWithdrawsItsPrompt(): void {
		$this->manager->method('createNotification')->willReturn($this->notification);
		$this->notification->expects($this->once())->method('setObject')->with('delegation', 'g-1')->willReturnSelf();
		$this->manager->expects($this->once())->method('markProcessed')->with($this->notification);

		$this->notifier()->answered(self::grant(status: DelegationGrant::STATUS_GRANTED));
	}

	public function testAnAnsweredGrantWithoutUuidWithdrawsNothing(): void {
		$this->manager->expects($this->never())->method('createNotification');

		$this->notifier()->answered(self::grant(uuid: ''));
	}

	public function testAFailedWithdrawalIsLoggedNotThrown(): void {
		$this->manager->method('createNotification')->willReturn($this->notification);
		$this->manager->method('markProcessed')->willThrowException(new \RuntimeException('db'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[DelegationNotifier] Could not withdraw the consent prompt: db',
			$this->callback(static fn (array $ctx): bool => $ctx['grant'] === 'g-1')
		);

		$this->notifier()->answered(self::grant());
	}
}//end class
