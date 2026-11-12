<?php

/**
 * ProcessingPurposeMapperInterface: the part of ProcessingPurposeMapper that the processing purpose controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The methods of ProcessingPurposeMapper that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface ProcessingPurposeMapperInterface {

	/**
	 * Find by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return ProcessingPurpose The purpose.
	 *
	 * @throws DoesNotExistException When no row matches the id.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function find(int $id): ProcessingPurpose;

	/**
	 * List purposes, newest first, optionally filtered.
	 *
	 * @param string|null $status Optional lifecycle filter.
	 * @param string|null $organisationId Optional organisation filter.
	 *
	 * @return ProcessingPurpose[] The matching purposes.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function findAll(?string $status = null, ?string $organisationId = null): array;

	/**
	 * Find by the code a caller names on a request.
	 *
	 * @param string $code The purpose code.
	 *
	 * @return ProcessingPurpose|null Null when no row matches.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function findByCode(string $code): ?ProcessingPurpose;

	/**
	 * Insert a purpose, filling the uuid and the timestamps.
	 *
	 * @param ProcessingPurpose $entity The purpose to insert.
	 *
	 * @return ProcessingPurpose The persisted purpose.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID pattern.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function insert($entity): ProcessingPurpose;

	/**
	 * Update a purpose, moving its change time.
	 *
	 * @param ProcessingPurpose $entity The purpose to update.
	 *
	 * @return ProcessingPurpose The persisted purpose.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function update($entity): ProcessingPurpose;

	/**
	 * Resolve a reference that may be a code or a uuid.
	 *
	 * Code first, exactly as {@see VerwerkingsactiviteitMapper::resolveReference()},
	 * so an administrator may write either form wherever a purpose is named.
	 *
	 * @param string $reference The reference (code or uuid).
	 *
	 * @return ProcessingPurpose|null Null when nothing matches.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function resolveReference(string $reference): ?ProcessingPurpose;
}//end interface
