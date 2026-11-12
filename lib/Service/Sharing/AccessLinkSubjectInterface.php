<?php

/**
 * Reads what an access link's subject names.
 *
 * Callers outside lib/Service (lib/Files/Service/Sharing) type against this
 * interface; ContractBindings aliases it to AccessLinkSubject. Types only: the
 * parsing, and the access checks that use it, are unchanged.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

/**
 * Reads what an access link's subject names.
 */
interface AccessLinkSubjectInterface {

	/**
	 * The uuid of the object a subject names.
	 *
	 * @param string $subjectType `object`, `view` or `file`.
	 * @param string $subjectId   The subject identifier.
	 *
	 * @return string|null The object uuid, or null when there is none.
	 */
	public function objectUuid(string $subjectType, string $subjectId): ?string;

	/**
	 * The file id a file subject names.
	 *
	 * @param string $subjectId The file subject, as `<object uuid>/<file id>`.
	 *
	 * @return string|null The file id, or null when the subject is malformed.
	 */
	public function fileId(string $subjectId): ?string;
}//end interface
