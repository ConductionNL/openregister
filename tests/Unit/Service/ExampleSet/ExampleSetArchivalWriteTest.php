<?php

/**
 * Tests for ExampleSetArchival: merging the destruction-list keys into the archival
 * setting with a record of what each held, proving them, and reading a setting
 * that cannot be read.
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

use OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses   \OCA\OpenRegister\Service\ExampleSet\ExampleSetRow
 */
class ExampleSetArchivalWriteTest extends TestCase {

	/**
	 * The app config holding the setting.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * Class under test.
	 *
	 * @var ExampleSetArchival
	 */
	private ExampleSetArchival $archival;

	/**
	 * Build the archival writer over a mocked app config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config   = $this->createMock(IAppConfig::class);
		$this->archival = new ExampleSetArchival(appConfig: $this->config);
	}

	/**
	 * A row of the archival part.
	 *
	 * @param array<string, mixed> $data The keys.
	 *
	 * @return ExampleSetRow
	 */
	private function row(array $data): ExampleSetRow {
		return new ExampleSetRow(app: 'openregister', key: 'archival', data: $data);
	}

	/**
	 * Merging keeps the other keys, and records what each written key held and whether it was there.
	 *
	 * @return void
	 */
	public function testWriteMergesAndRecordsWhatEachKeyHeld(): void {
		$this->config->expects($this->once())->method('hasKey')->with('openregister', 'archival')->willReturn(true);
		$this->config->method('getValueString')->with('openregister', 'archival', '')
			->willReturn('{"destructionListRegister":"1","retention":"P5Y"}');
		$this->config->expects($this->once())->method('setValueString')
			->with('openregister', 'archival', '{"destructionListRegister":4,"retention":"P5Y","destructionListSchema":"lists"}')
			->willReturn(true);

		$this->assertSame(
			[
				'error' => '',
				'entry' => [
					'hadSetting' => true,
					'set'        => ['destructionListRegister' => 4, 'destructionListSchema' => 'lists'],
					'had'        => ['destructionListRegister' => true, 'destructionListSchema' => false],
					'old'        => ['destructionListRegister' => '1', 'destructionListSchema' => null],
				],
			],
			$this->archival->write(row: $this->row(['destructionListRegister' => 4, 'destructionListSchema' => 'lists']))
		);
	}

	/**
	 * A setting that cannot be read counts as absent and empty; the merge is then the keys alone.
	 *
	 * @return void
	 */
	public function testWriteOverAnUnreadableSettingStartsEmpty(): void {
		$this->config->method('hasKey')->willThrowException(new RuntimeException('db down'));
		$this->config->method('getValueString')->willThrowException(new RuntimeException('db down'));
		$this->config->expects($this->once())->method('setValueString')
			->with('openregister', 'archival', '{"destructionListSchema":9}')
			->willReturn(true);

		$result = $this->archival->write(row: $this->row(['destructionListSchema' => 9]));

		$this->assertSame('', $result['error']);
		$this->assertFalse($result['entry']['hadSetting']);
		$this->assertSame(['destructionListSchema' => false], $result['entry']['had']);
	}

	/**
	 * A setting that cannot be stored is the row's error, with no entry.
	 *
	 * @return void
	 */
	public function testWriteThatCannotStoreIsTheError(): void {
		$this->config->method('hasKey')->willReturn(false);
		$this->config->method('getValueString')->willReturn('');
		$this->config->method('setValueString')->willThrowException(new RuntimeException('read-only config'));

		$this->assertSame(
			['error' => 'read-only config', 'entry' => null],
			$this->archival->write(row: $this->row(['destructionListRegister' => 2]))
		);
	}

	/**
	 * A key holding what the set wrote, as text or number, is not lost; a changed or missing key is.
	 *
	 * @return void
	 */
	public function testLostComparesEachKeyAsAnId(): void {
		$this->config->method('getValueString')->willReturn('{"destructionListRegister":"4","destructionListSchema":5}');

		$this->assertFalse($this->archival->lost(entry: ['set' => ['destructionListRegister' => 4, 'destructionListSchema' => '5']]));
		$this->assertTrue($this->archival->lost(entry: ['set' => ['destructionListRegister' => 4, 'destructionListSchema' => 6]]));
		$this->assertTrue($this->archival->lost(entry: ['set' => ['destructionListRegister' => null]]));
		$this->assertFalse($this->archival->lost(entry: []));
	}

	/**
	 * A setting that is gone loses every key the set wrote.
	 *
	 * @return void
	 */
	public function testLostWhenTheSettingIsGone(): void {
		$this->config->method('getValueString')->willReturn('');

		$this->assertTrue($this->archival->lost(entry: ['set' => ['destructionListRegister' => 4]]));
	}
}
