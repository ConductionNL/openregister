<?php

/**
 * Tests for ExampleSetAppConfig: the sensitive-key refusal, generated values and
 * their fingerprints, and the failure paths of apply, raise and revert.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigKey;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigValue;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigStore
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigValue
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigKey
 */
class ExampleSetAppConfigGeneratedTest extends TestCase {

	/**
	 * Nextcloud's app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * Makes generated values.
	 *
	 * @var ISecureRandom&MockObject
	 */
	private ISecureRandom&MockObject $random;

	/**
	 * Class under test.
	 *
	 * @var ExampleSetAppConfig
	 */
	private ExampleSetAppConfig $appConfig;

	/**
	 * Build the writer over mocked config and randomness.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config    = $this->createMock(IAppConfig::class);
		$this->random    = $this->createMock(ISecureRandom::class);
		$this->appConfig = new ExampleSetAppConfig(appConfig: $this->config, random: $this->random);
	}

	/**
	 * The address alpha/token.
	 *
	 * @return ExampleSetConfigKey
	 */
	private function at(): ExampleSetConfigKey {
		return new ExampleSetConfigKey(app: 'alpha', key: 'token');
	}

	/**
	 * A generated record entry for alpha/token.
	 *
	 * @param array<string, mixed> $extra Extra or overriding fields.
	 *
	 * @return array<string, mixed>
	 */
	private function generatedEntry(array $extra=[]): array {
		return array_merge(['app' => 'alpha', 'key' => 'token', 'had' => true, 'old' => '', 'new' => '', 'generated' => true], $extra);
	}

	/**
	 * An existing key kept as sensitive is refused unless the set generates it; an absent key is never sensitive.
	 *
	 * @return void
	 */
	public function testASensitiveKeyIsRefusedUnlessGenerated(): void {
		$this->config->method('hasKey')->willReturnCallback(static fn (string $app, string $key): bool => $key === 'token');
		$this->config->expects($this->once())->method('isSensitive')->with('alpha', 'token')->willReturn(true);

		$this->assertSame(
			'app config alpha token is kept as sensitive; a set may only generate such a value, never write one from a file',
			$this->appConfig->refusal(at: $this->at(), generate: false)
		);
		$this->assertSame('', $this->appConfig->refusal(at: $this->at(), generate: true));
		$this->assertSame('', $this->appConfig->refusal(at: new ExampleSetConfigKey(app: 'alpha', key: 'absent'), generate: false));
	}

	/**
	 * A key whose sensitivity cannot be read is treated as sensitive.
	 *
	 * @return void
	 */
	public function testAKeyWhoseSensitivityCannotBeReadIsRefused(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('isSensitive')->willThrowException(new RuntimeException('unknown key'));

		$this->assertStringStartsWith('app config alpha token is kept as sensitive', $this->appConfig->refusal(at: $this->at(), generate: false));
	}

	/**
	 * A generated value is stored as sensitive and recorded only by its fingerprint.
	 *
	 * @return void
	 */
	public function testAGeneratedValueIsStoredSensitiveAndRecordedByFingerprint(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('getValueString')->willReturn('');
		$this->random->expects($this->once())->method('generate')->with(40, ISecureRandom::CHAR_ALPHANUMERIC)->willReturn('s3cr3t');
		$this->config->expects($this->once())->method('setValueString')->with('alpha', 'token', 's3cr3t', false, true)->willReturn(true);

		$result = $this->appConfig->apply(target: new ExampleSetConfigValue(at: $this->at(), kind: ExampleSetConfigValue::KIND_GENERATED));

		$this->assertSame(
			[
				'error' => '',
				'entry' => [
					'app'         => 'alpha',
					'key'         => 'token',
					'had'         => true,
					'old'         => '',
					'new'         => '',
					'generated'   => true,
					'fingerprint' => hash('sha256', 's3cr3t'),
				],
			],
			$result
		);
	}

	/**
	 * A key that already holds a value is not generated again.
	 *
	 * @return void
	 */
	public function testAnExistingValueIsNotGeneratedAgain(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('getValueString')->willReturn('already');
		$this->random->expects($this->never())->method('generate');
		$this->config->expects($this->never())->method('setValueString');

		$this->assertSame(
			['error' => '', 'entry' => null],
			$this->appConfig->apply(target: new ExampleSetConfigValue(at: $this->at(), kind: ExampleSetConfigValue::KIND_GENERATED))
		);
	}

