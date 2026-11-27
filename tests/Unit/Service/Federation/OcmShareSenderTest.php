<?php

/**
 * OcmShareSender Test
 *
 * The outbound half of the OCM provider: what a sent share carries, when
 * nothing is sent, and which notification each status change sends.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Federation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Federation;

use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Service\Federation\OcmShareSender;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationNotification;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\ICloudId;
use OCP\Federation\ICloudIdManager;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for OcmShareSender.
 */
final class OcmShareSenderTest extends TestCase {

	/**
	 * Sends shares and notifications.
	 *
	 * @var ICloudFederationProviderManager&MockObject
	 */
	private ICloudFederationProviderManager&MockObject $providerManager;

	/**
	 * Builds the OCM share and notification.
	 *
	 * @var ICloudFederationFactory&MockObject
	 */
	private ICloudFederationFactory&MockObject $factory;

	/**
	 * Resolves cloud ids.
	 *
	 * @var ICloudIdManager&MockObject
	 */
	private ICloudIdManager&MockObject $cloudIds;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->providerManager = $this->createMock(ICloudFederationProviderManager::class);
		$this->factory = $this->createMock(ICloudFederationFactory::class);
		$this->cloudIds = $this->createMock(ICloudIdManager::class);
		$this->cloudIds->method('isValidCloudId')->willReturnCallback(
			static fn (string $id): bool => preg_match('/^[^@\s]+@[a-z0-9.-]+$/', $id) === 1
		);
		$this->cloudIds->method('resolveCloudId')->willReturnCallback(
			function (string $id): ICloudId {
				$cloudId = $this->createMock(ICloudId::class);
				$cloudId->method('getRemote')->willReturn('https://' . substr($id, strpos($id, '@') + 1));
				return $cloudId;
			}
		);
		$this->cloudIds->method('getCloudId')->willReturnCallback(
			function (string $user): ICloudId {
				$cloudId = $this->createMock(ICloudId::class);
				$cloudId->method('getId')->willReturn($user . '@here.example');
				return $cloudId;
			}
		);
	}//end setUp()

	/**
	 * Build the sender.
	 *
	 * @return OcmShareSender
	 */
	private function sender(): OcmShareSender {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->with('/')->willReturn('https://here.example/');

		return new OcmShareSender(
			providerManager: $this->providerManager,
			factory: $this->factory,
			cloudIdManager: $this->cloudIds,
			urlGenerator: $urls,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end sender()

	/**
	 * A stored share.
	 *
	 * @param array<string, mixed> $fields The fields to set.
	 *
	 * @return FederatedShare
	 */
	private function share(array $fields): FederatedShare {
		$share = (new FederatedShare())->hydrate(
			object: $fields + [
				'direction' => 'outgoing',
				'shareToken' => 'tok-123',
				'scope' => 'object',
				'register' => 'cases',
				'schema' => 'case',
				'objectUri' => 'obj-1',
				'permissions' => 'read',
				'sharedWith' => 'bob@there.example',
				'owner' => 'alice',
				'crosses' => ['data', 'files'],
			]
		);
		$share->setId(($fields['id'] ?? 7));

		return $share;
	}//end share()

	/**
	 * A response with this status code.
	 *
	 * @param int $code The HTTP status.
	 *
	 * @return IResponse
	 */
	private function response(int $code): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($code);

		return $response;
	}//end response()

	/**
	 * A share to a cloud id is sent as the openregister resource type, its
	 * secret the share token and its options what the receiving provider reads.
	 *
	 * @return void
	 */
	public function testSendsAShareToARemotePrincipal(): void {
		$ocmShare = $this->createMock(ICloudFederationShare::class);
		$this->factory->expects(self::once())->method('getCloudFederationShare')
			->with('bob@there.example', 'cases / case / obj-1', '', '7', 'alice@here.example', 'alice', 'alice@here.example', 'alice', 'tok-123', 'user', 'openregister')
			->willReturn($ocmShare);
		$ocmShare->expects(self::once())->method('setProtocol')->with(
			[
				'name' => 'webdav',
				'options' => [
					'apiUrl' => 'https://here.example',
					'token' => 'tok-123',
					'sharedSecret' => 'tok-123',
					'scope' => 'object',
					'register' => 'cases',
					'schema' => 'case',
					'objectUri' => 'obj-1',
					'permissions' => 'read',
					'crosses' => ['data', 'files'],
				],
			]
		);
		$this->providerManager->expects(self::once())->method('sendCloudShare')->with($ocmShare)->willReturn($this->response(201));

		self::assertSame(OcmShareSender::SENT, $this->sender()->send(share: $this->share([])));
	}//end testSendsAShareToARemotePrincipal()

	/**
	 * A token share (no cloud id) and an incoming share send nothing.
	 *
	 * @return void
	 */
	public function testSendsNothingWithoutARemotePrincipal(): void {
		$this->providerManager->expects(self::never())->method('sendCloudShare');

		self::assertSame(OcmShareSender::NOT_FEDERATED, $this->sender()->send(share: $this->share(['sharedWith' => 'gemeente-a'])));
		self::assertSame(OcmShareSender::NOT_FEDERATED, $this->sender()->send(share: $this->share(['sharedWith' => null])));
		self::assertSame(OcmShareSender::NOT_FEDERATED, $this->sender()->send(share: $this->share(['direction' => 'incoming'])));
	}//end testSendsNothingWithoutARemotePrincipal()

	/**
	 * A refusing or unreachable instance is a failure.
	 *
	 * @return void
	 */
	public function testARefusalOrAnUnreachableInstanceFails(): void {
		$this->factory->method('getCloudFederationShare')->willReturn($this->createMock(ICloudFederationShare::class));
		$this->providerManager->method('sendCloudShare')->willReturnOnConsecutiveCalls(
			$this->response(400),
			self::throwException(new RuntimeException('unreachable'))
		);

		self::assertSame(OcmShareSender::FAILED, $this->sender()->send(share: $this->share([])));
		self::assertSame(OcmShareSender::FAILED, $this->sender()->send(share: $this->share([])));
	}//end testARefusalOrAnUnreachableInstanceFails()

	/**
	 * Revoking a share sent from here withdraws it at the recipient's
	 * instance, named by our id and authenticated by the token.
	 *
	 * @return void
	 */
	public function testRevokingAnOutgoingShareWithdrawsItThere(): void {
		$notification = $this->createMock(ICloudFederationNotification::class);
		$this->factory->method('getCloudFederationNotification')->willReturn($notification);
		$notification->expects(self::once())->method('setMessage')
			->with('SHARE_UNSHARED', 'openregister', '7', ['sharedSecret' => 'tok-123', 'message' => 'The share was revoked.']);
		$this->providerManager->expects(self::once())->method('sendCloudNotification')
			->with('https://there.example', $notification)
			->willReturn($this->response(201));

		self::assertTrue($this->sender()->notify(share: $this->share([]), status: 'revoked'));
	}//end testRevokingAnOutgoingShareWithdrawsItThere()

	/**
	 * Answering a received share tells its sender, named by the sender's id;
	 * revoking a received share is a decline.
	 *
	 * @return void
	 */
	public function testAnsweringAnIncomingShareTellsItsSender(): void {
		$incoming = $this->share(['direction' => 'incoming', 'remoteInstanceUrl' => 'https://sender.example', 'remoteProviderId' => '55', 'sharedWith' => 'gemeente-a']);
		$sent = [];
		$this->factory->method('getCloudFederationNotification')->willReturnCallback(
			function () use (&$sent): ICloudFederationNotification {
				$notification = $this->createMock(ICloudFederationNotification::class);
				$notification->method('setMessage')->willReturnCallback(
					static function (string $type, string $resource, string $providerId, array $payload) use (&$sent): void {
						$sent[] = [$type, $resource, $providerId, $payload['sharedSecret']];
					}
				);
				return $notification;
			}
		);
		$this->providerManager->method('sendCloudNotification')
			->with('https://sender.example', self::anything())
			->willReturn($this->response(200));

		$sender = $this->sender();
		self::assertTrue($sender->notify(share: $incoming, status: 'accepted'));
		self::assertTrue($sender->notify(share: $incoming, status: 'declined'));
		self::assertTrue($sender->notify(share: $incoming, status: 'revoked'));

		self::assertSame(
			[
				['SHARE_ACCEPTED', 'openregister', '55', 'tok-123'],
				['SHARE_DECLINED', 'openregister', '55', 'tok-123'],
				['SHARE_DECLINED', 'openregister', '55', 'tok-123'],
			],
			$sent
		);
	}//end testAnsweringAnIncomingShareTellsItsSender()

	/**
	 * No notification for a change the far side has no word for, for a share
	 * with nobody on its other side, or when the far side does not answer.
	 *
	 * @return void
	 */
	public function testNotifiesOnlyWhenThereIsSomebodyToTell(): void {
		$this->factory->method('getCloudFederationNotification')->willReturn($this->createMock(ICloudFederationNotification::class));
		$this->providerManager->expects(self::once())->method('sendCloudNotification')
			->willThrowException(new RuntimeException('unreachable'));

		$sender = $this->sender();
		self::assertFalse($sender->notify(share: $this->share([]), status: 'accepted'));
		self::assertFalse($sender->notify(share: $this->share(['sharedWith' => 'gemeente-a']), status: 'revoked'));
		self::assertFalse($sender->notify(share: $this->share(['direction' => 'incoming', 'remoteInstanceUrl' => 'https://sender.example']), status: 'accepted'));
		self::assertFalse($sender->notify(share: $this->share([]), status: 'revoked'));
	}//end testNotifiesOnlyWhenThereIsSomebodyToTell()
}//end class
