<?php

/**
 * OpenRegister TranslationProviderResolver
 *
 * Chooses the translation provider bound behind TranslationProviderInterface
 * from two app settings: the Task Processing provider only when an
 * administrator chose it and confirmed that record text may be sent to it;
 * the identity provider otherwise (design D-6).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Translation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Translation;

use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the translation settings and answers the provider they select.
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */
class TranslationProviderResolver {

	/**
	 * The app the settings belong to.
	 *
	 * @var string
	 */
	public const APP = 'openregister';

	/**
	 * The setting naming the provider: `identity` (default) or `taskprocessing`.
	 *
	 * @var string
	 */
	public const PROVIDER_KEY = 'translation_provider';

	/**
	 * The setting confirming that record text may be sent to the provider; default false.
	 *
	 * @var string
	 */
	public const ALLOW_EXTERNAL_KEY = 'translation_allow_external';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig         $appConfig The app settings.
	 * @param ContainerInterface $container Builds the Task Processing provider only when it is selected.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the settings select the Task Processing provider: chosen and confirmed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function taskProcessingSelected(): bool {
		$provider = $this->appConfig->getValueString(self::APP, self::PROVIDER_KEY, IdentityTranslationProvider::IDENTIFIER);
		if ($provider !== TaskProcessingTranslationProvider::IDENTIFIER) {
			return false;
		}

		return $this->appConfig->getValueBool(self::APP, self::ALLOW_EXTERNAL_KEY, false);
	}//end taskProcessingSelected()

	/**
	 * The provider the settings select; the identity provider when nothing is
	 * selected or the Task Processing provider cannot be built.
	 *
	 * @return TranslationProviderInterface
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	public function resolve(): TranslationProviderInterface {
		if ($this->taskProcessingSelected() === false) {
			return new IdentityTranslationProvider();
		}

		try {
			return $this->container->get(TaskProcessingTranslationProvider::class);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[TranslationProviderResolver] The Task Processing provider could not be built; the identity provider is bound',
				['exception' => $failure->getMessage()]
			);
			return new IdentityTranslationProvider();
		}
	}//end resolve()
}//end class
