<?php

/**
 * Properties a reader may not see, with the schema that says what copies them.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Schema;

/**
 * The input of the withhold step of a render.
 */
final readonly class WithheldProperties {

	/**
	 * Constructor.
	 *
	 * @param Schema       $schema  The schema of the object.
	 * @param list<string> $removed The withheld property names.
	 */
	public function __construct(
		public Schema $schema,
		public array $removed,
	) {
	}//end __construct()
}//end class
