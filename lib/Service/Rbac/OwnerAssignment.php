<?php

/**
 * One object and the owner it is given.
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
 * @spec openspec/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * One object and the owner it is given.
 *
 * @spec openspec/specs/object-ownership/spec.md
 */
final class OwnerAssignment {

	/**
	 * Constructor.
	 *
	 * @param string               $objectUuid The object UUID.
	 * @param string               $owner      The new owner's uid.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly string $owner,
	) {
	}//end __construct()
}//end class
