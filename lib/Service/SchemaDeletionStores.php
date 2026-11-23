<?php

/**
 * The object, register, schema and audit trail mappers a schema-wide delete goes through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * The object, register, schema and audit trail mappers a schema-wide delete goes through.
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class SchemaDeletionStores {
	/**
	 * Object mapper.
	 *
	 * @var MagicMapperInterface
	 */
	public readonly MagicMapperInterface $magicMapper;

	/**
	 * Register mapper.
	 *
	 * @var RegisterMapperInterface
	 */
	public readonly RegisterMapperInterface $registerMapper;

	/**
	 * Schema mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	public readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Bundle the collaborators.
	 *
	 * @param SchemaCatalogInterface    $catalog          Register, schema and magic-table mappers.
	 * @param AuditTrailMapperInterface $auditTrailMapper Audit trail mapper.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function __construct(
		SchemaCatalogInterface $catalog,
		public readonly AuditTrailMapperInterface $auditTrailMapper,
	) {
		$this->magicMapper    = $catalog->tables();
		$this->registerMapper = $catalog->registers();
		$this->schemaMapper   = $catalog->schemas();
	}//end __construct()
}//end class
