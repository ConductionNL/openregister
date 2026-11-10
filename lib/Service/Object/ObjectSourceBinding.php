<?php

/**
 * ObjectSourceBinding: the object-source provider a schema is served from.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A read on a schema that declares `x-openregister-object-source`.
 *
 * The provider is null when the declared provider is missing or disabled: the
 * read then degrades to "not found" / an empty list and never touches the
 * magic table.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class ObjectSourceBinding {

	/**
	 * Constructor
	 *
	 * @param object|null   $provider The enabled provider, or null when missing or disabled.
	 * @param Register|null $register The register of the read.
	 * @param Schema        $schema   The schema declaring the object source.
	 */
	public function __construct(
		public readonly ?object $provider,
		public readonly ?Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * The provider configuration the schema's object-source declaration carries.
	 *
	 * @return array The configuration, empty when none is declared.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function config(): array {
		return ($this->schema->getObjectSource()['config'] ?? []);
	}//end config()
}//end class
