<?php

/**
 * Behaviour test for TranslationSettingsController: the "Translation" section.
 *
 * Asserts that the stored choice and the reported providers are answered,
 * that record text may be sent only when Task Processing is chosen and
 * confirmed, and that an unknown provider is refused before anything is written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller\Settings;

use OCA\OpenRegister\Controller\Settings\TranslationSettingsController;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\TaskProcessing\Exception\Exception as TaskProcessingException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use PHPUnit\Framework\TestCase;

class TranslationSettingsControllerTest extends TestCase {

	/** @var array<string, mixed> The stored app settings, by key. */
	private array $stored = [];

	private function controller(array $providers = []): TranslationSettingsController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => (string) ($this->stored[$app . ':' . $key] ?? $default)
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default): bool => (bool) ($this->stored[$app . ':' . $key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . ':' . $key] = $value;
				return true;
			}
		);
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $app, string $key, bool $value): bool {
				$this->stored[$app . ':' . $key] = $value;
				return true;
			}
		);

		$manager = $this->createMock(IManager::class);
		$manager->method('getPreferredProvider')->willReturnCallback(
			function (string $taskType) use ($providers): IProvider {
				if (isset($providers[$taskType]) === false) {
					throw new TaskProcessingException('No provider for ' . $taskType);
				}

				$provider = $this->createMock(IProvider::class);
				$provider->method('getId')->willReturn($providers[$taskType][0]);
				$provider->method('getName')->willReturn($providers[$taskType][1]);
				return $provider;
			}
		);

		return new TranslationSettingsController($this->createMock(IRequest::class), $appConfig, $manager);
	}

	public function testDefaultsAreTheIdentityProviderAndNothingSent(): void {
		$data = $this->controller()->show()->getData();

		$this->assertSame(['provider' => 'identity', 'allowExternal' => false, 'providers' => []], $data);
	}

	public function testTheProvidersNextcloudReportsAreNamedWithWhetherTheyRunLocally(): void {
		$controller = $this->controller([
			'core:text2text:translate' => ['translate2:translate', 'Local translation'],
			'core:text2text' => ['integration_openai-text2text', 'OpenAI'],
		]);

		$this->assertSame(
			[
				['taskType' => 'core:text2text:translate', 'id' => 'translate2:translate', 'name' => 'Local translation', 'local' => true],
				['taskType' => 'core:text2text', 'id' => 'integration_openai-text2text', 'name' => 'OpenAI', 'local' => false],
			],
			$controller->show()->getData()['providers']
		);
	}

	public function testChoosingTaskProcessingWithTheConfirmationStoresBoth(): void {
		$response = $this->controller()->update('taskprocessing', true);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('taskprocessing', $this->stored['openregister:translation_provider']);
		$this->assertTrue($this->stored['openregister:translation_allow_external']);
		$this->assertSame(['provider' => 'taskprocessing', 'allowExternal' => true], array_slice($response->getData(), 0, 2));
	}

	public function testSavingWithoutTheConfirmationKeepsRecordTextHome(): void {
		$this->controller()->update('taskprocessing', false);

		$this->assertSame('taskprocessing', $this->stored['openregister:translation_provider']);
		$this->assertFalse($this->stored['openregister:translation_allow_external']);
	}

	public function testTheIdentityProviderNeverAllowsSending(): void {
		$this->controller()->update('identity', true);

		$this->assertSame('identity', $this->stored['openregister:translation_provider']);
		$this->assertFalse($this->stored['openregister:translation_allow_external']);
	}

	public function testAnUnknownProviderIsRefusedBeforeAnythingIsWritten(): void {
		$response = $this->controller()->update('deepl', true);

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Unknown translation provider: deepl'], $response->getData());
		$this->assertSame([], $this->stored);
	}
}
