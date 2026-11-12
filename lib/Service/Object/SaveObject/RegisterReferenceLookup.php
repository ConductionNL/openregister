<?php

/**
 * OpenRegister RegisterReferenceLookup
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Finds the register a reference names.
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */
final class RegisterReferenceLookup {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registerMapper Finds registers.
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()

	/**
	 * The id of the register a reference names, or null.
	 *
	 * @param string $reference An id, a uuid, a slug or a URL ending in a slug.
	 *
	 * @return null|numeric-string The register id.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function find(string $reference): ?string {
		if (empty($reference) === true) {
			return null;
		}

		$slug = (new ReferenceText())->lastSegment(reference: $reference);

		return ($this->registerIdByIdentifier(identifier: $reference)
			?? $this->registerIdFromAll(slug: $slug)
			?? $this->registerIdBySlug(slug: $slug));
	}//end find()

	/**
	 * The id of the register an id or uuid names; null for anything else or when it is not found.
	 *
	 * @param string $identifier The id or uuid.
	 *
	 * @return string|null The register id.
	 */
	private function registerIdByIdentifier(string $identifier): ?string {
		if ((new ReferenceText())->isIdentifier(value: $identifier) === false) {
			return null;
		}

		try {
			return (string)$this->registerMapper->find($identifier)->getId();
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end registerIdByIdentifier()

	/**
	 * The id of the register with this slug.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string|null The register id; null when none matches or the read fails.
	 */
	private function registerIdFromAll(string $slug): ?string {
		try {
			foreach ($this->registerMapper->findAll() as $register) {
				if (strcasecmp($register->getSlug(), $slug) === 0) {
					return (string)$register->getId();
				}
			}
		} catch (Exception $e) {
			return null;
		}

		return null;
	}//end registerIdFromAll()

	/**
	 * The id of the register the mapper finds under this slug, outside the caller's scope.
	 *
	 * @param string $slug The slug.
	 *
	 * @return string|null The register id.
	 */
	private function registerIdBySlug(string $slug): ?string {
		try {
			return (string)$this->registerMapper->find(id: $slug, _rbac: false, _multitenancy: false)->getId();
		} catch (Exception $e) {
			return null;
		}
	}//end registerIdBySlug()
}//end class
