<?php

/**
 * One stored write-only value and the path it is declared at.
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
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The declared dot-path and the stored value that belongs there, verbatim.
 *
 * @spec openspec/specs/row-field-level-security/spec.md#requirement-an-omitted-writeonly-value-must-be-preserved-on-save
 */
final class WriteOnlyValue {

	/**
	 * Constructor.
	 *
	 * @param string $path  The declared dot-path.
	 * @param mixed  $value The stored value.
	 */
	public function __construct(
		public readonly string $path,
		public readonly mixed $value,
	) {
	}//end __construct()
}//end class
