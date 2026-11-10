<?php

/**
 * The caller asking to manage a record's grants.
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
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The caller's account, or null when anonymous, and its group ids.
 *
 * @spec openspec/changes/special-right-to-manage-grants/specs/object-ownership/spec.md
 */
final class GrantCaller {

	/**
	 * Constructor.
	 *
	 * @param string|null $userId     The caller, or null when anonymous.
	 * @param string[]    $userGroups The caller's group ids.
	 */
	public function __construct(
		public readonly ?string $userId,
		public readonly array $userGroups,
	) {
	}//end __construct()
}//end class
