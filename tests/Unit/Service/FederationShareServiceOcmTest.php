<?php

/**
 * FederationShareService OCM Test
 *
 * The two-sided lifecycle of a federated share as the service drives it:
 * sending a new share, revoking one the other instance could not take,
 * telling the other side about a change, and answering a received share.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-an-object-is-shared-with-a-principal-at-another-instance-with-a-two-sided-lifecycle-req-pcf-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\FederatedShare;
use OCA\OpenRegister\Db\FederatedShareMapper;
use OCA\OpenRegister\Exception\FederatedShareNotDeliveredException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Federation\OcmShareSender;
use OCA\OpenRegister\Service\Federation\ShareCreatorCheck;
use OCA\OpenRegister\Service\Federation\ShareGrantor;
use OCA\OpenRegister\Service\FederationShareService;
use OCA\OpenRegister\Tests\Unit\Service\Federation\Support\FixedClock;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the OCM lifecycle in FederationShareService.
 */
final class FederationShareServiceOcmTest extends TestCase {

	/**
	 * The share mapper.
	 *
	 * @var FederatedShareMapper&MockObject
	 */
	private FederatedShareMapper&MockObject $shareMapper;

	/**
	 * The grantor.
	 *
	 * @var ShareGrantor&MockObject
	 */
	private ShareGrantor&MockObject $grantor;

	/**
	 * The OCM sender.
	 *
	 * @var OcmShareSender&MockObject
	 */
	private OcmShareSender&MockObject $sender;

	/**
	 * The status writes, in order: [id, status].
	 *
	 * @var array<int, array{0: int, 1: string}>
	 */
	private array $writes = [];

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->shareMapper = $this->createMock(FederatedShareMapper::class);
		$this->grantor = $this->createMock(ShareGrantor::class);
		$this->grantor->method('userId')->willReturn('alice');
		$this->grantor->method('organisation')->willReturn('org-a');
		$this->sender = $this->createMock(OcmShareSender::class);

		$this->shareMapper->method('createFromArray')->willReturnCallback(
			static function (array $data): FederatedShare {
				$share = (new FederatedShare())->hydrate(object: $data);
				$share->setId(7);
				return $share;
			}
		);
		$this->shareMapper->method('updateFromArray')->willReturnCallback(
			function (int $id, array $data): FederatedShare {
				$this->writes[] = [$id, (string)$data['status']];
				$share = (new FederatedShare())->hydrate(object: $data + ['direction' => 'outgoing']);
				$share->setId($id);
				return $share;
			}
		);
	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @param bool $withSender Whether the sender is wired.
	 *
	 * @return FederationShareService
	 */
	private function service(bool $withSender = true): FederationShareService {
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('a', 48));
		$sender = null;
		if ($withSender === true) {
			$sender = $this->sender;
		}

