<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\ObjectReadAccessInterface;
use OCP\AppFramework\Http\JSONResponse;

/**
 * An object read check under which nothing is readable.
 */
final class UnreadableObjects implements ObjectReadAccessInterface {
	/**
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 * @param string $id       The object.
	 *
	 * @return ObjectEntity|null
	 */
	public function readable(string $register, string $schema, string $id): ?ObjectEntity {
		return null;
	}//end readable()

	/**
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null
	 */
	public function schemaOf(ObjectEntity $object): ?Schema {
		return null;
	}//end schemaOf()

	/**
	 * @param string|integer|null $schemaId The schema.
	 *
	 * @return Schema|null
	 */
	public function schemaById(string|int|null $schemaId): ?Schema {
		return null;
	}//end schemaById()

	/**
	 * @param ObjectEntity $object The object.
	 *
	 * @return integer|null
	 */
	public function registerIdOf(ObjectEntity $object): ?int {
		return null;
	}//end registerIdOf()

	/**
	 * @return JSONResponse
	 */
	public function notReadable(): JSONResponse {
		return new JSONResponse(['error' => 'Not found'], 404);
	}//end notReadable()
}//end class
