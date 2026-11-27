<?php

/**
 * The upload-in-parts settings: a part size between 1 MiB and 100 MiB and a session lifetime.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Exception\UploadPartSizeOutOfRangeException;
use OCA\OpenRegister\Kernel\Contracts\UploadSettingsReaderInterface;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ExtractionCeiling
 * @uses   \OCA\OpenRegister\Service\File\ArchiveBounds
 * @uses   \OCA\OpenRegister\Service\Anonymisation\CapacityPolicy
 * @uses   \OCA\OpenRegister\Service\Anonymisation\OfficialsSource
 * @uses   \OCA\OpenRegister\Service\Settings\EntityPatternSetSetting
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator
 */
class FileSettingsUploadTest extends TestCase {

	/**
	 * The stored file settings.
	 *
	 * @var string
	 */
	private string $stored = '';

	/**
	 * The handler over an app config holding $this->stored.
	 *
	 * @return FileSettingsHandler
	 */
	private function handler(): FileSettingsHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === 'fileManagement' ? $this->stored : $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored = $value;
				return true;
			}
		);

		return new FileSettingsHandler(appConfig: $appConfig);
	}//end handler()

	/**
	 * Nothing stored reads 10 MiB parts and a 24 hour session.
	 *
	 * @return void
	 */
	public function testTheDefaultsAreStated(): void {
		$this->assertSame(['partSizeBytes' => 10485760, 'sessionTtl' => 'PT24H'], $this->handler()->uploadSettings());
		$this->assertSame(['partSizeBytes' => 10485760, 'sessionTtl' => 'PT24H'], $this->handler()->getFileSettingsOnly()['upload']);
	}//end testTheDefaultsAreStated()

	/**
	 * The Files component reads the upload settings through the Kernel port, which the
	 * handler answers, with the port's bounds.
	 *
	 * @return void
	 */
	public function testTheUploadPortIsAnsweredByTheHandler(): void {
		$this->assertSame(FileSettingsHandler::class, ContractBindings::BINDINGS[UploadSettingsReaderInterface::class]);

		$this->stored = (string) json_encode(['upload' => ['partSizeBytes' => UploadSettingsReaderInterface::MAX_PART_SIZE + 1, 'sessionTtl' => 'PT1H']]);
		$port = $this->handler();
		$this->assertInstanceOf(UploadSettingsReaderInterface::class, $port);
		$this->assertSame(
			['partSizeBytes' => UploadSettingsReaderInterface::DEFAULT_PART_SIZE, 'sessionTtl' => 'PT1H'],
			$port->uploadSettings(),
			'A part size above the port\'s maximum reads as the default.'
		);
	}//end testTheUploadPortIsAnsweredByTheHandler()

	/**
	 * A part size within the bounds is stored and read back, with the lifetime.
	 *
	 * @return void
	 */
	public function testAPartSizeWithinTheBoundsIsStored(): void {
		$handler = $this->handler();

		$saved = $handler->updateFileSettingsOnly(['upload' => ['partSizeBytes' => 1048576, 'sessionTtl' => 'PT6H']]);

		$this->assertSame(['partSizeBytes' => 1048576, 'sessionTtl' => 'PT6H'], $saved['upload']);
		$this->assertSame(['partSizeBytes' => 1048576, 'sessionTtl' => 'PT6H'], $handler->uploadSettings());
		$handler->updateFileSettingsOnly(['upload' => ['partSizeBytes' => '104857600']]);
		$this->assertSame(['partSizeBytes' => 104857600, 'sessionTtl' => 'PT24H'], $handler->uploadSettings());
	}//end testAPartSizeWithinTheBoundsIsStored()

	/**
	 * A part size outside 1 MiB to 100 MiB is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAPartSizeOutsideTheBoundsIsRefused(): void {
		$handler = $this->handler();
		foreach ([1048575, 104857601, 0, -5, 'ten', 1.5e6] as $partSize) {
			try {
				$handler->updateFileSettingsOnly(['upload' => ['partSizeBytes' => $partSize]]);
				$this->fail('Stored a part size of ' . var_export($partSize, true));
			} catch (UploadPartSizeOutOfRangeException $e) {
				$this->assertSame('upload.partSizeBytes must be a whole number of bytes between 1048576 and 104857600.', $e->getMessage());
			}
		}

		$this->assertSame('', $this->stored);
	}//end testAPartSizeOutsideTheBoundsIsRefused()

	/**
	 * Unusable stored values read as the defaults.
	 *
	 * @return void
	 */
	public function testUnusableStoredValuesReadAsTheDefaults(): void {
		$this->stored = (string) json_encode(['upload' => ['partSizeBytes' => 5, 'sessionTtl' => 'one day']]);

		$this->assertSame(['partSizeBytes' => 10485760, 'sessionTtl' => 'PT24H'], $this->handler()->uploadSettings());
	}//end testUnusableStoredValuesReadAsTheDefaults()
}//end class
