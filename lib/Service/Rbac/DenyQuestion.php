<?php

/**
 * Which verb a deny is asked about, and for whom.
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
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The verb being decided and the caller's principal names.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class DenyQuestion {

	/**
	 * Constructor.
	 *
	 * @param string   $action     The verb being decided.
	 * @param string[] $principals The caller's principal names.
	 */
	public function __construct(
		public readonly string $action,
		public readonly array $principals,
	) {
	}//end __construct()
}//end class
