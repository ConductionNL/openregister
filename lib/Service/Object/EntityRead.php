<?php

/**
 * An object being rendered, with its schema.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * The entity and schema the read steps of a render work on.
 */
final readonly class EntityRead {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $entity The entity.
	 * @param Schema|null  $schema Its schema, when known.
	 */
	public function __construct(
		public ObjectEntity $entity,
		public ?Schema $schema,
	) {
	}//end __construct()
}//end class
