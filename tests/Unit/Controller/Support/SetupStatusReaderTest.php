<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/shell-reads-from-initial-state/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Controller\Support\SetupStatusReader;
use OCA\OpenRegister\Service\DemoDataServiceInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class SetupStatusReaderTest extends TestCase {
	/**
	 * @return array<string, array{string, string, bool}>
	 */
	public static function answers(): array {
		return [
			'nothing answered'   => ['', '', false],
			'declined'           => ['dismissed', '', true],
			'picked, not loaded' => ['', 'gemeente', true],
			'loaded'             => ['installed', 'gemeente', true],
		];
	}

	/**
	 * @dataProvider answers
	 */
	public function testTheDemoStepIsDoneOnceAnswered(string $decided, string $picked, bool $done): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnMap([
			['openregister', SetupStatusReader::DEMO_DECIDED_KEY, '', false, $decided],
			['openregister', SetupStatusReader::DATASET_KEY, '', false, $picked],
		]);
		$demo = $this->createMock(DemoDataServiceInterface::class);
		$demo->method('listChoices')->willReturn([]);

		$status = (new SetupStatusReader($appConfig, $demo))->read();

		$this->assertSame($done, $status['steps']['demo-data']['done']);
		$this->assertTrue($status['completed']);
		$this->assertSame(SetupStatusReader::SETUP_VERSION, $status['version']);
		$this->assertSame(['welcome', 'demo-data', 'done'], array_keys($status['steps']));
	}
}
