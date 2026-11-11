<?php

/**
 * OpenRegister TranslationScope
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Where an object's translations are read from: the schema that declares its translatable
 * properties, and the register (when known) that sets its languages.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class TranslationScope {

	/**
	 * Constructor.
	 *
	 * @param Schema        $schema   The schema for property definitions.
	 * @param Register|null $register The register for language configuration, when known.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ?Register $register=null,
	) {
	}//end __construct()
}//end class
