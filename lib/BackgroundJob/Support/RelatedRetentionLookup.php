<?php

/**
 * OpenRegister related retention lookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
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

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The mappers that find the records whose retention follows a changed source record.
 *
 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
 */
final class RelatedRetentionLookup {
	/**
	 * Reads and writes the records.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $objectMapper;

	/**
	 * Resolves the source record's schema.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Finds the registers a dependant schema lives in.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface $catalog The register, schema and magic-table mappers.
	 *
	 * @spec openspec/changes/archival-frozen-refuses-delete-and-dates-follow/specs/retention-management/spec.md#requirement-the-destruction-date-follows-its-source-date-under-every-method
	 */
	public function __construct(SchemaCatalogInterface $catalog) {
		$this->objectMapper = $catalog->tables();
		$this->schemaMapper = $catalog->schemas();
		$this->registerMapper = $catalog->registers();
	}//end __construct()
}//end class
