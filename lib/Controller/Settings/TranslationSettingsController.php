<?php

/**
 * TranslationSettingsController: the "Translation" section of the admin settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Translation\IdentityTranslationProvider;
use OCA\OpenRegister\Service\Translation\TaskProcessingTranslationProvider;
use OCA\OpenRegister\Service\Translation\TranslationProviderResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\TaskTypes\TextToText;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use Throwable;

/**
 * Reads and saves the translation provider choice and the confirmation that
 * record text may be sent, and names the Task Processing providers Nextcloud
 * reports for the two task types the provider runs.
 *
 * Administrator only, by carrying no `NoAdminRequired`: the choice decides
 * whether record text leaves the server.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
 */
class TranslationSettingsController extends Controller {

	/**
	 * The task types the Task Processing translation provider runs.
	 *
	 * @var list<string>
	 */
	private const TASK_TYPES = [TextToTextTranslate::ID, TextToText::ID];

	/**
	 * Apps whose Task Processing providers run on this Nextcloud server. Any
	 * other provider is reported as remote: when in doubt, the section says the
	 * text leaves the server.
	 *
	 * @var list<string>
	 */
	private const LOCAL_PROVIDER_APPS = ['llm2', 'translate2', 'testing'];

	/**
	 * Build the controller.
	 *
	 * @param IRequest   $request        The request.
	 * @param IAppConfig $appConfig      The app settings.
	 * @param IManager   $taskProcessing Nextcloud's Task Processing manager.
	 */
	public function __construct(
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly IManager $taskProcessing,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The stored choice and the providers Nextcloud reports.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
	 */
	public function show(): JSONResponse {
		return new JSONResponse(data: $this->settings());
	}//end show()

	/**
	 * Save the choice. Record text may be sent only when Task Processing is
	 * chosen and the administrator confirmed it; anything else stores false.
	 *
	 * @param string $provider      `identity` or `taskprocessing`.
	 * @param bool   $allowExternal The confirmation that record text is sent to the provider.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs/register-i18n/spec.md#requirement-ai-translation-runs-through-nextclouds-task-processing-when-an-administrator-enables-it
	 */
	public function update(string $provider=IdentityTranslationProvider::IDENTIFIER, bool $allowExternal=false): JSONResponse {
		$known = [IdentityTranslationProvider::IDENTIFIER, TaskProcessingTranslationProvider::IDENTIFIER];
		if (in_array($provider, $known, true) === false) {
			return new JSONResponse(
				data: ['error' => 'Unknown translation provider: ' . $provider],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$allowed = ($provider === TaskProcessingTranslationProvider::IDENTIFIER && $allowExternal === true);
		$this->appConfig->setValueString(TranslationProviderResolver::APP, TranslationProviderResolver::PROVIDER_KEY, $provider);
		$this->appConfig->setValueBool(TranslationProviderResolver::APP, TranslationProviderResolver::ALLOW_EXTERNAL_KEY, $allowed);

		return new JSONResponse(data: $this->settings());
	}//end update()

	/**
	 * The stored choice and the provider per task type.
	 *
	 * @return array{provider: string, allowExternal: bool, providers: list<array{taskType: string, id: string, name: string, local: bool}>}
	 */
	private function settings(): array {
		return [
			'provider'      => $this->appConfig->getValueString(
				TranslationProviderResolver::APP,
				TranslationProviderResolver::PROVIDER_KEY,
				IdentityTranslationProvider::IDENTIFIER
			),
			'allowExternal' => $this->appConfig->getValueBool(
				TranslationProviderResolver::APP,
				TranslationProviderResolver::ALLOW_EXTERNAL_KEY,
				false
			),
			'providers'     => $this->providers(),
		];
	}//end settings()

	/**
	 * The provider Nextcloud prefers for each task type, and whether it runs on
	 * this server; a type without a provider is left out.
	 *
	 * @return list<array{taskType: string, id: string, name: string, local: bool}>
	 */
	private function providers(): array {
		$providers = [];
		foreach (self::TASK_TYPES as $taskType) {
			try {
				$provider = $this->taskProcessing->getPreferredProvider($taskType);
			} catch (Throwable $missing) {
				continue;
			}

			$id          = $provider->getId();
			$app         = explode(':', $id, 2)[0];
			$providers[] = [
				'taskType' => $taskType,
				'id'       => $id,
				'name'     => $provider->getName(),
				'local'    => in_array($app, self::LOCAL_PROVIDER_APPS, true),
			];
		}

		return $providers;
	}//end providers()
}//end class
