<?php

/**
 * OpenRegister entity recognition backends
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\TextExtraction;

use OCA\OpenRegister\Service\Anonymisation\AnonymiserRequestInterface;
use OCA\OpenRegister\Service\Anonymisation\TextDisclosureRecorder;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCP\Http\Client\IClientService;
use Psr\Container\ContainerInterface;

/**
 * The detection backends and settings of the entity recognition handler.
 *
 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
 */
final class EntityRecognitionBackends {
	/**
	 * Bundle the backends.
	 *
	 * @param SettingsServiceInterface    $settingsService             Settings service.
	 * @param AnonymiserRequestInterface $anonymisationBackendService Backend state + ExApp client.
	 * @param ContainerInterface|null     $container                   App container, consulted lazily for the object mapper.
	 * @param IClientService|null         $clientService               Nextcloud HTTP client for the analyze calls; without one no call is made.
	 * @param TextDisclosureRecorder|null $disclosureRecorder          Records each call carrying a document text; none: no external call.
	 *
	 * @spec openspec/specs/text-extraction/spec.md#requirement-file-and-object-chunk-extraction-lifecycle
	 */
	public function __construct(
		public readonly SettingsServiceInterface $settingsService,
		public readonly AnonymiserRequestInterface $anonymisationBackendService,
		public readonly ?ContainerInterface $container = null,
		public readonly ?IClientService $clientService = null,
		public readonly ?TextDisclosureRecorder $disclosureRecorder = null,
	) {
	}//end __construct()
}//end class
