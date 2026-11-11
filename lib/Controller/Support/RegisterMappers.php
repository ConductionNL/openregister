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
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The register, schema, audit trail and object table mappers a register is read through.
 *
 * @spec openspec/specs/register-service-extensions/spec.md
 */
final class RegisterMappers {

	/**
	 * The register mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * The schema mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * The object table mapper.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $objectEntityMapper;

	/**
	 * Bundle the services.
	 *
	 * @param SchemaCatalogInterface    $catalog          The register, schema and object table mappers.
	 * @param AuditTrailMapperInterface $auditTrailMapper The audit trail mapper.
	 *
	 * @spec openspec/specs/register-service-extensions/spec.md
	 */
	public function __construct(
		public readonly SchemaCatalogInterface $catalog,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
		$this->registerMapper     = $catalog->registers();
		$this->schemaMapper       = $catalog->schemas();
		$this->objectEntityMapper = $catalog->tables();
	}//end __construct()
}//end class
