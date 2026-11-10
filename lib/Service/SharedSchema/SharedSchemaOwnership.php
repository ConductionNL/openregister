<?php

/**
 * How a shared schema's owners are found and their copies created.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\SharedSchema
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

namespace OCA\OpenRegister\Service\SharedSchema;

use OCA\OpenRegister\Service\Configuration\ImportHandler;

/**
 * The import handler, register configuration locator and attribution rules the shared schema repair decides ownership with.
 *
 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
 */
final class SharedSchemaOwnership {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ImportHandler                $importHandler The configuration-driven schema create path.
	 * @param RegisterConfigurationLocator $locator       Reads a register's own app configuration.
	 * @param SchemaAttribution            $attribution   The pure detection and ownership rules.
	 *
	 * @spec openspec/changes/archive/2026-10-30-dedupe-shared-schemas/proposal.md
	 */
	public function __construct(
		public readonly ImportHandler $importHandler,
		public readonly RegisterConfigurationLocator $locator,
		public readonly SchemaAttribution $attribution,
	) {
	}//end __construct()
}//end class
