<?php

/**
 * The register and schema an access report names, as far as they resolve.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and the schema a reported object sits in; either may not resolve.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class ReportedPlace {

	/**
	 * Constructor.
	 *
	 * @param Register|null $register The register, or null when the reference did not resolve.
	 * @param Schema|null   $schema   The schema, or null when the reference did not resolve.
	 */
	public function __construct(
		public readonly ?Register $register,
		public readonly ?Schema $schema,
	) {
	}//end __construct()
}//end class
