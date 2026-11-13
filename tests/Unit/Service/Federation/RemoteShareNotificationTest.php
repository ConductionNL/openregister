<?php

/**
 * Unit tests for RemoteShareNotification: a far-side accept, decline or
 * withdrawal is reflected on the share here, authenticated by its secret.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapperInterface;
use OCA\OpenRegister\Federation\OpenRegisterCloudFederationProvider;
use OCA\OpenRegister\Service\Federation\RemoteShareNotification;
use OCA\OpenRegister\Service\FederationShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\Federation\Exceptions\ActionNotSupportedException;
use OCP\Federation\Exceptions\AuthenticationFailedException;
use OCP\Federation\Exceptions\BadRequestException;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Federation\RemoteShareNotification
 * @covers \OCA\OpenRegister\Federation\OpenRegisterCloudFederationProvider
 * @uses   \OCA\OpenRegister\Db\FederatedShare
 */
class RemoteShareNotificationTest extends TestCase {

	/**
	 * The shares the mapper stores, by token.
	 *
	 * @var array<string, FederatedShare>
	 */
	private array $shares = [];

	/**
	 * The statuses written, in order.
	 *
	 * @var array<int, string>
	 */
	private array $written = [];

	protected function setUp(): void {
		$this->shares  = [];
		$this->written = [];
		$this->provideATranslator();
	}//end setUp()

