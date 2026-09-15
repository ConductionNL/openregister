<?php

/**
 * Reads what an access link's subject names.
 *
 * A link's subject is a (type, id) pair, and for a file the id carries two
 * things: the object that owns the file, and the file. Writing it as
 * `<object uuid>/<file id>` means the owning object is NAMED in the link rather
 * than looked up from the file afterwards. A file id on its own would let a
 * link point at a file whose object nobody ever checked, which is the shape the
 * mint guard exists to prevent.
 *
 * Shared by the reader and the mint guard on purpose. They ask the same
 * question with the access rules pointing in opposite directions, and the one
 * thing that must NOT differ between them is which object the subject means.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Db\AccessLink;

/**
 * The object and file a link subject names.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
 */
class AccessLinkSubject {

	/**
	 * The object uuid a subject resolves to, or null.
	 *
	 * A view resolves to no single object, so it answers null too. Callers
	 * handle a view before asking.
	 *
	 * @param string $subjectType `object`, `view` or `file`.
	 * @param string $subjectId The subject identifier.
	 *
	 * @return string|null The object uuid, or null when there is none.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	public function objectUuid(string $subjectType, string $subjectId): ?string {
		$id = trim($subjectId);
		if ($id === '' || $subjectType === AccessLink::SUBJECT_VIEW) {
			return null;
		}

		if ($subjectType !== AccessLink::SUBJECT_FILE) {
			return $id;
		}

		return $this->half(subjectId: $id, index: 0);
	}

	/**
	 * The file id a file subject names, or null.
	 *
	 * @param string $subjectId The file subject, as `<object uuid>/<file id>`.
	 *
	 * @return string|null The file id, or null when the subject is malformed.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	public function fileId(string $subjectId): ?string {
		return $this->half(subjectId: trim($subjectId), index: 1);
	}

	/**
	 * One half of a `<object uuid>/<file id>` subject, or null.
	 *
	 * @param string $subjectId The file subject.
	 * @param int $index 0 for the object uuid, 1 for the file id.
	 *
	 * @return string|null The half, or null when the subject is malformed.
	 */
	private function half(string $subjectId, int $index): ?string {
		$parts = explode('/', $subjectId, 2);
		if (count($parts) !== 2) {
			return null;
		}

		$half = trim($parts[$index]);
		if ($half === '') {
			return null;
		}

		return $half;
	}
}
