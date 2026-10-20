<?php

/**
 * A repeated submit with the same key gets the first answer, for 24 hours.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-submit-must-create-the-destination-in-one-request-and-return-its-reference
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryLockingProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\NoopLockingProvider;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Remember, find, expire, purge, and keys scoped per form.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormIdempotencyStore
 * @uses \OCA\OpenRegister\Exception\FormSubmitRefusedException
 */
class FormIdempotencyStoreTest extends TestCase {

	private int $now = 1_760_000_000;

	private MemoryAppData $appData;

	private FormIdempotencyStore $store;

	private MemoryLockingProvider $locks;

	protected function setUp(): void {
		$this->appData = new MemoryAppData();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->locks = new MemoryLockingProvider();
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$this->store = new FormIdempotencyStore(appData: $this->appData, time: $time, locks: $this->locks, l10n: $l10n);
	}//end setUp()

	/**
	 * Nothing remembered: nothing found.
	 */
	public function testAnUnknownKeyFindsNothing(): void {
		$this->assertNull($this->store->find(scope: 'form-1', key: 'k1', binding: 'b'));
	}//end testAnUnknownKeyFindsNothing()

	/**
	 * The remembered answer comes back unchanged within 24 hours.
	 */
	public function testARememberedAnswerIsFoundWithinTheWindow(): void {
		$answer = ['reference' => '2026-0412', 'id' => 'uuid-7'];
		$this->store->remember(scope: 'form-1', key: 'k1', response: $answer, binding: 'b');

		$this->now += 86_399;
		$this->assertSame($answer, $this->store->find(scope: 'form-1', key: 'k1', binding: 'b'));
	}//end testARememberedAnswerIsFoundWithinTheWindow()

	/**
	 * After 24 hours the key is free again, and the expired entry is gone.
	 */
	public function testAnExpiredAnswerIsNotFound(): void {
		$this->store->remember(scope: 'form-1', key: 'k1', response: ['id' => 'uuid-7'], binding: 'b');

		$this->now += 86_401;
		$this->assertNull($this->store->find(scope: 'form-1', key: 'k1', binding: 'b'));
		$this->assertSame([], $this->appData->folders[FormIdempotencyStore::FOLDER]->files);
	}//end testAnExpiredAnswerIsNotFound()

	/**
	 * The same key on another form is another submit.
	 */
	public function testKeysAreScopedPerForm(): void {
		$this->store->remember(scope: 'form-1', key: 'k1', response: ['id' => 'uuid-7'], binding: 'b');

		$this->assertNull($this->store->find(scope: 'form-2', key: 'k1', binding: 'b'));
	}//end testKeysAreScopedPerForm()

	/**
	 * The same key from another subject or with another payload does not get the first answer.
	 */
	public function testAnotherBindingGetsNoAnswerOnlyAConflict(): void {
		$mine = $this->store->bindingFor(subject: 'user:alice', payload: ['title' => 'a', 'n' => ['x' => 1, 'y' => 2]]);
		$this->store->remember(scope: 'form-1', key: 'k1', response: ['reference' => 'secret'], binding: $mine);

		// The same subject and an equal payload (keys in another order) is a repeat.
		$again = $this->store->bindingFor(subject: 'user:alice', payload: ['n' => ['y' => 2, 'x' => 1], 'title' => 'a']);
		$this->assertSame(['reference' => 'secret'], $this->store->find(scope: 'form-1', key: 'k1', binding: $again));

		foreach (
			[
				$this->store->bindingFor(subject: 'user:bob', payload: ['title' => 'a', 'n' => ['x' => 1, 'y' => 2]]),
				$this->store->bindingFor(subject: 'anonymous', payload: ['title' => 'a', 'n' => ['x' => 1, 'y' => 2]]),
				$this->store->bindingFor(subject: 'user:alice', payload: ['title' => 'changed']),
			] as $other
		) {
			try {
				$this->store->find(scope: 'form-1', key: 'k1', binding: $other);
				$this->fail('A different binding received the first answer.');
			} catch (FormSubmitRefusedException $refused) {
				$this->assertSame(409, $refused->getStatus());
				$this->assertStringNotContainsString('secret', $refused->getMessage());
			}
		}
	}//end testAnotherBindingGetsNoAnswerOnlyAConflict()

	/**
	 * A key being worked on is refused to a second request until it is released.
	 */
	public function testAReservedKeyRefusesASecondRequestUntilReleased(): void {
		$this->store->reserve(scope: 'form-1', key: 'k1');

		try {
			$this->store->reserve(scope: 'form-1', key: 'k1');
			$this->fail('A key in use was reserved twice.');
		} catch (FormSubmitRefusedException $refused) {
			$this->assertSame(409, $refused->getStatus());
		}

		// Another key and another form are not held up.
		$this->store->reserve(scope: 'form-1', key: 'k2');
		$this->store->reserve(scope: 'form-2', key: 'k1');

		$this->store->release(scope: 'form-1', key: 'k1');
		$this->store->reserve(scope: 'form-1', key: 'k1');
		$this->addToAssertionCount(1);
	}//end testAReservedKeyRefusesASecondRequestUntilReleased()

	/**
	 * The purge deletes only expired entries and counts them.
	 */
	public function testThePurgeCountsWhatItDeleted(): void {
		$this->store->remember(scope: 'f', key: 'old', response: ['id' => 'a'], binding: 'b');
		$this->now += 50_000;
		$this->store->remember(scope: 'f', key: 'new', response: ['id' => 'b'], binding: 'b');
		$this->now += 40_000;

		$this->assertSame(1, $this->store->purge());
		$this->assertSame(['id' => 'b'], $this->store->find(scope: 'f', key: 'new', binding: 'b'));
		$this->assertSame(0, $this->store->purge());
	}//end testThePurgeCountsWhatItDeleted()

	/**
	 * A purge before any submit counts zero instead of failing on the absent folder.
	 */
	public function testAPurgeWithNoFolderCountsZero(): void {
		$this->assertSame(0, $this->store->purge());
	}//end testAPurgeWithNoFolderCountsZero()

	/**
	 * On a no-op locking provider a key is not reserved, the store says so, and it says so once.
	 */
	public function testANoopLockingProviderIsSaidOnceInTheLog(): void {
		$time = $this->createMock(ITimeFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('locking provider is a no-op'));
		$noop = new FormIdempotencyStore(appData: $this->appData, time: $time, locks: new NoopLockingProvider(), l10n: $l10n, logger: $logger);

		$this->assertTrue($noop->reservationIsANoop());
		$noop->reserve(scope: 'form-1', key: 'k1');
		$noop->reserve(scope: 'form-1', key: 'k1');
		// A real provider never warns, and holds the key.
		$this->assertFalse($this->store->reservationIsANoop());
	}//end testANoopLockingProviderIsSaidOnceInTheLog()
}//end class
