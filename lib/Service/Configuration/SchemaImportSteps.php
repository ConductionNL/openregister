<?php

/**
 * OpenRegister SchemaImportSteps
 *
 * The optional services an imported schema passes through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCA\OpenRegister\Service\ShippedBaseline\ShippedConfigurationGuard;

/**
 * The shape check, the shipped-schema guard and the flow and webhook install of an imported
 * schema. Each is optional: without it the import behaves as it did before that step existed.
 *
 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
 */
final class SchemaImportSteps {

	/**
	 * Constructor.
	 *
	 * @param OasRequestValidator|null       $shapeValidator Validates a schema's shape at import time.
	 * @param ShippedConfigurationGuard|null $shippedGuard   Keeps local changes to an app-shipped schema.
	 * @param SchemaImportInstaller|null     $installer      Installs a schema's flows and webhooks when an
	 *                                                       import fired no SchemaUpdatedEvent.
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public function __construct(
		public readonly ?OasRequestValidator $shapeValidator = null,
		public readonly ?ShippedConfigurationGuard $shippedGuard = null,
		public readonly ?SchemaImportInstaller $installer = null,
	) {
	}//end __construct()
}//end class
