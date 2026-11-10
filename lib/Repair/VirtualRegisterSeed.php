<?php

/**
 * OpenRegister virtual register seed
 *
 * One virtual register being seeded: found or created once, its schema ids
 * collected as the seed ensures each schema, and saved only when that list grew.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use Throwable;

/**
 * A virtual register and the schema ids it should list.
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
 */
final class VirtualRegisterSeed {

	/**
	 * The schema ids the register lists, including the ones attached by this seed.
	 *
	 * @var array<int, int|string>
	 */
	private array $schemaIds;

	/**
	 * Whether this seed attached a schema the register did not list yet.
	 *
	 * @var boolean
	 */
	private bool $changed = false;

	/**
	 * Start from the register's current schema list.
	 *
	 * @param Register $register The virtual register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	public function __construct(
		public readonly Register $register,
	) {
		$this->schemaIds = $register->getSchemas();
	}//end __construct()

	/**
	 * Find the register by the definition's slug, or create it from the definition.
	 *
	 * @param SchemaCatalogInterface $catalog    The register and schema mappers.
	 * @param array<string, mixed>   $definition The register to create when none has the slug.
	 *
	 * @return self The seed for that register.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	public static function findOrCreate(SchemaCatalogInterface $catalog, array $definition): self {
		try {
			$register = $catalog->registers()->find($definition['slug'], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// Not found — create it.
			$register = $catalog->registers()->createFromArray(object: $definition);
		}

		return new self(register: $register);
	}//end findOrCreate()

	/**
	 * List a schema on the register when it is not listed yet.
	 *
	 * @param Schema $schema The ensured schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	public function attach(Schema $schema): void {
		if (in_array($schema->getId(), $this->schemaIds, false) === false) {
			$this->schemaIds[] = $schema->getId();
			$this->changed = true;
		}
	}//end attach()

	/**
	 * Save the register's schema list, when a schema was attached.
	 *
	 * @param SchemaCatalogInterface $catalog The register and schema mappers.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-2.2
	 */
	public function save(SchemaCatalogInterface $catalog): void {
		if ($this->changed === true) {
			$this->register->setSchemas($this->schemaIds);
			$catalog->registers()->update($this->register);
		}
	}//end save()
}//end class
