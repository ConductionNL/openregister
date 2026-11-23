<?php

/**
 * A schema being saved, and the schema it is saved over.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * The schema as it will be written and, when saving over one, as it is stored.
 *
 * @spec openspec/specs/row-field-level-security/spec.md
 */
final class SchemaSave {

	/**
	 * Constructor.
	 *
	 * @param Schema      $schema The schema being saved.
	 * @param Schema|null $stored The schema as stored, when saving over one.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ?Schema $stored,
	) {
	}//end __construct()
}//end class
