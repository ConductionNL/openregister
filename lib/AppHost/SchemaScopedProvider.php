<?php

/**
 * What the schema-scoped search and reference providers share: their register and schema ids, title and flag.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppHost
 * @package  OCA\OpenRegister\AppHost
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

namespace OCA\OpenRegister\AppHost;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\Schemas\SchemaRefInRegister;

/**
 * Resolves a provider's slugs once per instance and reads its schema live.
 *
 * The using class declares `getRegisterSlug()`, `getSchemaSlug()`, its `registerMapper`,
 * `schemaMapper` and `logger`, and `LOG_NAME`, the name its debug lines carry.
 *
 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d3
 */
trait SchemaScopedProvider {

	/**
	 * Memoized resolved register database ID. `false` means resolution was
	 * attempted and failed (distinct from `null`, meaning not yet attempted).
	 *
	 * @var int|false|null
	 */
	private int|false|null $registerId = null;

	/**
	 * Memoized register entity, read once and shared by the id and the schema
	 * lookup. `false` means the read failed; `null` that it was not tried.
	 *
	 * @var Register|false|null
	 */
	private Register|false|null $registerEntity = null;

	/**
	 * Memoized resolved schema database ID. `false` means resolution was
	 * attempted and failed (distinct from `null`, meaning not yet attempted).
	 *
	 * @var int|false|null
	 */
	private int|false|null $schemaId = null;

	/**
	 * Whether this provider's configured schema has opted in to its own
	 * Smart Picker entry's functionality. `false` for an unresolvable
	 * schema slug, matching the fail-closed default.
	 *
	 * @return bool True when the schema's `smartPickerEnabled` flag is set
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
	 */
	protected function isSmartPickerEnabled(): bool {
		$schemaId = $this->resolveSchemaId();
		if ($schemaId === false) {
			return false;
		}

		try {
			return $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)->isSmartPickerEnabled();
		} catch (\Exception $e) {
			return false;
		}
	}//end isSmartPickerEnabled()

	/**
	 * The schema's title, read live so an edit shows up immediately; its slug when it has none or cannot be read.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/schema-scoped-reference-providers/spec.md
	 */
	private function schemaTitle(): string {
		$schemaId = $this->resolveSchemaId();
		if ($schemaId === false) {
			return $this->getSchemaSlug();
		}

		try {
			$title = $this->schemaMapper->find($schemaId, _multitenancy: false, _rbac: false)->getTitle();
			if ($title !== null && $title !== '') {
				return $title;
			}
		} catch (\Exception $e) {
			return $this->getSchemaSlug();
		}

		return $this->getSchemaSlug();
	}//end schemaTitle()

	/**
	 * Lazily resolve {@see self::getRegisterSlug()} to its database ID,
	 * memoized per-instance for the lifetime of the request.
	 *
	 * @return int|false The resolved register ID, or `false` when the slug
	 *                    could not be resolved
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d3
	 */
	private function resolveRegisterId(): int|false {
		if ($this->registerId === null) {
			$register = $this->resolveRegister();
			$this->registerId = false;
			if ($register !== false) {
				$this->registerId = (int)$register->getId();
			}
		}

		return $this->registerId;
	}//end resolveRegisterId()

	/**
	 * Lazily read the register {@see self::getRegisterSlug()} names, once per instance.
	 *
	 * @return Register|false The register, or `false` when it could not be read
	 *
	 * @spec openspec/changes/register-scoped-schema-slug-resolution/specs/schema-slug-resolution/spec.md
	 */
	private function resolveRegister(): Register|false {
		if ($this->registerEntity === null) {
			try {
				$this->registerEntity = $this->registerMapper->find($this->getRegisterSlug(), _rbac: false, _multitenancy: false);
			} catch (\Exception $e) {
				$this->logger->debug(
					'[' . self::LOG_NAME . '] Failed to resolve register slug "{slug}": {error}',
					['slug' => $this->getRegisterSlug(), 'error' => $e->getMessage()]
				);
				$this->registerEntity = false;
			}
		}

		return $this->registerEntity;
	}//end resolveRegister()

	/**
	 * Lazily resolve {@see self::getSchemaSlug()} to its database ID,
	 * memoized per-instance for the lifetime of the request.
	 *
	 * @return int|false The resolved schema ID, or `false` when the slug
	 *                    could not be resolved
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d3
	 */
	private function resolveSchemaId(): int|false {
		if ($this->schemaId === null) {
			try {
				// Inside the provider's own register first: the slug is unique
				// there, not across the instance. An unreadable register leaves
				// the global lookup, as before.
				$register = $this->resolveRegister();
				if ($register === false) {
					$register = null;
				}

				$this->schemaId = (int)(new SchemaRefInRegister(schemas: $this->schemaMapper))
					->find(schemaRef: $this->getSchemaSlug(), register: $register, _rbac: false, _multitenancy: false)
					->getId();
			} catch (\Exception $e) {
				$this->logger->debug(
					'[' . self::LOG_NAME . '] Failed to resolve schema slug "{slug}": {error}',
					['slug' => $this->getSchemaSlug(), 'error' => $e->getMessage()]
				);
				$this->schemaId = false;
			}
		}

		return $this->schemaId;
	}//end resolveSchemaId()
}//end trait
