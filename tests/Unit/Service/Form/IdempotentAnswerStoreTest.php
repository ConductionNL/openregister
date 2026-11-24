<?php

/**
 * Unit tests for IdempotentAnswerStore: the form key store's refusals as plain answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Form
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\IdempotentAnswerStoreInterface;
use OCA\OpenRegister\Service\Form\FormIdempotencyStore;
use OCA\OpenRegister\Service\Form\IdempotentAnswerStore;
use OCA\OpenRegister\Service\Form\IdempotentRequest;
use PHPUnit\Framework\TestCase;

/**
 * The adapter keeps the store's files and bindings and turns its 409s into states.
 */
class IdempotentAnswerStoreTest extends TestCase {
	/**
	 * A busy key reads as not reserved; a free one as reserved.
	 *
	 * @return void
	 */
	public function testTryReserveAnswersFalseWhileTheKeyIsBusy(): void {
		$store = $this->createMock(FormIdempotencyStore::class);
		$store->method('reserve')->willReturnCallback(
			function (string $scope, string $key): void {
				if ($key === 'busy') {
					throw new FormSubmitRefusedException('busy', 409);
				}
			}
		);
		$adapter = new IdempotentAnswerStore($store);

		$this->assertTrue($adapter->tryReserve(scope: 's', key: 'free'));
		$this->assertFalse($adapter->tryReserve(scope: 's', key: 'busy'));
	}//end testTryReserveAnswersFalseWhileTheKeyIsBusy()

	/**
	 * Remembered, none and taken map from an answer, null and a refusal.
	 *
	 * @return void
	 */
	public function testRememberedMapsTheStoreAnswerToAState(): void {
		$store = $this->createMock(FormIdempotencyStore::class);
		$store->method('answerFor')->willReturnCallback(
			function (IdempotentRequest $request): ?array {
				return match ($request->key) {
					'kept' => ['id' => 7],
					'taken' => throw new FormSubmitRefusedException('taken', 409),
					default => null,
				};
			}
		);
		$adapter = new IdempotentAnswerStore($store);

		$this->assertSame(['state' => IdempotentAnswerStoreInterface::REMEMBERED, 'answer' => ['id' => 7]], $adapter->remembered(scope: 's', key: 'kept', binding: 'b'));
		$this->assertSame(['state' => IdempotentAnswerStoreInterface::NONE, 'answer' => null], $adapter->remembered(scope: 's', key: 'new', binding: 'b'));
		$this->assertSame(['state' => IdempotentAnswerStoreInterface::TAKEN, 'answer' => null], $adapter->remembered(scope: 's', key: 'taken', binding: 'b'));
	}//end testRememberedMapsTheStoreAnswerToAState()

	/**
	 * Remember writes the same scope, key and binding the store reads back.
	 *
	 * @return void
	 */
	public function testRememberKeepsTheAnswerUnderTheSameRequest(): void {
		$store = $this->createMock(FormIdempotencyStore::class);
		$store->expects($this->once())->method('keep')->with(
			$this->callback(fn (IdempotentRequest $r): bool => $r->scope === 's' && $r->key === 'k' && $r->binding === 'b'),
			['id' => 7]
		);
		$store->expects($this->once())->method('release')->with('s', 'k');
		$store->method('bindingFor')->with('user:a', ['x' => 1])->willReturn('bound');
		$adapter = new IdempotentAnswerStore($store);

		$adapter->remember(scope: 's', key: 'k', binding: 'b', answer: ['id' => 7]);
		$adapter->release(scope: 's', key: 'k');
		$this->assertSame('bound', $adapter->bindingFor(subject: 'user:a', payload: ['x' => 1]));
	}//end testRememberKeepsTheAnswerUnderTheSameRequest()
}//end class
