<?php

/**
 * OpenRegister schema scope lookups
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * What a schema-scoped provider needs to resolve its register and schema slugs.
 *
 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
 */
final class SchemaScopeLookups {
	/**
	 * Bundle the lookups.
	 *
	 * @param RegisterMapperInterface $registerMapper Register mapper for slug-to-id resolution.
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper for slug-to-id resolution and the `smartPickerEnabled` flag.
	 * @param LoggerInterface         $logger         Logger.
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
