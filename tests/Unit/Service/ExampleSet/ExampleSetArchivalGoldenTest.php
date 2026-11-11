<?php

/**
 * Golden: which archival keys an example set may write and which it refuses, and how removing the set puts the
 * archival setting back, pinned on the exact refusal, outcome and stored setting.
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-openregisters-fragment-may-name-the-destruction-list-register-and-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 */
class ExampleSetArchivalGoldenTest extends TestCase {
	/**
	 * What happened to the setting, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * The archival writer over a stored setting.
	 *
	 * @param string $stored     The setting as stored, JSON.
	 * @param bool   $failDelete Whether deleting the setting throws.
	 * @param bool   $failStore  Whether storing the setting throws.
	 *
	 * @return ExampleSetArchival
	 */
	private function archival(string $stored, bool $failDelete=false, bool $failStore=false): ExampleSetArchival {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($stored);
		$config->method('deleteKey')->willReturnCallback(function (string $app, string $key) use ($failDelete): void {
			$this->calls[] = 'delete '.$app.' '.$key;
			if ($failDelete === true) {
				throw new RuntimeException('no');
			}
		});
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value) use ($failStore): bool {
			$this->calls[] = 'store '.$app.' '.$key.' '.$value;
			if ($failStore === true) {
				throw new RuntimeException('no');
			}

			return true;
		});
		return new ExampleSetArchival(appConfig: $config);
	}//end archival()

	/**
	 * Archival keys and their exact refusal.
	 *
	 * @return void
	 */
	public function testRefusal(): void {
		$archival = $this->archival(stored: '');
		$this->assertSame(
			'key "colour" is not an archival key a set may write (only destructionListRegister, destructionListSchema)',
			$archival->refusal(data: ['destructionListRegister' => 1, 'colour' => 2], users: [])
		);
		$this->assertSame('it names no key', $archival->refusal(data: [], users: []));
		$this->assertSame('destructionListSchema is a register or schema id', $archival->refusal(data: ['destructionListSchema' => ''], users: []));
		$this->assertSame('destructionListSchema is a register or schema id', $archival->refusal(data: ['destructionListSchema' => 1.5], users: []));
		$this->assertSame('', $archival->refusal(data: ['destructionListRegister' => 3, 'destructionListSchema' => 'list'], users: []));
	}//end testRefusal()

	/**
	 * Removing puts back what a key held before the set wrote it, takes away what it did not hold, and leaves a key an
	 * administrator changed since.
	 *
	 * @return void
	 */
	public function testRevertPutsBackAndLeavesAnAdministratorsChange(): void {
		$archival = $this->archival(stored: '{"destructionListRegister":"3","destructionListSchema":7,"other":"x"}');
		$entry    = [
			'set'        => ['destructionListRegister' => 3, 'destructionListSchema' => 5],
			'had'        => ['destructionListRegister' => true],
			'old'        => ['destructionListRegister' => 1],
			'hadSetting' => true,
		];
		$this->assertSame('removed', $archival->revert(entry: $entry));
		$this->assertSame(['store openregister archival {"destructionListSchema":7,"other":"x","destructionListRegister":1}'], $this->calls);
	}//end testRevertPutsBackAndLeavesAnAdministratorsChange()

	/**
	 * A setting the set brought into being and that is empty again goes; one that was there is stored empty.
	 *
	 * @return void
	 */
	public function testRevertOfAnEmptySetting(): void {
		$entry = ['set' => ['destructionListRegister' => 3], 'hadSetting' => false];
		$this->assertSame('removed', $this->archival(stored: '{"destructionListRegister":3}')->revert(entry: $entry));
		$this->assertSame('failed', $this->archival(stored: '{"destructionListRegister":3}', failDelete: true)->revert(entry: $entry));
		$this->assertSame('removed', $this->archival(stored: '{"destructionListRegister":3}')->revert(entry: ['set' => ['destructionListRegister' => 3]]));
		$this->assertSame('removed', $this->archival(stored: 'not json')->revert(entry: ['set' => ['destructionListRegister' => null]]));
		$this->assertSame(
			[
				'delete openregister archival',
				'delete openregister archival',
				'store openregister archival []',
				'store openregister archival []',
			],
			$this->calls
		);
	}//end testRevertOfAnEmptySetting()

	/**
	 * A setting that cannot be stored is a failure.
	 *
	 * @return void
	 */
	public function testRevertThatCannotStore(): void {
		$entry = ['set' => ['destructionListRegister' => 3], 'hadSetting' => true];
		$this->assertSame('failed', $this->archival(stored: '{"destructionListRegister":3,"x":1}', failStore: true)->revert(entry: $entry));
	}//end testRevertThatCannotStore()
}//end class
