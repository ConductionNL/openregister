<?php

/**
 * OpenRegister archival mappers
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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;

/**
 * The mappers and repositories of the archival API.
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */
final class ArchivalMappers {
	/**
	 * Bundle the services.
	 *
	 * @param MagicMapper               $objectMapper Object mapper.
	 * @param DestructionListRepository $lists        Finds the destruction lists this instance holds.
	 * @param AuditTrailMapper          $auditMapper  Records who signed off what.
	 * @param SchemaMapper              $schemaMapper Loads the schema a nomination is derived from.
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function __construct(
		public readonly MagicMapper $objectMapper,
		public readonly DestructionListRepository $lists,
		public readonly AuditTrailMapper $auditMapper,
		public readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()
}//end class
