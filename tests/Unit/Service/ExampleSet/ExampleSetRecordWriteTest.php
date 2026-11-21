<?php

/**
 * Unit tests for ExampleSetRecord — whether a record holds anything, and how it is stored.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ExampleSetRecordWriteTest extends TestCase {

	private IAppConfig&MockObject $config;
	private ExampleSetRecord $record;

	protected function setUp(): void {
		$this->config = $this->createMock(IAppConfig::class);
		$this->record = new ExampleSetRecord($this->config);
	}

	public function testTheBlankRecordIsEmpty(): void {
		$this->assertTrue($this->record->isEmpty(ExampleSetRecord::BLANK));
		$this->assertTrue($this->record->isEmpty([]));
	}

	public function testAnyHeldPartMakesTheRecordNonEmpty(): void {
		$this->assertFalse($this->record->isEmpty(['users' => ['ann' => 'created']]));
		$this->assertFalse($this->record->isEmpty(['grants' => [['id' => '1']]]));
	}

	public function testARegisterWithNoJobsLeftDoesNotCount(): void {
		$this->assertTrue($this->record->isEmpty(['registers' => ['zaken' => []]]));
		$this->assertFalse($this->record->isEmpty(['registers' => ['zaken' => ['job-1']]]));
	}

	public function testAHoldingRecordIsStoredLazilyAsJson(): void {
		$record = ['users' => ['ann' => 'created'], 'groups' => ['team']];
		$this->config->expects($this->once())->method('setValueString')
			->with(AppIdentity::APP_ID, 'example_set_demo', json_encode($record), true);
		$this->config->expects($this->never())->method('deleteKey');

		$this->record->write('demo', $record);
	}

	public function testAnEmptyRecordDeletesTheStoredKey(): void {
		$this->config->expects($this->once())->method('deleteKey')->with(AppIdentity::APP_ID, 'example_set_demo');
		$this->config->expects($this->never())->method('setValueString');

		$this->record->write('demo', ExampleSetRecord::BLANK);
	}

	public function testRemovedRegistersAreRememberedSoTheNextInstallImportsAgain(): void {
		$this->config->expects($this->once())->method('setValueString')
			->with(AppIdentity::APP_ID, 'example_set_demo', '{"registers":{"zaken":[]}}', true);
		$this->config->expects($this->never())->method('deleteKey');

		$this->record->write('demo', ['registers' => ['zaken' => []]]);
	}

	public function testReadFallsBackToTheEagerValueWhenNoLazyOneIsStored(): void {
		$this->config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default, bool $lazy): string => ($lazy === true) ? '' : '{"groups":["team",3]}'
		);

		$this->assertSame(['team'], $this->record->read('demo')['groups']);
	}

	public function testUndecodableStorageReadsAsTheBlankRecord(): void {
		$this->config->method('getValueString')->willReturn('{broken');

		$this->assertSame(ExampleSetRecord::BLANK, $this->record->read('demo'));
	}
}
