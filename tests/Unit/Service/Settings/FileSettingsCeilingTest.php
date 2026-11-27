<?php

/**
 * The text extraction ceiling is stated as textExtraction.maxBytes, migrated from maxFileSizeMB.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-text-extraction-has-a-stated-ceiling-and-a-file-above-it-stays-findable-req-lfh-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Files\Service\TextExtraction\ExtractionCeiling;
use OCA\OpenRegister\Repair\MigrateTextExtractionCeiling;
use OCA\OpenRegister\Service\Anonymisation\CapacityPolicy;
use OCA\OpenRegister\Service\Anonymisation\OfficialsSource;
use OCA\OpenRegister\Service\File\ArchiveBounds;
use OCA\OpenRegister\Service\Settings\EntityPatternSetSetting;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @covers \OCA\OpenRegister\Repair\MigrateTextExtractionCeiling
 * @uses   \OCA\OpenRegister\Files\Service\TextExtraction\ExtractionCeiling
 * @uses   \OCA\OpenRegister\Service\File\ArchiveBounds
 * @uses   \OCA\OpenRegister\Service\Anonymisation\CapacityPolicy
 * @uses   \OCA\OpenRegister\Service\Anonymisation\OfficialsSource
 * @uses   \OCA\OpenRegister\Service\Settings\EntityPatternSetSetting
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewPolicy
 * @uses   \OCA\OpenRegister\Service\Decision\ReviewAnnotationValidator
 */
class FileSettingsCeilingTest extends TestCase {

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * An app config backed by $this->store.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$appConfig->method('getValueBool')->willReturn(false);
		$appConfig->method('hasKey')->willReturn(false);

		return $appConfig;
	}//end appConfig()

	/**
	 * An upgraded install with maxFileSizeMB 100 reads 104857600 bytes, the
	 * repair step stores that, and the legacy keys are gone from both.
	 *
	 * @return void
	 */
	public function testTheCeilingIsReturnedAndMigrated(): void {
		$this->store['fileManagement'] = json_encode(['maxFileSizeMB' => 100, 'maxFileSize' => 100, 'chunkSize' => 700]);
		$appConfig = $this->appConfig();
		$handler = new FileSettingsHandler(appConfig: $appConfig);

		$read = $handler->getFileSettingsOnly();
		$this->assertSame(['maxBytes' => 104857600], $read['textExtraction']);
		$this->assertArrayNotHasKey('maxFileSizeMB', $read);
		$this->assertArrayNotHasKey('maxFileSize', $read);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Text extraction ceiling is now 104857600 bytes.');
		(new MigrateTextExtractionCeiling(appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class)))->run($output);

		$stored = json_decode($this->store['fileManagement'], true);
		$this->assertSame(['maxFileSizeMB' => null, 'maxFileSize' => null, 'chunkSize' => 700, 'textExtraction' => ['maxBytes' => 104857600]], [
			'maxFileSizeMB' => ($stored['maxFileSizeMB'] ?? null),
			'maxFileSize' => ($stored['maxFileSize'] ?? null),
			'chunkSize' => $stored['chunkSize'],
			'textExtraction' => $stored['textExtraction'],
		]);
		$this->assertArrayNotHasKey('maxFileSizeMB', $stored);
	}//end testTheCeilingIsReturnedAndMigrated()

	/**
	 * A legacy value of 50 MiB is carried into bytes, not reset to the default.
	 *
	 * @return void
	 */
	public function testALegacyCeilingKeepsItsValue(): void {
		$this->store['fileManagement'] = json_encode(['maxFileSizeMB' => 50]);
		$appConfig = $this->appConfig();

		(new MigrateTextExtractionCeiling(appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class)))->run($this->createMock(IOutput::class));

		$this->assertSame(['textExtraction' => ['maxBytes' => 52428800]], json_decode($this->store['fileManagement'], true));
	}//end testALegacyCeilingKeepsItsValue()

	/**
	 * Settings without a legacy key are not written, so a second run changes nothing.
	 *
	 * @return void
	 */
	public function testAMigratedInstallIsNotRewritten(): void {
		$stored = json_encode(['textExtraction' => ['maxBytes' => 2000]]);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($stored);
		$appConfig->expects($this->never())->method('setValueString');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new MigrateTextExtractionCeiling(appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class)))->run($output);
	}//end testAMigratedInstallIsNotRewritten()

	/**
	 * A failing config store is logged, never thrown into the upgrade.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsLoggedNotThrown(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[MigrateTextExtractionCeiling] Could not migrate the text extraction ceiling',
			$this->callback(static fn (array $context): bool => $context['error'] === 'down')
		);

		(new MigrateTextExtractionCeiling(appConfig: $appConfig, logger: $logger))->run($this->createMock(IOutput::class));
	}//end testAFailingStoreIsLoggedNotThrown()

	/**
	 * Nothing stored reads the default ceiling and no legacy key.
	 *
	 * @return void
	 */
	public function testTheDefaultCeilingIsStated(): void {
		$read = (new FileSettingsHandler(appConfig: $this->appConfig()))->getFileSettingsOnly();

		$this->assertSame(['maxBytes' => ExtractionCeiling::DEFAULT_BYTES], $read['textExtraction']);
		$this->assertArrayNotHasKey('maxFileSizeMB', $read);
		$this->assertArrayNotHasKey('maxFileSize', $read);
	}//end testTheDefaultCeilingIsStated()

	/**
	 * A saved ceiling is stored in bytes, a legacy submitted one is converted,
	 * and the legacy keys are never stored.
	 *
	 * @return void
	 */
	public function testASavedCeilingIsStoredInBytes(): void {
		$handler = new FileSettingsHandler(appConfig: $this->appConfig());

		$saved = $handler->updateFileSettingsOnly(['textExtraction' => ['maxBytes' => 5000]]);
		$this->assertSame(['maxBytes' => 5000], $saved['textExtraction']);
		$this->assertSame(['maxBytes' => 5000], json_decode($this->store['fileManagement'], true)['textExtraction']);

		$saved = $handler->updateFileSettingsOnly(['maxFileSizeMB' => 2, 'maxFileSize' => 9]);
		$stored = json_decode($this->store['fileManagement'], true);
		$this->assertSame(['maxBytes' => 2097152], $saved['textExtraction']);
		$this->assertArrayNotHasKey('maxFileSizeMB', $stored);
		$this->assertArrayNotHasKey('maxFileSize', $stored);
	}//end testASavedCeilingIsStoredInBytes()
}//end class