	/**
	 * The OCM exceptions translate their message when built; outside a running
	 * Nextcloud the test server has no translator, so give it one.
	 *
	 * @return void
	 */
	private function provideATranslator(): void {
		try {
			$current = Server::get(IFactory::class);
			if ($current instanceof IFactory && $current->get('core') instanceof IL10N) {
				return;
			}
		} catch (\Throwable $e) {
			// No container entry: provide one below.
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		\OC::$server->registerService(IFactory::class, static fn () => $factory);
	}//end provideATranslator()

	/**
	 * Store a share under its token.
	 *
	 * @param int         $id        The local id.
	 * @param string      $direction The direction.
	 * @param string      $status    The status.
	 * @param string      $token     The share secret.
	 * @param string|null $remoteId  The id the sender gave it.
	 *
	 * @return FederatedShare
	 */
	private function stored(int $id, string $direction, string $status, string $token, ?string $remoteId = null): FederatedShare {
		$share = new FederatedShare();
		$share->setId($id);
		$share->setDirection($direction);
		$share->setStatus($status);
		$share->setShareToken($token);
		$share->setRemoteProviderId($remoteId);
		$this->shares[$token] = $share;

		return $share;
	}//end stored()

	private function mapper(): FederatedShareMapperInterface {
		$mapper = $this->createMock(FederatedShareMapperInterface::class);
		$mapper->method('findByToken')->willReturnCallback(function (string $shareToken): FederatedShare {
			if (isset($this->shares[$shareToken]) === false) {
				throw new DoesNotExistException('none');
			}

			return $this->shares[$shareToken];
		});
		$mapper->method('update')->willReturnCallback(function (Entity $entity): FederatedShare {
			$this->written[] = (string)$entity->getStatus();
			return $entity;
		});

		return $mapper;
	}//end mapper()

	private function notifications(): RemoteShareNotification {
		return new RemoteShareNotification($this->mapper());
	}//end notifications()

	public function testTheRecipientAcceptingASentShareIsReflectedHere(): void {
		$this->stored(12, 'outgoing', 'pending', 'secret-12');

		$share = $this->notifications()->apply('SHARE_ACCEPTED', '12', ['sharedSecret' => 'secret-12']);

		$this->assertSame('accepted', $share->getStatus());
		$this->assertSame(['accepted'], $this->written);
	}

	public function testTheRecipientDecliningASentShareIsReflectedHere(): void {
		$this->stored(12, 'outgoing', 'accepted', 'secret-12');

		$this->assertSame('declined', $this->notifications()->apply('SHARE_DECLINED', '12', ['sharedSecret' => 'secret-12'])->getStatus());
	}

	public function testTheSenderWithdrawingAReceivedShareRevokesItHere(): void {
		$this->stored(30, 'incoming', 'accepted', 'their-secret', 'remote-7');

		$this->assertSame('revoked', $this->notifications()->apply('SHARE_UNSHARED', 'remote-7', ['sharedSecret' => 'their-secret'])->getStatus());
		$this->assertSame(['revoked'], $this->written);
	}

	public function testADeclinedOrRevokedShareStaysSo(): void {
		$this->stored(12, 'outgoing', 'declined', 'secret-12');
		$this->stored(30, 'incoming', 'revoked', 'their-secret', 'remote-7');

		$this->assertSame('declined', $this->notifications()->apply('SHARE_ACCEPTED', '12', ['sharedSecret' => 'secret-12'])->getStatus());
		$this->assertSame('revoked', $this->notifications()->apply('SHARE_UNSHARED', 'remote-7', ['sharedSecret' => 'their-secret'])->getStatus());
		$this->assertSame([], $this->written);
	}

	public function testAnUnknownSecretIsRefused(): void {
		$this->stored(12, 'outgoing', 'pending', 'secret-12');

		$this->expectException(AuthenticationFailedException::class);
		$this->notifications()->apply('SHARE_ACCEPTED', '12', ['sharedSecret' => 'guessed']);
	}

	public function testASecretForAnotherShareIdIsRefused(): void {
		$this->stored(12, 'outgoing', 'pending', 'secret-12');

		try {
			$this->notifications()->apply('SHARE_DECLINED', '13', ['sharedSecret' => 'secret-12']);
			$this->fail('A secret must only open the share it belongs to.');
		} catch (AuthenticationFailedException $e) {
			$this->assertSame([], $this->written);
		}
	}

	public function testANotificationForTheOtherDirectionIsRefused(): void {
		$this->stored(12, 'outgoing', 'accepted', 'secret-12', '12');

		try {
			$this->notifications()->apply('SHARE_UNSHARED', '12', ['sharedSecret' => 'secret-12']);
			$this->fail('A sender may only withdraw what it sent.');
		} catch (AuthenticationFailedException $e) {
			$this->assertSame('accepted', $this->shares['secret-12']->getStatus());
		}
	}

	public function testAMissingSecretIsABadRequest(): void {
		$this->expectException(BadRequestException::class);
		$this->notifications()->apply('SHARE_ACCEPTED', '12', []);
	}

	public function testAnUnappliedTypeIsNotSupported(): void {
		$this->expectException(ActionNotSupportedException::class);
		$this->notifications()->apply('RESHARE_CHANGE_PERMISSION', '12', ['sharedSecret' => 'secret-12']);
	}

	public function testTheProviderHandsALifecycleNotificationOn(): void {
		$this->stored(12, 'outgoing', 'pending', 'secret-12');
		$provider = new OpenRegisterCloudFederationProvider(
			$this->createMock(FederationShareService::class),
			$this->createMock(LoggerInterface::class),
			$this->notifications()
		);

		$this->assertSame([], $provider->notificationReceived('SHARE_ACCEPTED', '12', ['sharedSecret' => 'secret-12']));
		$this->assertSame(['accepted'], $this->written);
	}

	public function testTheProviderStillAcknowledgesOtherTypes(): void {
		$provider = new OpenRegisterCloudFederationProvider(
			$this->createMock(FederationShareService::class),
			$this->createMock(LoggerInterface::class),
			$this->notifications()
		);

		$this->assertSame([], $provider->notificationReceived('RESHARE_UNDO', '12', []));
		$this->assertSame([], $this->written);
	}

	public function testTheProviderRefusesALifecycleNotificationWithoutAMatchingShare(): void {
		$provider = new OpenRegisterCloudFederationProvider(
			$this->createMock(FederationShareService::class),
			$this->createMock(LoggerInterface::class),
			$this->notifications()
		);

		$this->expectException(AuthenticationFailedException::class);
		$provider->notificationReceived('SHARE_DECLINED', '99', ['sharedSecret' => 'nothing']);
	}
}
