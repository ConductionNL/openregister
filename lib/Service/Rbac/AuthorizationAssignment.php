<?php

/**
 * One object and the authorization block it is given.
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
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * One object and the authorization block it is given.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */
final class AuthorizationAssignment {

	/**
	 * Constructor.
	 *
	 * @param string               $objectUuid The object UUID.
	 * @param array<string, mixed> $block      The block to store.
	 */
	public function __construct(
		public readonly string $objectUuid,
		public readonly array $block,
	) {
	}//end __construct()
}//end class
