<?php

/**
 * OpenRegister RBAC + tenant scoped object loader for the data-subject service.
 *
 * Every object a data-subject request reads or writes is loaded here, with the
 * `_rbac` / `_multitenancy` flags the caller asked for (on by default), so a
 * handler only ever reaches objects it is authorised to read. A load the
 * caller may not make answers null, never a partial object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Subject
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

namespace OCA\OpenRegister\Service\Gdpr\Subject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Scoped object loads for data-subject requests.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-consumable-rbac-tenant-scoped-data-subject-request-service
 */
class SubjectObjectLoader {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $objectMapper Object loader (RBAC/tenant scoped reads).
	 * @param LoggerInterface      $logger       Logger.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Load the owning object of a grouped index hit.
	 *
	 * The uuid is preferred over the numeric id. An entry naming neither
	 * loads nothing.
	 *
	 * @param array{object_id: int, object_uuid: string} $entry        Grouped entry.
	 * @param bool                                       $rbac         Apply RBAC.
	 * @param bool                                       $multitenancy Apply tenant scope.
	 *
	 * @return ObjectEntity|null Null when absent or not authorised.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-consumable-rbac-tenant-scoped-data-subject-request-service
	 */
	public function loadEntry(array $entry, bool $rbac, bool $multitenancy): ?ObjectEntity {
		$identifier = $this->identifierOf(entry: $entry);
		if ($identifier === 0) {
			return null;
		}

		try {
			return $this->objectMapper->find(
				$identifier,
				_rbac: $rbac,
				_multitenancy: $multitenancy
			);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[DSR] Object load skipped (not found or not authorised)',
				context: ['identifier' => $identifier, 'error' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end loadEntry()

	/**
	 * Load a single object by id/uuid, with RBAC and tenant scoping on.
	 *
	 * @param string $identifier Object id or uuid.
	 *
	 * @return ObjectEntity|null Null when absent or not authorised.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-consumable-rbac-tenant-scoped-data-subject-request-service
	 */
	public function loadByIdentifier(string $identifier): ?ObjectEntity {
		try {
			return $this->objectMapper->find($identifier, _rbac: true, _multitenancy: true);
		} catch (\Throwable $e) {
			return null;
		}

	}//end loadByIdentifier()

	/**
	 * The identifier to load an entry by: its uuid, else its numeric id.
	 *
	 * @param array{object_id: int, object_uuid: string} $entry Grouped entry.
	 *
	 * @return string|int The uuid, or the id (0 when the entry names no object).
	 */
	private function identifierOf(array $entry): string|int {
		$uuid = (string)$entry['object_uuid'];
		if ($uuid !== '') {
			return $uuid;
		}

		return (int)$entry['object_id'];

	}//end identifierOf()
}//end class
