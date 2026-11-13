<?php

/**
 * MaskFormSettingTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Exception\UnknownMaskFormException;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The `anonymisation.maskForms` file setting (REQ-PIS-010): refused when it
 * cannot be applied, stored normalised, kept by a save that does not name it.
 *
 * @covers \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Files\Service\File\MaskForm
 * @uses   \OCA\OpenRegister\Service\File\ArchiveBounds
 */
class MaskFormSettingTest extends TestCase {

	/**
	 * The stored fileManagement JSON, as the app config holds it.
	 *
	 * @var string
	 */
	private string $stored = '';

	private function handler(): FileSettingsHandler {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === 'fileManagement' ? $this->stored : $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === 'fileManagement') {
					$this->stored = $value;
				}

				return true;
			}
		);

		return new FileSettingsHandler($appConfig);
	}//end handler()

	private function storedMaskForms(): mixed {
		return (json_decode($this->stored, true)['anonymisation']['maskForms'] ?? null);
	}//end storedMaskForms()

	public function testAnUnknownFormIsRefused(): void {
		$this->expectException(UnknownMaskFormException::class);
		$this->expectExceptionMessage('anonymisation.maskForms.IBAN names an unknown mask form.');

		try {
			$this->handler()->updateFileSettingsOnly(['anonymisation' => ['maskForms' => ['IBAN' => ['form' => 'middle']]]]);
		} finally {
			$this->assertSame('', $this->stored, 'nothing is stored');
		}
	}//end testAnUnknownFormIsRefused()

	public function testAKeepCountBelowOneIsRefused(): void {
		$this->expectException(UnknownMaskFormException::class);
		$this->expectExceptionMessage('anonymisation.maskForms.PHONE needs a whole number n of at least 1.');

		$this->handler()->updateFileSettingsOnly(['anonymisation' => ['maskForms' => ['PHONE' => ['form' => 'last', 'n' => 0]]]]);
	}//end testAKeepCountBelowOneIsRefused()

	public function testAValidFormIsStoredAndReadBack(): void {
		$handler = $this->handler();
		$handler->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => 0.7, 'maskForms' => ['IBAN' => ['form' => 'last', 'n' => '4']]]]);

		$this->assertSame(['IBAN' => ['form' => 'last', 'n' => 4]], $this->storedMaskForms());
		$this->assertSame(['IBAN' => ['form' => 'last', 'n' => 4]], $handler->maskForms());
		$this->assertSame(0.7, $handler->confidenceThreshold());
	}//end testAValidFormIsStoredAndReadBack()

	public function testASaveThatDoesNotNameTheFormsKeepsThem(): void {
		$this->stored = json_encode(['anonymisation' => ['confidenceThreshold' => 0.5, 'maskForms' => ['EMAIL' => ['form' => 'email-local']]]]);
		$handler = $this->handler();

		$handler->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => 0.8]]);
		$this->assertSame(['EMAIL' => ['form' => 'email-local']], $this->storedMaskForms());

		$handler->updateFileSettingsOnly(['batchSize' => 5]);
		$this->assertSame(['EMAIL' => ['form' => 'email-local']], $this->storedMaskForms());

		$handler->updateFileSettingsOnly(['anonymisation' => ['maskForms' => []]]);
		$this->assertSame([], $this->storedMaskForms(), 'an empty map clears them');
	}//end testASaveThatDoesNotNameTheFormsKeepsThem()

	public function testNothingOrAnInvalidStoredValueMasksNothing(): void {
		$this->assertSame([], $this->handler()->maskForms());

		$this->stored = json_encode(['anonymisation' => ['maskForms' => ['IBAN' => ['form' => 'middle']]]]);
		$this->assertSame([], $this->handler()->maskForms());

		$this->stored = json_encode(['anonymisation' => ['maskForms' => 'last']]);
		$this->assertSame([], $this->handler()->maskForms());
	}//end testNothingOrAnInvalidStoredValueMasksNothing()
}//end class
