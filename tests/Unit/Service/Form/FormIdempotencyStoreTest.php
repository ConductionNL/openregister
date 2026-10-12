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
use OCA\OpenRegister\Tests\Unit\Service\Form\Fakes\MemoryAppData;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Remember, find, expire, purge, and keys scoped per form.
 *
 * @covers \OCA\OpenRegister\Service\Form\FormIdempotencyStore
 */
class FormIdempotencyStoreTest extends TestCase {

	private int $now = 1_760_000_000;

	private MemoryAppData $appData;

	private FormIdempotencyStore $store;

	protected function setUp(): void {
		$this->appData = new MemoryAppData();
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);
		$this->store = new FormIdempotencyStore(appData: $this->appData, time: $time);
	}//end setUp()

	/**
	 * Nothing remembered: nothing found.
	 */
	public function testAnUnknownKeyFindsNothing(): void {
		$this->assertNull($this->store->find(scope: 'form-1', key: 'k1'));
	}//end testAnUnknownKeyFindsNothing()

	/**
	 * The remembered answer comes back unchanged within 24 hours.
	 */
	public function testARememberedAnswerIsFoundWithinTheWindow(): void {
		$answer = ['reference' => '2026-0412', 'id' => 'uuid-7'];
		$this->store->remember(scope: 'form-1', key: 'k1', response: $answer);

		$this->now += 86_399;
		$this->assertSame($answer, $this->store->find(scope: 'form-1', key: 'k1'));
	}//end testARememberedAnswerIsFoundWithinTheWindow()

	/**
	 * After 24 hours the key is free again, and the expired entry is gone.
	 */
	public function testAnExpiredAnswerIsNotFound(): void {
		$this->store->remember(scope: 'form-1', key: 'k1', response: ['id' => 'uuid-7']);

		$this->now += 86_401;
		$this->assertNull($this->store->find(scope: 'form-1', key: 'k1'));
		$this->assertSame([], $this->appData->folders[FormIdempotencyStore::FOLDER]->files);
	}//end testAnExpiredAnswerIsNotFound()

	/**
	 * The same key on another form is another submit.
	 */
	public function testKeysAreScopedPerForm(): void {
		$this->store->remember(scope: 'form-1', key: 'k1', response: ['id' => 'uuid-7']);

		$this->assertNull($this->store->find(scope: 'form-2', key: 'k1'));
	}//end testKeysAreScopedPerForm()

	/**
	 * The purge deletes only expired entries and counts them.
	 */
	public function testThePurgeCountsWhatItDeleted(): void {
		$this->store->remember(scope: 'f', key: 'old', response: ['id' => 'a']);
		$this->now += 50_000;
		$this->store->remember(scope: 'f', key: 'new', response: ['id' => 'b']);
		$this->now += 40_000;

		$this->assertSame(1, $this->store->purge());
		$this->assertSame(['id' => 'b'], $this->store->find(scope: 'f', key: 'new'));
		$this->assertSame(0, $this->store->purge());
	}//end testThePurgeCountsWhatItDeleted()

	/**
	 * A purge before any submit counts zero instead of failing on the absent folder.
	 */
	public function testAPurgeWithNoFolderCountsZero(): void {
		$this->assertSame(0, $this->store->purge());
	}//end testAPurgeWithNoFolderCountsZero()
}//end class
