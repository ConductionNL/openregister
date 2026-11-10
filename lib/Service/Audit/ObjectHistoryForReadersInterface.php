<?php

/**
 * ObjectHistoryForReadersInterface: what callers outside the component use of ObjectHistoryForReaders.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * The object history page the audit trail endpoints show to readers.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ObjectHistoryForReadersInterface {

	/**
	 * One page of an object's history, or null when the caller may not read the object.
	 *
	 * The whole history of the object is read and filtered before it is paged,
	 * because a free-text search has to match what the reader is shown, not
	 * the stored payload: matched in SQL, `?_search=<value>` would tell a
	 * reader whether a hidden property ever held that value.
	 *
	 * @param ObjectRef $ref    The register and schema slug or id and the object id from the path.
	 * @param array     $params limit, offset, page, filters, sort and search, as the controller extracts them.
	 *
	 * @return array{results: array<int, array>, total: int, page: int|null, pages: float, limit: int, offset: int|null, readEventsVisible: bool}|null
	 *     The page, with whether read entries are shown to this caller, or null when the object is not readable for this caller.
	 *
	 * @throws PurposeRefusedException When the schema asks for a purpose and none usable was named.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException When the identifier is a slug two objects of the register and schema hold.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
	 */
	public function page(ObjectRef $ref, array $params): ?array;
}//end interface
