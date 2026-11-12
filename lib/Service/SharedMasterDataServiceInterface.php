<?php

/**
 * SharedMasterDataServiceInterface: what callers outside the component use of SharedMasterDataService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Exception\SharedMasterDataWriteException;

/**
 * Declares only what callers outside the component use of SharedMasterDataService; the rest
 * of the class stays its own.
 */
interface SharedMasterDataServiceInterface {

	/**
	 * The registers table.
	 */
	public const REGISTERS = 'openregister_registers';

	/**
	 * The schemas table.
	 */
	public const SCHEMAS = 'openregister_schemas';

	/**
	 * Forget the cached declarations.
	 *
	 * Called after a declaration is written, so the same request cannot read a
	 * share it just removed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function clearCache(): void;

	/**
	 * The ids in one table that the given organisations may read through a share.
	 *
	 * Only rows they do NOT already hold: a holder reads its own rows through
	 * the ordinary organisation filter, and adding them here would double a
	 * predicate for no gain.
	 *
	 * @param string $table Either {@see REGISTERS} or {@see SCHEMAS}.
	 * @param array<int, string> $consumerOrgUuids The reading organisation and its parents.
	 *
	 * @return array<int, int> The row ids.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function sharedIds(string $table, array $consumerOrgUuids): array;

	/**
	 * The holder organisations whose OBJECT rows the reader may see in one register+schema pair.
	 *
	 * This is the object-data half. Each magic table holds exactly one
	 * register+schema pair, so a widening resolved here applies to that pair and
	 * to nothing else the holder owns — which is the whole isolation argument
	 * for doing it this way rather than adding holder UUIDs to the tenant set
	 * globally.
	 *
	 * A share declared on EITHER the register or the schema admits the pair.
	 * Declaring it on the register is how a whole code list is shared;
	 * declaring it on the schema is how one case type or party definition is
	 * shared across the registers that carry it.
	 *
	 * @param DeclaredPair       $pair             The register and schema being read, each when known.
	 * @param array<int, string> $consumerOrgUuids The reading organisation and its parents.
	 *
	 * @return array<int, string> The holder organisation UUIDs to widen by.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function holdersForResource(DeclaredPair $pair, array $consumerOrgUuids): array;

	/**
	 * Refuse a write to shared master data the acting organisation only consumes.
	 *
	 * Design D-2: read-only to the consumer is a property of the resolution, not
	 * of the user interface. This is the write path, so this is where the
	 * refusal belongs, and the message names the holder so the caller knows who
	 * to ask rather than only that they were told no.
	 *
	 * A row whose id is not known asks nothing.
	 *
	 * @param DeclaredRow        $row            The register or schema row being written ({@see REGISTERS} or {@see SCHEMAS}).
	 * @param array<int, string> $activeOrgUuids The acting organisation and its parents.
	 *
	 * @return void
	 *
	 * @throws SharedMasterDataWriteException When the acting organisation only consumes the row.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function assertWritable(DeclaredRow $row, array $activeOrgUuids): void;
}//end interface
