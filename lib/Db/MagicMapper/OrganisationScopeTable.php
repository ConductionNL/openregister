<?php

/**
 * OrganisationScopeTable
 *
 * The register+schema pair of the magic table an organisation scope is decided for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

/**
 * The table a caller named when asking for its organisation scope: the
 * register and schema shared master data is resolved for. Either may be null.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
 */
final class OrganisationScopeTable
{
	/**
	 * Constructor.
	 *
	 * @param int|null $registerId The register of the table being read.
	 * @param int|null $schemaId   The schema of the table being read.
	 */
	public function __construct(
		public readonly ?int $registerId,
		public readonly ?int $schemaId,
	) {
	}//end __construct()

	/**
	 * Whether the caller named the table at all. A caller that names neither
	 * register nor schema gets no shared master data widening.
	 *
	 * @return bool True when the register or the schema is known.
	 */
	public function isNamed(): bool {
		return $this->registerId !== null || $this->schemaId !== null;
	}//end isNamed()
}//end class
