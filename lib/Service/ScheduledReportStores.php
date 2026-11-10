<?php

/**
 * OpenRegister Scheduled report stores
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapperInterface;

/**
 * The stores a scheduled report reads and writes: the report rows themselves,
 * and the registers and schemas a report payload names.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ScheduledReportStores {
	/**
	 * Register lookup (create/update validation).
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema lookup (create/update validation).
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Bundle the collaborators.
	 *
	 * @param ScheduledReportMapperInterface $mapper  Scheduled report mapper.
	 * @param SchemaCatalogInterface         $catalog Register, schema and magic-table mappers.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly ScheduledReportMapperInterface $mapper,
		SchemaCatalogInterface $catalog,
	) {
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper   = $catalog->schemas();
	}//end __construct()
}//end class
