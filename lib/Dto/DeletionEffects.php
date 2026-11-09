<?php

/**
 * OpenRegister Deletion Effects DTO
 *
 * What a delete does to the objects that reference the deleted one: which are
 * deleted with it, which have the reference set to null, and which have it
 * set to the schema default.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dto;

/**
 * The cascade, set-null and set-default targets of one deletion.
 *
 * @category Dto
 * @package  OCA\OpenRegister\Dto
 */
final class DeletionEffects {
	/**
	 * Constructor.
	 *
	 * @param array $cascadeTargets Dependent objects that will be cascade-deleted alongside the root.
	 * @param array $nullifyTargets Dependent objects whose reference property will be set to null.
	 * @param array $defaultTargets Dependent objects whose reference property will be set to its schema default.
	 */
	public function __construct(
		public readonly array $cascadeTargets = [],
		public readonly array $nullifyTargets = [],
		public readonly array $defaultTargets = [],
	) {
	}//end __construct()
}//end class
