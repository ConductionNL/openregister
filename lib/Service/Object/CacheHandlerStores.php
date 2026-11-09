<?php

/**
 * The stores CacheHandler reads names and objects from
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\IDBConnection;

/**
 * The stores CacheHandler reads names and objects from: the organisation
 * mapper, and the register/schema mappers plus connection for magic tables.
 */
final class CacheHandlerStores {

	/**
	 * @param OrganisationMapperInterface  $organisationMapper The organisation entity mapper
	 * @param RegisterMapperInterface|null $registerMapper     Register mapper for magic table queries
	 * @param SchemaMapperInterface|null   $schemaMapper       Schema mapper for magic table queries
	 * @param IDBConnection|null           $db                 Database connection for magic table queries
	 */
	public function __construct(
		public readonly OrganisationMapperInterface $organisationMapper,
		public readonly ?RegisterMapperInterface $registerMapper = null,
		public readonly ?SchemaMapperInterface $schemaMapper = null,
		public readonly ?IDBConnection $db = null,
	) {
	}//end __construct()
}//end class