		return new FederationShareService(
			shareMapper: $this->shareMapper,
			grantor: $this->grantor,
			secureRandom: $random,
			logger: $this->createMock(LoggerInterface::class),
			creatorCheck: $this->createMock(ShareCreatorCheck::class),
			lifetime: (new FixedClock($this))->lifetime(),
			sender: $sender
		);
	}//end service()

	/**
	 * A stored share as find() returns it.
	 *
	 * @param string $direction The direction.
	 * @param string $status    The status.
	 *
	 * @return FederatedShare
	 */
	private function stored(string $direction, string $status): FederatedShare {
		$share = (new FederatedShare())->hydrate(object: ['direction' => $direction, 'status' => $status, 'shareToken' => 'tok']);
		$share->setId(7);

		return $share;
	}//end stored()

	/**
	 * A share sent over OCM waits for the recipient's answer as pending.
	 *
	 * @return void
	 */
	public function testASentShareWaitsForTheRecipientsAnswer(): void {
		$this->sender->expects(self::once())->method('send')
			->with(self::callback(static fn (FederatedShare $s): bool => $s->getSharedWith() === 'bob@there.example' && $s->getId() === 7))
			->willReturn(OcmShareSender::SENT);

		$share = $this->service()->createOutgoingShare(['scope' => 'register', 'register' => '1', 'sharedWith' => 'bob@there.example']);

		self::assertSame([[7, 'pending']], $this->writes);
		self::assertSame('pending', $share->getStatus());
	}//end testASentShareWaitsForTheRecipientsAnswer()

	/**
	 * A token share handed over by other means stays as it was.
	 *
	 * @return void
	 */
	public function testATokenShareIsNotSent(): void {
		$this->sender->method('send')->willReturn(OcmShareSender::NOT_FEDERATED);

		$share = $this->service()->createOutgoingShare(['scope' => 'register', 'register' => '1', 'sharedWith' => 'gemeente-a']);

		self::assertSame([], $this->writes);
		self::assertSame('accepted', $share->getStatus());
	}//end testATokenShareIsNotSent()

	/**
	 * A share the other instance could not take is revoked, and the caller is told.
	 *
	 * @return void
	 */
	public function testAShareNobodyReceivedIsRevoked(): void {
		$this->sender->method('send')->willReturn(OcmShareSender::FAILED);

		try {
			$this->service()->createOutgoingShare(['scope' => 'register', 'register' => '1', 'sharedWith' => 'bob@there.example']);
			self::fail('An undelivered share must be reported.');
		} catch (FederatedShareNotDeliveredException $e) {
			self::assertSame([[7, 'revoked']], $this->writes);
		}
	}//end testAShareNobodyReceivedIsRevoked()

	/**
	 * A refused share is never sent.
	 *
	 * @return void
	 */
	public function testARefusedShareIsNeverSent(): void {
		$this->grantor->method('refusal')->willReturn('no');
		$this->sender->expects(self::never())->method('send');

		$this->expectException(NotAuthorizedException::class);
		$this->service()->createOutgoingShare(['scope' => 'register', 'register' => '1', 'sharedWith' => 'bob@there.example']);
	}//end testARefusedShareIsNeverSent()

	/**
	 * A status change made here is told to the other side, once, and not
	 * when the status does not change.
	 *
	 * @return void
	 */
	public function testAStatusChangeIsToldToTheOtherSide(): void {
		$this->shareMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->stored(direction: 'outgoing', status: 'accepted'),
			$this->stored(direction: 'outgoing', status: 'revoked')
		);
		$this->sender->expects(self::once())->method('notify')
			->with(self::callback(static fn (FederatedShare $s): bool => $s->getStatus() === 'revoked'), 'revoked');

		$service = $this->service();
		$service->setStatus(id: 7, status: 'revoked');
		$service->setStatus(id: 7, status: 'revoked');
	}//end testAStatusChangeIsToldToTheOtherSide()

	/**
	 * Without a sender the change is only made here.
	 *
	 * @return void
	 */
	public function testWithoutASenderTheChangeStaysHere(): void {
		$this->shareMapper->method('find')->willReturn($this->stored(direction: 'outgoing', status: 'accepted'));

		$share = $this->service(withSender: false)->setStatus(id: 7, status: 'revoked');

		self::assertSame('revoked', $share->getStatus());
		self::assertSame([[7, 'revoked']], $this->writes);
	}//end testWithoutASenderTheChangeStaysHere()

	/**
	 * A refused change is neither made nor told.
	 *
	 * @return void
	 */
	public function testARefusedChangeIsNeitherMadeNorTold(): void {
		$this->shareMapper->method('find')->willReturn($this->stored(direction: 'outgoing', status: 'accepted'));
		$this->grantor->method('changeRefusal')->willReturn('no');
		$this->sender->expects(self::never())->method('notify');

		try {
			$this->service()->setStatus(id: 7, status: 'revoked');
			self::fail('A refused change must throw.');
		} catch (NotAuthorizedException $e) {
			self::assertSame([], $this->writes);
		}
	}//end testARefusedChangeIsNeitherMadeNorTold()

	/**
	 * A pending received share is accepted or declined, and its sender told.
	 *
	 * @return void
	 */
	public function testAPendingReceivedShareIsAnswered(): void {
		$this->shareMapper->method('find')->willReturn($this->stored(direction: 'incoming', status: 'pending'));
		$told = [];
		$this->sender->method('notify')->willReturnCallback(
			static function (FederatedShare $share, string $status) use (&$told): bool {
				$told[] = $status;
				return true;
			}
		);

		self::assertSame('accepted', $this->service()->answerIncomingShare(id: 7, accept: true)->getStatus());
		self::assertSame('declined', $this->service()->answerIncomingShare(id: 7, accept: false)->getStatus());
		self::assertSame(['accepted', 'declined'], $told);
	}//end testAPendingReceivedShareIsAnswered()

	/**
	 * Only a pending received share can be answered.
	 *
	 * @return void
	 */
	public function testOnlyAPendingReceivedShareCanBeAnswered(): void {
		$this->shareMapper->method('find')->willReturnOnConsecutiveCalls(
			$this->stored(direction: 'outgoing', status: 'pending'),
			$this->stored(direction: 'incoming', status: 'revoked')
		);
		$this->sender->expects(self::never())->method('notify');

		foreach ([true, false] as $accept) {
			try {
				$this->service()->answerIncomingShare(id: 7, accept: $accept);
				self::fail('Only a pending received share can be answered.');
			} catch (InvalidArgumentException $e) {
				self::assertStringContainsString('pending share received from another instance', $e->getMessage());
			}
		}

		self::assertSame([], $this->writes);
	}//end testOnlyAPendingReceivedShareCanBeAnswered()
}//end class
