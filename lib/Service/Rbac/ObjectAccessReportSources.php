<?php

/**
 * The stores the per-object access report reads from.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register, schema and trail lookups of ObjectAccessReport.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ObjectAccessReportSources {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface   $registerMapper   Register lookup.
	 * @param SchemaMapperInterface     $schemaMapper     Schema lookup.
	 * @param AuditTrailMapperInterface $auditTrailMapper The trail the history is read from.
	 */
	public function __construct(
		public readonly RegisterMapperInterface $registerMapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
	}//end __construct()
}//end class
