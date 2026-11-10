<?php

/**
 * An imported schema definition and the scope its stored schema is looked up in.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

/**
 * A schema definition to import and where its existing schema is looked up:
 * the target registers' schema ids and the importing app's options.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ImportedSchemaLookup {

	/**
	 * Constructor.
	 *
	 * @param array               $data              The schema data (MUST carry a non-empty `slug`).
	 * @param array|null          $registerSchemaIds Pre-import schema ids of the target register(s), or null for none known.
	 * @param SchemaImportOptions $options           The importing owner, app id, version and force flag.
	 */
	public function __construct(
		public readonly array $data,
		public readonly ?array $registerSchemaIds = null,
		public readonly SchemaImportOptions $options = new SchemaImportOptions(),
	) {
	}//end __construct()
}//end class
