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
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * What a schema-scoped provider needs to resolve its register and schema slugs.
 *
 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
 */
final class SchemaScopeLookups {
	/**
	 * Register mapper for slug-to-id resolution.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper for slug-to-id resolution and the `smartPickerEnabled` flag.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Bundle the lookups.
	 *
	 * @param SchemaCatalogInterface $catalog The register and schema mappers.
	 * @param LoggerInterface        $logger  Logger.
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/specs/schema-scoped-reference-providers/spec.md
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		public readonly LoggerInterface $logger,
	) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper   = $catalog->schemas();
	}//end __construct()
}//end class
