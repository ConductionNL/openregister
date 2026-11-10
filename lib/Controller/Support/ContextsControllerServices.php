<?php

/**
 * OpenRegister ContextsController services
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

use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextServiceInterface;

/**
 * The collaborators of ContextsController, wired by DI autowiring.
 *
 * @spec openspec/specs/json-ld-output/spec.md
 */
final class ContextsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface        $catalog        The register and schema mappers.
	 * @param JsonLdContextServiceInterface $contextService The JSON-LD context service.
	 *
	 * @spec openspec/specs/json-ld-output/spec.md
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly JsonLdContextServiceInterface $contextService,
	) {
	}//end __construct()
}//end class
