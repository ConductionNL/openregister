<?php

/**
 * The persons register, its boost and the place register settings (REQ-DKP-003):
 * a source without a purpose is refused, a saved register is read back, a save
 * that does not name them keeps them, and the default boost.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/detection-knows-the-person/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Exception\PersonRegisterPurposeMissingException;
use OCA\OpenRegister\Service\Anonymisation\CapacityPolicy;
use OCA\OpenRegister\Service\Anonymisation\OfficialsSource;
use OCA\OpenRegister\Service\Anonymisation\PersonRegisterSource;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The person register settings.
 */
#[CoversClass(FileSettingsHandler::class)]
#[CoversClass(PersonRegisterSource::class)]
#[UsesClass(CapacityPolicy::class)]
#[UsesClass(OfficialsSource::class)]
class PersonRegisterSettingTest extends TestCase {

	private IAppConfig&MockObject $appConfig;

	private string $stored = '';

	protected function setUp(): void {
		$this->stored = '';
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === 'fileManagement' && $this->stored !== '' ? $this->stored : $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === 'fileManagement') {
					$this->stored = $value;
				}

				return true;
			}
		);
	}//end setUp()

	/**
	 * A persons register named without a purpose is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testASourceWithoutAPurposeIsRefused(): void {
		$handler = new FileSettingsHandler($this->appConfig);

		try {
			$handler->updateFileSettingsOnly(
				['anonymisation' => ['personRegister' => ['type' => 'schema', 'register' => 'brp', 'schema' => 'personen', 'purpose' => '  ']]]
			);
			$this->fail('A persons register without a purpose was accepted');
		} catch (PersonRegisterPurposeMissingException $e) {
			$this->assertStringContainsString('anonymisation.personRegister needs a purpose', $e->getMessage());
		}

		$this->assertSame('', $this->stored);
		$this->assertNull($handler->personRegister());
	}//end testASourceWithoutAPurposeIsRefused()

	/**
	 * A saved register, boost and place register are read back normalised.
	 *
	 * @return void
	 */
	public function testASavedRegisterIsReadBack(): void {
		$handler = new FileSettingsHandler($this->appConfig);

		$handler->updateFileSettingsOnly(
			[
				'anonymisation' => [
					'personRegister' => ['type' => 'contacts', 'addressBook' => ' inwoners ', 'purpose' => 'Woo-verzoek anonimiseren'],
					'personRegisterBoost' => 0.3,
					'placeRegister' => ['type' => 'schema', 'register' => 'bag', 'schema' => 'straten', 'nameProperty' => 'straatnaam'],
				],
			]
		);

		$this->assertSame(
			['type' => 'contacts', 'addressBook' => 'inwoners', 'purpose' => 'Woo-verzoek anonimiseren'],
			$handler->personRegister()
		);
		$this->assertSame(0.3, $handler->personRegisterBoost());
		$this->assertSame(
			['type' => 'schema', 'register' => 'bag', 'schema' => 'straten', 'nameProperty' => 'straatnaam'],
			$handler->placeRegister()
		);
		$anonymisation = $handler->getFileSettingsOnly()['anonymisation'];
		$this->assertSame('inwoners', $anonymisation['personRegister']['addressBook']);
		$this->assertSame(0.3, $anonymisation['personRegisterBoost']);
	}//end testASavedRegisterIsReadBack()

	/**
	 * A save that does not name the registers keeps the stored ones.
	 *
	 * @return void
	 */
	public function testASaveWithoutTheRegistersKeepsThem(): void {
		$handler = new FileSettingsHandler($this->appConfig);
		$handler->updateFileSettingsOnly(
			['anonymisation' => ['personRegister' => ['type' => 'schema', 'register' => 'brp', 'schema' => 'personen', 'purpose' => 'anonimiseren'], 'personRegisterBoost' => 0.1]]
		);

		$handler->updateFileSettingsOnly(['anonymisation' => ['confidenceThreshold' => 0.7]]);

		$this->assertSame(
			['type' => 'schema', 'register' => 'brp', 'schema' => 'personen', 'nameProperty' => 'name', 'purpose' => 'anonimiseren'],
			$handler->personRegister()
		);
		$this->assertSame(0.1, $handler->personRegisterBoost());
		$this->assertNull($handler->placeRegister());
	}//end testASaveWithoutTheRegistersKeepsThem()

	/**
	 * Without a stored register: none, and the boost is the shipped 0.2; an out-of-range boost is the default.
	 *
	 * @return void
	 */
	public function testTheDefaults(): void {
		$handler = new FileSettingsHandler($this->appConfig);

		$this->assertNull($handler->personRegister());
		$this->assertNull($handler->placeRegister());
		$this->assertSame(0.2, $handler->personRegisterBoost());
		$this->assertSame(0.2, PersonRegisterSource::boost(value: 1.5));
		$this->assertSame(0.2, PersonRegisterSource::boost(value: 'x'));
		$this->assertSame(0.5, PersonRegisterSource::boost(value: '0.5'));
		$this->assertNull(PersonRegisterSource::placeRegister(value: ['type' => 'contacts', 'addressBook' => 'a']));
		$this->assertNull(PersonRegisterSource::personRegister(value: ['type' => 'schema', 'register' => 'brp', 'schema' => 'personen']));
		$this->assertSame('contacts:inwoners', PersonRegisterSource::label(source: ['type' => 'contacts', 'addressBook' => 'inwoners']));
		$this->assertSame('schema:brp/personen', PersonRegisterSource::label(source: ['type' => 'schema', 'register' => 'brp', 'schema' => 'personen', 'nameProperty' => 'name']));
	}//end testTheDefaults()
}//end class
