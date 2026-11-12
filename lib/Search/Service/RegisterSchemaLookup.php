<?php

/**
 * Resolves the registers and schemas a size calculation names.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service;

use Exception;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Fetches registers and schemas by id, with the checks a size calculation needs.
 */
class RegisterSchemaLookup {
	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registerMapper Register mapper.
	 * @param SchemaMapperInterface   $schemaMapper   Schema mapper.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * The entities behind one magic table, read without access narrowing.
	 *
	 * @param int $registerId The register of the pair.
	 * @param int $schemaId   The schema of the pair.
	 *
	 * @return RegisterSchemaPair
	 *
	 * @throws Exception When either row is gone.
	 */
	public function pair(int $registerId, int $schemaId): RegisterSchemaPair {
		return new RegisterSchemaPair(
			register: $this->registerMapper->find($registerId, _rbac: false, _multitenancy: false),
			schema: $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false)
		);
	}//end pair()

	/**
	 * Fetch register by ID with validation
	 *
	 * @param int|null $registerId Register ID to fetch.
	 *
	 * @return Register|null Register entity or null if not provided.
	 *
	 * @throws \Exception If register is not found.
	 */
	public function fetchRegister(?int $registerId): ?Register {
		if ($registerId === null) {
			return null;
		}

		try {
			return $this->registerMapper->find($registerId);
		} catch (Exception $e) {
			throw new Exception('Register not found: ' . $e->getMessage());
		}
	}//end fetchRegister()

	/**
	 * Fetch schema by ID with validation, when one was named
	 *
	 * @param int|null $schemaId Schema ID to fetch.
	 * @param Register|null $register Register to validate against.
	 *
	 * @return Schema|null Schema entity or null if not provided.
	 *
	 * @throws \Exception If schema is not found or doesn't belong to register.
	 */
	public function fetchOptionalSchema(?int $schemaId, ?Register $register): ?Schema {
		if ($schemaId === null) {
			return null;
		}

		return $this->fetchSchema(schemaId: $schemaId, register: $register);
	}//end fetchOptionalSchema()

	/**
	 * Fetch schema by ID with validation
	 *
	 * @param int $schemaId Schema ID to fetch.
	 * @param Register|null $register Register to validate against.
	 *
	 * @return Schema Schema entity.
	 *
	 * @throws \Exception If schema is not found or doesn't belong to register.
	 */
	public function fetchSchema(int $schemaId, ?Register $register): Schema {
		try {
			$schema = $this->schemaMapper->find($schemaId);

			// Verify schema belongs to register if both are provided.
			if ($register !== null && in_array($schema->getId(), $register->getSchemas()) === false) {
				throw new Exception('Schema does not belong to the specified register');
			}

			return $schema;
		} catch (Exception $e) {
			throw new Exception('Schema not found or invalid: ' . $e->getMessage());
		}
	}//end fetchSchema()
}//end class
