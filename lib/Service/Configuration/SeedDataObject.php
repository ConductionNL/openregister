<?php

/**
 * One seed-data object and where it is imported to.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A seed object's data without its `_relatedItems` marker, the related items,
 * and the register and schema it is saved in: the pass's own, or the ones
 * its `@self` names in another app's configuration.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class SeedDataObject
{

	/**
	 * The related items the object declared, created once it is saved.
	 *
	 * @var mixed
	 */
	public readonly mixed $relatedItems;

	/**
	 * The object data, without `_relatedItems`.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $data;

	/**
	 * The schema the object is saved in.
	 *
	 * @var Schema|null
	 */
	public ?Schema $schema;

	/**
	 * The register the object is saved in.
	 *
	 * @var Register|null
	 */
	public ?Register $register;

	/**
	 * The register id the object is saved under.
	 *
	 * @var integer|string|null
	 */
	public int|string|null $registerId;

	/**
	 * The slug (else title) the object is known by.
	 *
	 * @var mixed
	 */
	public mixed $slug = null;

	/**
	 * The uuid or slug the object is looked up by.
	 *
	 * @var mixed
	 */
	public mixed $identifier = null;

	/**
	 * Whether more than one stored object answers to its identifier.
	 *
	 * @var boolean
	 */
	public bool $ambiguous = false;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data The object as the configuration lists it.
	 * @param SeedImport           $seed The pass, for the default register and schema.
	 */
	public function __construct(array $data, SeedImport $seed) {
		$this->relatedItems = ($data['_relatedItems'] ?? null);
		unset($data['_relatedItems']);
		$this->data = $data;
		$this->schema = $seed->schema;
		$this->register = $seed->targetRegister;
		$this->registerId = $seed->targetRegisterId;
	}//end __construct()
}//end class
