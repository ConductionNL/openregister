<?php

/**
 * DbalObjectRef: the object a DBAL-source call addresses.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectSource;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Register, schema and object-source config of one call, plus the object id
 * when the call addresses a single row. They travel together through every
 * read and write of {@see DbalObjectSourceProvider}.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class DbalObjectRef {

	/**
	 * Constructor.
	 *
	 * @param Register             $register The register the schema belongs to.
	 * @param Schema               $schema   The sourced schema.
	 * @param array<string, mixed> $config   The object-source `config` block.
	 * @param string|null          $id       The object id, for single-row calls.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly array $config,
		public readonly ?string $id = null,
	) {
	}//end __construct()

	/**
	 * The same reference, addressing one row.
	 *
	 * @param string $id The object id.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/dbal-virtual-registers/spec.md
	 */
	public function withId(string $id): self {
		return new self(register: $this->register, schema: $this->schema, config: $this->config, id: $id);
	}//end withId()
}//end class
