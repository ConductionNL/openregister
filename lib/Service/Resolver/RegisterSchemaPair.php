<?php

/**
 * OpenRegister RegisterSchemaPair value object
 *
 * Read-only value object returned by RegisterResolverService::resolvePair().
 * Bundles a register entity plus a schema entity plus the two raw
 * slug/UUID strings the resolver started from, so consumers don't have
 * to round-trip back to the mapper just to obtain the canonical id.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Resolver
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resolver;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Readonly value object pairing a resolved register and schema.
 */
final class RegisterSchemaPair {
	/**
	 * Constructor; capture every field as readonly.
	 *
	 * @param string $registerId The raw register slug/UUID string from app config.
	 * @param string $schemaId The raw schema slug/UUID string from app config.
	 * @param Register $register The hydrated Register entity.
	 * @param Schema $schema The hydrated Schema entity.
	 */
	public function __construct(
		private readonly string $registerId,
		private readonly string $schemaId,
		private readonly Register $register,
		private readonly Schema $schema,
	) {

	}//end __construct()

	/**
	 * Get the raw register slug/UUID the resolver started from.
	 *
	 * @return string The register identifier.
	 */
	public function getRegisterId(): string {
		return $this->registerId;
	}//end getRegisterId()

	/**
	 * Get the raw schema slug/UUID the resolver started from.
	 *
	 * @return string The schema identifier.
	 */
	public function getSchemaId(): string {
		return $this->schemaId;
	}//end getSchemaId()

	/**
	 * Get the hydrated Register entity.
	 *
	 * @return Register The Register entity.
	 */
	public function getRegister(): Register {
		return $this->register;
	}//end getRegister()

	/**
	 * Get the hydrated Schema entity.
	 *
	 * @return Schema The Schema entity.
	 */
	public function getSchema(): Schema {
		return $this->schema;
	}//end getSchema()
}//end class
