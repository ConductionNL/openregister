<?php

/**
 * OpenRegister register mappers
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
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;

/**
 * The register, schema, audit trail and object table mappers a register is read through.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterMappers {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapper   $registerMapper     The register mapper.
	 * @param SchemaMapper     $schemaMapper       The schema mapper.
	 * @param AuditTrailMapper $auditTrailMapper   The audit trail mapper.
	 * @param MagicMapper      $objectEntityMapper The object table mapper.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		public readonly RegisterMapper $registerMapper,
		public readonly SchemaMapper $schemaMapper,
		public readonly AuditTrailMapper $auditTrailMapper,
		public readonly MagicMapper $objectEntityMapper,
	) {
	}//end __construct()
}//end class