	/**
	 * A write that fails is reported with its message.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsTheError(): void {
		$this->config->method('hasKey')->willReturn(false);
		$this->config->method('getValueString')->willReturn('');
		$this->config->method('setValueString')->willThrowException(new RuntimeException('config locked'));

		$this->assertSame(
			['error' => 'config locked', 'entry' => null],
			$this->appConfig->apply(target: new ExampleSetConfigValue(at: $this->at(), value: 'v'))
		);
	}

	/**
	 * Raising a recorded entry whose write fails gives no entry and reports nothing written.
	 *
	 * @return void
	 */
	public function testARaiseThatCannotWriteGivesNoEntry(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('getValueInt')->willReturn(3);
		$this->config->expects($this->once())->method('setValueInt')->with('alpha', 'token', 10)->willThrowException(new RuntimeException('no'));

		$this->assertSame(
			['entry' => null, 'written' => false],
			$this->appConfig->raise(entry: ['app' => 'alpha', 'key' => 'token', 'had' => true, 'old' => '3', 'new' => '3'], value: '10')
		);
	}

	/**
	 * A generated entry is lost exactly when the key is empty or gone.
	 *
	 * @return void
	 */
	public function testAGeneratedEntryIsLostWhenEmpty(): void {
		$this->config->method('getValueString')->willReturnOnConsecutiveCalls('', 'anything');

		$this->assertTrue($this->appConfig->lost(entry: $this->generatedEntry()));
		$this->assertFalse($this->appConfig->lost(entry: $this->generatedEntry()));
	}

	/**
	 * A generated value somebody replaced since no longer matches the fingerprint, and stays.
	 *
	 * @return void
	 */
	public function testAReplacedGeneratedValueIsLeft(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('getValueString')->willReturn('admin-set');
		$this->config->expects($this->never())->method('setValueString');
		$this->config->expects($this->never())->method('deleteKey');

		$this->assertSame('left', $this->appConfig->revert(entry: $this->generatedEntry(['fingerprint' => hash('sha256', 's3cr3t')])));
	}

	/**
	 * A generated value that still matches its fingerprint gets back what the key held.
	 *
	 * @return void
	 */
	public function testAnUnchangedGeneratedValueIsPutBack(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->method('getValueString')->willReturn('s3cr3t');
		$this->config->expects($this->once())->method('setValueString')->with('alpha', 'token', '')->willReturn(true);
		$this->config->expects($this->never())->method('deleteKey');

		$this->assertSame('restored', $this->appConfig->revert(entry: $this->generatedEntry(['fingerprint' => hash('sha256', 's3cr3t')])));
	}

	/**
	 * A generated entry from before fingerprints cannot tell a change, and is deleted as it always was.
	 *
	 * @return void
	 */
	public function testAGeneratedEntryWithoutFingerprintIsDeleted(): void {
		$this->config->method('hasKey')->willReturn(true);
		$this->config->expects($this->never())->method('getValueString');
		$this->config->expects($this->once())->method('deleteKey')->with('alpha', 'token');

		$this->assertSame('restored', $this->appConfig->revert(entry: $this->generatedEntry()));
	}

	/**
	 * A key that is already gone is not changed since; one the set added is deleted, and a failing delete is a failure.
	 *
	 * @return void
	 */
	public function testAGoneKeyIsDeletedAndAFailingDeleteFails(): void {
		$this->config->method('hasKey')->willReturn(false);
		$this->config->expects($this->exactly(2))->method('deleteKey')->with('alpha', 'token')
			->willReturnOnConsecutiveCalls(null, $this->throwException(new RuntimeException('no')));
		$entry = ['app' => 'alpha', 'key' => 'token', 'had' => false, 'old' => '', 'new' => 'v'];

		$this->assertSame('restored', $this->appConfig->revert(entry: $entry));
		$this->assertSame('failed', $this->appConfig->revert(entry: $entry));
	}
}
