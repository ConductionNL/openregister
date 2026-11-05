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

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register, schema, audit trail and object table mappers a register is read through.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterMappers {
	/**
	 * Bundle the services.
	 *
	 * @param RegisterMapperInterface   $registerMapper     The register mapper.
	 * @param SchemaMapperInterface     $schemaMapper       The schema mapper.
	 * @param AuditTrailMapperInterface $auditTrailMapper   The audit trail mapper.
	 * @param MagicMapperInterface      $objectEntityMapper The object table mapper.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
		public readonly MagicMapperInterface $objectEntityMapper,
	) {
	}//end __construct()
}//end class
