<?php

/**
 * OpenRegister linked data services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdSerializerInterface;
use OCP\IURLGenerator;

/**
 * JSON-LD serialization and relation URL resolution; each is null when absent.
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
final class LinkedDataServices {
	/**
	 * Bundle the services.
	 *
	 * @param ?JsonLdSerializerInterface     $jsonLdSerializer     The JSON-LD serializer.
	 * @param ?JsonLdContextServiceInterface $jsonLdContextService The JSON-LD context service.
	 * @param ?DeepLinkRegistryServiceInterface       $deepLinkRegistry     The relation resourceUrl resolver.
	 * @param ?IURLGenerator                 $relationUrlGenerator The relation fallback URL generator.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function __construct(
		public readonly ?JsonLdSerializerInterface $jsonLdSerializer = null,
		public readonly ?JsonLdContextServiceInterface $jsonLdContextService = null,
		public readonly ?DeepLinkRegistryServiceInterface $deepLinkRegistry = null,
		public readonly ?IURLGenerator $relationUrlGenerator = null,
	) {
	}//end __construct()
}//end class
