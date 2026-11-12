<?php

/**
 * The schema and register mappers an RBAC read resolves its source through.
 *
 * A rule operand names a schema and, optionally, a register; resolving it
 * always asks both mappers in turn (find the schema, then the register that
 * holds it). They are handed over together as one collaborator. This bundle
 * carries no behaviour and, deliberately, no MagicMapper: see the constructor
 * note on {@see MemberOfResolver}.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;

/**
 * Schema and register lookup, wired by DI autowiring.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class SchemaRegisterLookup {
	/**
	 * Bundle the mappers.
	 *
	 * @param SchemaMapperInterface   $schemas   Loads the schema an operand names.
	 * @param RegisterMapperInterface $registers Finds the register that holds it.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemas,
		public readonly RegisterMapperInterface $registers,
	) {
	}//end __construct()
}//end class
