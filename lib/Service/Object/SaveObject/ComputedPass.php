<?php

/**
 * One evaluation pass of a schema's computed fields: at save or at read.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object\SaveObject;

use OCA\OpenRegister\Db\Schema;

/**
 * The schema whose computed fields are evaluated, and the moment (`save` or `read`) they are evaluated for.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class ComputedPass {
	/**
	 * Constructor.
	 *
	 * @param Schema $schema     The schema containing the property definitions.
	 * @param string $evaluateOn The evaluation mode: 'save' or 'read'.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly string $evaluateOn = 'save',
	) {
	}//end __construct()
}//end class
