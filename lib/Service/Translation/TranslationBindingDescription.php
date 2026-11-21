<?php

/**
 * TranslationBindingDescription: says which translation provider is bound, for the connection report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Translation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/app-connections/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Translation;

use OCA\OpenRegister\Kernel\Contracts\Integrations\TranslationBindingInterface;
use Psr\Container\ContainerInterface;

/**
 * Resolves TranslationProviderInterface when asked, never at construction, so
 * a broken binding fails inside the report that describes it.
 *
 * @spec openspec/specs/app-connections/spec.md
 */
final class TranslationBindingDescription implements TranslationBindingInterface {

	/**
	 * The identifier IdentityTranslationProvider reports.
	 *
	 * @var string
	 */
	private const IDENTITY_TRANSLATION = 'identity';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the bound translation provider.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * What answers behind the translation seam.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/specs/app-connections/spec.md
	 */
	public function describe(): array {
		/*
		 * @var TranslationProviderInterface $provider
		 */

		$provider = $this->container->get(TranslationProviderInterface::class);
		$identifier = $provider->getIdentifier();

		if ($identifier === self::IDENTITY_TRANSLATION) {
			return [
				'simulated',
				'The identity provider is bound. A translation returns the source text unchanged. Bind a real TranslationProviderInterface to translate.',
			];
		}

		if ($provider instanceof TaskProcessingTranslationProvider) {
			return ['configured', $this->describeTaskProcessing(names: $provider->providerNames())];
		}

		return ['configured', 'Translation provider "' . $identifier . '" is bound. Not tested.'];
	}//end describe()

	/**
	 * The message for the Task Processing provider: the Nextcloud providers it sends text to.
	 *
	 * @param array<string, string> $names The provider name per task type.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ai-translation-with-a-glossary/specs
	 */
	private function describeTaskProcessing(array $names): string {
		if ($names === []) {
			return 'Translation runs through Nextcloud Task Processing, which reports no provider for translation. Not tested.';
		}

		$parts = [];
		foreach ($names as $taskTypeId => $name) {
			$parts[] = $name . ' (' . $taskTypeId . ')';
		}

		return 'Translation runs through Nextcloud Task Processing: ' . implode(', ', $parts) . '. Not tested.';
	}//end describeTaskProcessing()
}//end class
