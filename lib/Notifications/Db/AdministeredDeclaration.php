<?php

/**
 * AdministeredDeclaration: the columns every administered timeline declaration carries.
 *
 * A reference pattern, a text block and a timeline kind are each administered
 * under a uuid, a slug and a title, scoped to an optional register and schema,
 * and stamped with when they were declared and last changed. Those columns,
 * their types and their API spelling live here once.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * The shared columns of ReferencePattern, TextBlock and TimelineKind.
 *
 * Each subclass registers the column types in its own column order by calling
 * addNameTypes(), addScopeTypes() and addTimestampTypes() around its own
 * columns, and spells its API shape the same way with nameFields(),
 * scopeFields() and timestampFields().
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
abstract class AdministeredDeclaration extends Entity implements JsonSerializable {

	/**
	 * The stable id of the declaration.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The name the declaration is administered under.
	 *
	 * @var string|null
	 */
	protected ?string $slug = null;

	/**
	 * The label a reader sees.
	 *
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * The register the declaration is scoped to, or null for every register.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The schema the declaration is scoped to, or null for every schema.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * When the declaration was made.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When the declaration last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Register the uuid, slug and title column types.
	 *
	 * @return void
	 */
	protected function addNameTypes(): void {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'slug', type: 'string');
		$this->addType(fieldName: 'title', type: 'string');
	}//end addNameTypes()

	/**
	 * Register the register and schema column types.
	 *
	 * @return void
	 */
	protected function addScopeTypes(): void {
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
	}//end addScopeTypes()

	/**
	 * Register the created and updated column types.
	 *
	 * @return void
	 */
	protected function addTimestampTypes(): void {
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
	}//end addTimestampTypes()

	/**
	 * The uuid (as `id`), slug and title as the API returns them.
	 *
	 * @return array{id: ?string, slug: ?string, title: ?string}
	 */
	protected function nameFields(): array {
		return [
			'id' => $this->uuid,
			'slug' => $this->slug,
			'title' => $this->title,
		];
	}//end nameFields()

	/**
	 * The register and schema as the API returns them.
	 *
	 * @return array{register: ?string, schema: ?string}
	 */
	protected function scopeFields(): array {
		return [
			'register' => $this->register,
			'schema' => $this->schema,
		];
	}//end scopeFields()

	/**
	 * The created and updated stamps as the API returns them (ATOM, or null).
	 *
	 * @return array{created: ?string, updated: ?string}
	 */
	protected function timestampFields(): array {
		return [
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];
	}//end timestampFields()
}//end class
