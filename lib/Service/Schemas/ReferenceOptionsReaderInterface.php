<?php

/**
 * ReferenceOptionsReaderInterface: what callers outside the component use of ReferenceOptionsReader.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\Schema;

/**
 * Declares only what callers outside the component use of ReferenceOptionsReader; the rest
 * of the class stays its own.
 */
interface ReferenceOptionsReaderInterface {

	/**
	 * What the reader answers for one property.
	 *
	 * @param Schema        $schema The schema of the record being edited.
	 * @param ReferencePick $pick   The reference property and the record being edited.
	 *
	 * @return array{filtered: bool, needs: array<int, string>, filter: array<string, mixed>,
	 *         target: array{schema: ?string, register: ?string}} The plan.
	 *
	 * @throws ReferenceFilterException When the declaration cannot be honoured.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function plan(Schema $schema, ReferencePick $pick): array;

	/**
	 * Whether the plan can be turned into a list at all.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return bool Whether options may be listed.
	 */
	public function isAnswerable(array $plan): bool;

	/**
	 * The page size to use, clamped.
	 *
	 * 🔑 A LIMIT OF ZERO IS NOT UNLIMITED. `_limit=0` reaching a query builder
	 * produces `LIMIT 0`, an empty page with an HTTP 200 and no explanation,
	 * which is the same failure `QueryLimit::normalise()` was written for. Here
	 * it means "the default", because a picker asking for nothing is a picker
	 * that did not say.
	 *
	 * @param mixed $requested What the caller asked for.
	 *
	 * @return int The page size.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function limitFor(mixed $requested): int;

	/**
	 * The query the options read runs, given a resolved plan.
	 *
	 * The filter goes in as ordinary object-query keys, so the search path
	 * applies the caller's own row access to it. Nothing here bypasses RBAC,
	 * and nothing here re-implements it.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param ListQuery            $page The page size and where the page starts.
	 *
	 * @return array<string, mixed> The query.
	 *
	 * @spec openspec/changes/fields-a-user-adds-and-choices-a-record-narrows/specs/runtime-schema-api/spec.md
	 */
	public function queryFor(array $plan, ListQuery $page): array;
}//end interface
