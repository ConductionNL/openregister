<?php

/**
 * The pre-destruction pass keeps the notified set to what is still relevant and saves only on change.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob\Support;

use OCA\OpenRegister\BackgroundJob\Support\PreDestructionPass;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\Support\PreDestructionPass
 */
class PreDestructionPassTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A pass over a stored notified list.
	 *
	 * @param string $stored The stored JSON.
	 */
	private function pass(string $stored): PreDestructionPass {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('openregister', 'retention_notified_objects', '[]')
			->willReturn($stored);

		return new PreDestructionPass(appConfig: $this->appConfig, logger: $this->logger);
	}//end pass()

	public function testKeepIfNotifiedAnswersFromTheStoredList(): void {
		$pass = $this->pass('["a","b"]');

		$this->assertTrue($pass->keepIfNotified('a'));
		$this->assertFalse($pass->keepIfNotified('c'));
		$this->assertFalse($pass->keepIfNotified(null));
	}//end testKeepIfNotifiedAnswersFromTheStoredList()

	public function testFullPassKeepsOnlyStillRelevantAndNewlyNotified(): void {
		$pass = $this->pass('["a","b","c"]');
		$pass->keepIfNotified('b');
		$pass->markNotified('d');

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'retention_notified_objects', '["b","d"]');
		$this->logger->expects($this->once())
			->method('info')
			->with('[DestructionCheckJob] Sent 1 pre-destruction notifications');

		$pass->save(truncated: false);
	}//end testFullPassKeepsOnlyStillRelevantAndNewlyNotified()

	public function testTruncatedPassKeepsEveryStoredEntryAndAddsNewOnes(): void {
		$pass = $this->pass('["a","b"]');
		$pass->markNotified('b');
		$pass->markNotified('c');

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'retention_notified_objects', '["a","b","c"]');
		$this->logger->expects($this->once())
			->method('info')
			->with('[DestructionCheckJob] Sent 2 pre-destruction notifications');

		$pass->save(truncated: true);
	}//end testTruncatedPassKeepsEveryStoredEntryAndAddsNewOnes()

	public function testNothingIsSavedWhenNothingChanged(): void {
		$pass = $this->pass('["a","b"]');
		$pass->keepIfNotified('a');
		$pass->keepIfNotified('b');

		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->never())->method('info');

		$pass->save(truncated: false);
	}//end testNothingIsSavedWhenNothingChanged()

	public function testShrinkingTheListSavesEvenWithoutNewNotifications(): void {
		$pass = $this->pass('["a","b"]');
		$pass->keepIfNotified('a');

		$this->appConfig->expects($this->once())
			->method('setValueString')
			->with('openregister', 'retention_notified_objects', '["a"]');
		$this->logger->expects($this->once())
			->method('info')
			->with('[DestructionCheckJob] Sent 0 pre-destruction notifications');

		$pass->save(truncated: false);
	}//end testShrinkingTheListSavesEvenWithoutNewNotifications()

	public function testUnreadableStoredValueStartsFromAnEmptyList(): void {
		$pass = $this->pass('not json');

		$this->assertFalse($pass->keepIfNotified('a'));
		$this->appConfig->expects($this->never())->method('setValueString');

		$pass->save(truncated: false);
	}//end testUnreadableStoredValueStartsFromAnEmptyList()
}//end class
