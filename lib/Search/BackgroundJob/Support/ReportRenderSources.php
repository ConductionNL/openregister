<?php

/**
 * OpenRegister report render sources
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Search\BackgroundJob\Support
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

namespace OCA\OpenRegister\Search\BackgroundJob\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers that find the dashboards a scheduled render pass considers.
 *
 * @spec openspec/specs/rapportage-bi-export/spec.md
 */
final class ReportRenderSources {
	/**
	 * Register lookup.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema lookup for the register's schemas.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Object loader.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $objectMapper;

	/**
	 * Take the mappers from the schema catalog.
	 *
	 * @param SchemaCatalogInterface $catalog The register, schema and magic-table mappers.
	 *
	 * @spec openspec/specs/rapportage-bi-export/spec.md
	 */
	public function __construct(SchemaCatalogInterface $catalog) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper = $catalog->schemas();
		$this->objectMapper = $catalog->tables();
	}//end __construct()
}//end class
