<?php

/**
 * ReplyThreadService: the resolver's answer, limited to what the caller may read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Service\Notification\ReplyThreadResolver;
use OCA\OpenRegister\Notifications\Service\ReplyThreadService;
use OCA\OpenRegister\Tests\Unit\Support\FindCallShape;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\ReplyThreadService
 * @covers \OCA\OpenRegister\Db\EmailLink
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Notifications\Service\Notification\ReplyThreadResolver
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 */
class ReplyThreadServiceTest extends TestCase {

	/** @var array<int, array<int, mixed>> The arguments of each read. */
	private array $reads = [];

	/**
	 * The service over recorded links and what the caller may read.
	 *
	 * @param array<string, string[]> $links    The object uuids per `Message-ID`.
	 * @param array<string, mixed>    $readable What a read of each uuid does: true, null or a Throwable.
	 *
	 * @return ReplyThreadService The service.
	 */
	private function service(array $links, array $readable): ReplyThreadService {
		$mapper = $this->createMock(EmailLinkMapper::class);
		$mapper->method('objectUuidsByRfcMessageId')->willReturnCallback(
			static fn (string $rfcMessageId): array => ($links[$rfcMessageId] ?? [])
		);

		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('find')->willReturnCallback(
			function (...$arguments) use ($readable): ?ObjectEntity {
				$this->reads[] = FindCallShape::find(args: $arguments);
				$outcome = ($readable[$arguments[0]] ?? null);
				if ($outcome instanceof \Throwable) {
					throw $outcome;
				}

				return ($outcome === true) ? new ObjectEntity() : null;
			}
		);

		return new ReplyThreadService(links: $mapper, objects: $objects, resolver: new ReplyThreadResolver());
	}//end service()

	public function testAReplyThreadsOntoTheObjectItsParentIsLinkedTo(): void {
		$answer = $this->service(['<a@x>' => ['case-1']], ['case-1' => true])->resolve(headers: ['In-Reply-To' => '<a@x>']);

		$this->assertSame('threaded', $answer['state']);
		$this->assertSame('case-1', $answer['objectUuid']);
		$this->assertSame('In-Reply-To', $answer['matchedOn']);
		$this->assertSame('user', $answer['scope']);
	}//end testAReplyThreadsOntoTheObjectItsParentIsLinkedTo()

	/**
	 * Positional, in ObjectOperationsInterface::find()'s order: rbac 5, multitenancy 6, audit 8.
	 */
	public function testTheReadRunsUnderTheCallersRightsAndLeavesNoReadAudit(): void {
		$this->service(['<a@x>' => ['case-1']], ['case-1' => true])->resolve(headers: ['In-Reply-To' => '<a@x>']);

		$this->assertSame('case-1', $this->reads[0][0]);
		$this->assertTrue($this->reads[0][5]);
		$this->assertTrue($this->reads[0][6]);
		$this->assertFalse($this->reads[0][8]);
	}//end testTheReadRunsUnderTheCallersRightsAndLeavesNoReadAudit()

	public function testAnObjectTheCallerMayNotReadReadsAsNoLink(): void {
		$answer = $this->service(['<a@x>' => ['secret']], ['secret' => null])->resolve(headers: ['In-Reply-To' => '<a@x>']);

		$this->assertSame('unthreaded', $answer['state']);
		$this->assertSame('', $answer['objectUuid']);
		$this->assertStringNotContainsString('secret', (string)json_encode($answer));
	}//end testAnObjectTheCallerMayNotReadReadsAsNoLink()

	public function testARefusedReadIsANo(): void {
		$answer = $this->service(['<a@x>' => ['secret']], ['secret' => new RuntimeException('no')])->resolve(headers: ['In-Reply-To' => '<a@x>']);

		$this->assertSame('unthreaded', $answer['state']);
	}//end testARefusedReadIsANo()

	public function testAnUnreadableCandidateIsNotNamedBesideAReadableOne(): void {
		$answer = $this->service(['<a@x>' => ['case-1', 'secret']], ['case-1' => true, 'secret' => null])
			->resolve(headers: ['In-Reply-To' => '<a@x>']);

		$this->assertSame('case-1', $answer['objectUuid']);
		$this->assertStringNotContainsString('secret', (string)json_encode($answer));
	}//end testAnUnreadableCandidateIsNotNamedBesideAReadableOne()

	public function testAChainNamingTwoReadableObjectsResolvesNeither(): void {
		$answer = $this->service(['<a@x>' => ['case-1'], '<b@x>' => ['case-2']], ['case-1' => true, 'case-2' => true])
			->resolve(headers: ['References' => '<a@x> <b@x>']);

		$this->assertSame('ambiguous', $answer['state']);
		$this->assertEqualsCanonicalizing(['case-1', 'case-2'], $answer['candidates']);
	}//end testAChainNamingTwoReadableObjectsResolvesNeither()

	public function testNoMatchIsANamedAnswer(): void {
		$answer = $this->service([], [])->resolve(headers: ['In-Reply-To' => '<a@x>']);

		$this->assertSame('unthreaded', $answer['state']);
	}//end testNoMatchIsANamedAnswer()

	public function testAMessageIdTheColumnCannotHoldIsNotStored(): void {
		$this->assertSame('<a@x>', EmailLink::storableMessageId(messageId: '<a@x>'));
		$this->assertSame(str_repeat('a', 255), EmailLink::storableMessageId(messageId: str_repeat('a', 255)));
		$this->assertNull(EmailLink::storableMessageId(messageId: str_repeat('a', 256)));
		$this->assertNull(EmailLink::storableMessageId(messageId: ''));
		$this->assertNull(EmailLink::storableMessageId(messageId: null));
	}//end testAMessageIdTheColumnCannotHoldIsNotStored()
}//end class
