<?php

/**
 * SchemaFlowImportListenerInterface: what callers outside the Listener component use of SchemaFlowImportListener.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\Schema;

/**
 * Imports the flows a schema declares, as the configuration import runs it.
 *
 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-an-import-of-an-unchanged-schema-still-installs-what-it-declares
 */
interface SchemaFlowImportListenerInterface {

	/**
	 * Import the flows a schema declares under `x-openregister-flows`.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-an-import-of-an-unchanged-schema-still-installs-what-it-declares
	 */
	public function importFor(Schema $schema): void;
}//end interface
