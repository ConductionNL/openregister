<?php

/**
 * OpenRegister BundleManageGuard
 *
 * Refuses a shared bundle that would overwrite a register or schema the person
 * installing it may not manage. An install writes as the system, so the manage
 * right has to be judged here, against the person who asked for the install.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Rbac\SchemaManageGate;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Judges the registers and schemas a bundle would overwrite, for the signed-in user.
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */
class BundleManageGuard {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper   $registerMapper Finds an existing register by slug.
	 * @param SchemaMapper     $schemaMapper   Finds existing schemas by slug.
	 * @param SchemaManageGate $manageGate     Who may manage a register or schema.
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly SchemaManageGate $manageGate,
	) {
	}//end __construct()

	/**
	 * Refuse the bundle when it names an existing register or schema the caller may not manage.
	 *
	 * New registers and schemas are not judged here: who may install at all is
	 * FederatedConfigAccess::canInstall(). Administrators always pass.
	 *
	 * @param array<string, mixed> $bundle The bundle.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the bundle would overwrite a definition the caller may not manage.
	 *
	 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
	 */
	public function assertAllowed(array $bundle): void {
		if ($this->manageGate->isAdministrator() === true) {
			return;
		}

		foreach ($this->slugs(documents: ($bundle['components']['registers'] ?? [])) as $slug) {
			if ($this->mayOverwriteRegister(slug: $slug) === false) {
				throw new NotAuthorizedException(message: 'This bundle would change a register you may not manage');
			}
		}

		foreach ($this->slugs(documents: ($bundle['components']['schemas'] ?? [])) as $slug) {
			if ($this->mayOverwriteSchema(slug: $slug) === false) {
				throw new NotAuthorizedException(message: 'This bundle would change a schema you may not manage');
			}
		}
	}//end assertAllowed()

	/**
	 * Whether the caller may overwrite the register with this slug (true when there is none).
	 *
	 * @param string $slug The register slug.
	 *
	 * @return bool False when it exists and the caller may not manage it, or the lookup fails.
	 */
	private function mayOverwriteRegister(string $slug): bool {
		try {
			// The lookup sees every register the system write could reach; the manage gate judges it.
			$register = $this->registerMapper->find(
				id: $slug,
				// phpcs:ignore Squiz.Commenting.InlineComment.NotCapital -- ADR-055 marker.
				_rbac: false, // rbac-bypass: judged by the manage gate below.
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return true;
		} catch (Throwable $e) {
			return false;
		}

		return $this->manageGate->mayManageRegister(register: $register);
	}//end mayOverwriteRegister()

	/**
	 * Whether the caller may overwrite every schema with this slug (true when there is none).
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return bool False when one exists that the caller may not manage, or the lookup fails.
	 */
	private function mayOverwriteSchema(string $slug): bool {
		try {
			// The lookup sees every schema the system write could reach; the manage gate judges it.
			$schemas = $this->schemaMapper->findBySlug(
				slug: $slug,
				limit: 100,
				offset: 0,
				// phpcs:ignore Squiz.Commenting.InlineComment.NotCapital -- ADR-055 marker.
				_rbac: false, // rbac-bypass: judged by the manage gate below.
				_multitenancy: false
			);
		} catch (Throwable $e) {
			return false;
		}

		foreach ($schemas as $schema) {
			if ($this->manageGate->mayManage(schema: $schema) === false) {
				return false;
			}
		}

		return true;
	}//end mayOverwriteSchema()

	/**
	 * The slugs of a bundle's register or schema documents.
	 *
	 * @param mixed $documents The documents, keyed by slug or carrying a `slug`.
	 *
	 * @return list<string> The slugs.
	 */
	private function slugs(mixed $documents): array {
		if (is_array($documents) === false) {
			return [];
		}

		$slugs = [];
		foreach ($documents as $key => $document) {
			$slug = '';
			if (is_array($document) === true && is_scalar($document['slug'] ?? null) === true) {
				$slug = (string)$document['slug'];
			} else if (is_string($key) === true) {
				$slug = $key;
			}

			if ($slug !== '') {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}//end slugs()
}//end class
