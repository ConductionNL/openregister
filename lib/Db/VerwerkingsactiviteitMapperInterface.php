<?php

/**
 * VerwerkingsactiviteitMapperInterface: what callers outside the Db component use of VerwerkingsactiviteitMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use InvalidArgumentException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The methods of VerwerkingsactiviteitMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to VerwerkingsactiviteitMapper. Guarded by C04InterfaceBindingsTest.
 */
interface VerwerkingsactiviteitMapperInterface {

	/**
	 * Find by primary key.
	 *
	 * @param int $id Primary key.
	 *
	 * @return Verwerkingsactiviteit
	 *
	 * @throws DoesNotExistException When no row matches the id.
	 */
	public function find(int $id): Verwerkingsactiviteit;

	/**
	 * List all activities, optionally filtered by tenant or status.
	 *
	 * @param string|null $organisationId Optional multi-tenant filter.
	 * @param string|null $status Optional status filter.
	 *
	 * @return Verwerkingsactiviteit[]
	 */
	public function findAll(?string $organisationId = null, ?string $status = null): array;

	/**
	 * Find by short readable code.
	 *
	 * @param string $code The activity code.
	 *
	 * @return Verwerkingsactiviteit|null Null when no row matches.
	 */
	public function findByCode(string $code): ?Verwerkingsactiviteit;

	/**
	 * Insert with vocabulary validation.
	 *
	 * Auto-fills `uuid`, `created`, `updated`, and `status` when blank.
	 *
	 * @param Verwerkingsactiviteit $entity Entity to insert.
	 *
	 * @return Verwerkingsactiviteit Persisted entity with id populated.
	 *
	 * @throws InvalidArgumentException When `legalBasis` is unset/invalid
	 *                                  or `name`/`purpose` are blank.
	 */
	public function insert($entity): Verwerkingsactiviteit;

	/**
	 * Resolve a `code|uuid` reference to a concrete entity.
	 *
	 * Tries `code` first, falls back to `uuid`. Used by the audit-trail
	 * trigger contract to honour `x-openregister-processing-activity`
	 * annotations regardless of which form operators chose to write.
	 *
	 * @param string $reference The reference string (code or uuid).
	 *
	 * @return Verwerkingsactiviteit|null
	 */
	public function resolveReference(string $reference): ?Verwerkingsactiviteit;

	/**
	 * Update with vocabulary validation. Bumps `updated`.
	 *
	 * @param Verwerkingsactiviteit $entity Entity to update.
	 *
	 * @return Verwerkingsactiviteit
	 */
	public function update($entity): Verwerkingsactiviteit;
}//end interface
