<?php

/**
 * Decides whether a caller may publish a subject at a link.
 *
 * THIS IS THE WHOLE ACCESS DECISION, AND IT IS TAKEN ONCE. Every read through
 * a link afterwards runs with the group rules off, because a holder with no
 * account gives the rules nothing to judge. So minting is the only moment
 * anybody asks whether this subject may leave the building, and a mint that
 * skipped the question would let any signed-in user publish any record by
 * naming its uuid. The link would then keep serving that record correctly, for
 * as long as it lived, and nothing downstream would look wrong.
 *
 * That is why this is its own class and not a method on the reader. The reader
 * serves with the rules OFF; this decides with the rules ON, as the person
 * minting. Putting both in one place invites one flag to be copied from the
 * wrong neighbour.
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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ViewMapper;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * The mint-time authorization check.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
 */
class AccessLinkMintGuard {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects The object read path, asked with the rules on.
	 * @param AccessLinkSubject $subjects Reads which object a subject names.
	 * @param ViewMapper        $views    Resolves a view under the caller's own rules.
	 * @param RegisterMapper $registers Resolves the registers a view names, same rules.
	 * @param SchemaMapper $schemas Resolves the schemas a view names, same rules.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly AccessLinkSubject $subjects,
		private readonly ViewMapper $views,
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
	) {

	}//end __construct()

	/**
	 * Whether the calling principal may publish this subject at a link.
	 *
	 * The flags are `true` on purpose and are the point of the method: the
	 * subject is fetched exactly as the caller would fetch it themselves, so a
	 * record they cannot read is a record they cannot publish.
	 *
	 * @param string $subjectType `object`, `view` or `file`.
	 * @param string $subjectId The subject the caller wants to publish.
	 *
	 * @return bool True when the caller may mint a link over it.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	public function mayMint(string $subjectType, string $subjectId): bool {
		if (trim($subjectId) === '') {
			return false;
		}

		if ($subjectType === AccessLink::SUBJECT_VIEW) {
			return $this->mayPublishView(viewId: trim($subjectId));
		}

		$uuid = $this->subjects->objectUuid(subjectType: $subjectType, subjectId: $subjectId);
		if ($uuid === null) {
			return false;
		}

		return $this->mayReadObject(uuid: $uuid);
	}//end mayMint()

	/**
	 * Whether the caller may read one object under the ordinary rules.
	 *
	 * @param string $uuid The object uuid.
	 *
	 * @return bool True when the object answers this caller and is not in the trash.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	private function mayReadObject(string $uuid): bool {
		try {
			$object = $this->objects->find(
				id: $uuid,
				_rbac: true,
				_multitenancy: true,
				_render: false,
				_audit: false
			);
		} catch (Throwable $denied) {
			unset($denied);
			return false;
		}

		return ($object instanceof ObjectEntity === true && $object->isSoftDeleted() === false);
	}//end mayReadObject()

	/**
	 * Whether the caller may publish one saved view.
	 *
	 * A view that refuses to list for this caller is a view this caller may not
	 * publish.
	 *
	 * @param string $viewId The view uuid.
	 *
	 * @return bool True when the view answers this caller.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	private function mayPublishView(string $viewId): bool {
		// ASK THE VIEW, NOT A SEARCH THAT MENTIONS IT.
		//
		// This used to run `searchObjects(..., _rbac: true, _multitenancy: true,
		// views: [$viewId])` and accept any array as proof. It proved nothing:
		// `applyViewsToQuery()` SKIPS a view it cannot resolve — logging a
		// warning and leaving the query unfiltered — so a view belonging to
		// another organisation produced a perfectly ordinary, still-RBAC'd
		// search, an array came back, and the guard said yes. A caller in one
		// organisation could mint a publication link over another's view.
		//
		// That tolerance is deliberate elsewhere and stays, which is exactly
		// why this guard must not lean on it. Resolving the view directly under
		// the caller's OWN rules — RBAC and multitenancy both left at their
		// defaults — asks the question this method's name claims to ask, and a
		// refusal is a refusal rather than a quietly widened search.
		try {
			// Flags stated rather than defaulted. They ARE the property this guard
			// exists to hold - resolving the view exempt is precisely what it must
			// not do - and a test cannot pin an argument that was never passed.
			$view = $this->views->find($viewId, _rbac: true, _multitenancy: true);
		} catch (Throwable $denied) {
			unset($denied);
			return false;
		}

		return $this->mayReadEverythingTheViewNames(viewQuery: $view->getQuery());
	}//end mayPublishView()

	/**
	 * Whether the caller may read every register and schema the view's query names.
	 *
	 * Resolving the view row proves the caller may see the VIEW. It says nothing
	 * about what the view points at, and a view's stored query is caller-supplied:
	 * `POST /api/views` is `#[NoAdminRequired]` and copies `configuration.registers`
	 * and `configuration.schemas` into the stored query verbatim, with no check
	 * against the caller's organisation. So a view you legitimately own, in your
	 * own organisation, may name another organisation's register ids.
	 *
	 * That is harmless only for as long as those ids fail to reach the query. They
	 * now do - the array bound no longer collapses in MagicMapper - and a link mints
	 * a reader that runs with RBAC and multitenancy OFF. Register and schema ids are
	 * auto-increment integers, so an unchecked view is an enumerable cross-tenant
	 * read from an ordinary account. The two fixes belong in the same change.
	 *
	 * Resolved at the caller's own defaults: `_rbac` and `_multitenancy` both true.
	 *
	 * @param array<string, mixed>|null $viewQuery The view's stored query.
	 *
	 * @return bool True when every register and schema it names answers this caller.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	private function mayReadEverythingTheViewNames(?array $viewQuery): bool {
		if ($viewQuery === null) {
			return true;
		}

		$registers = ($viewQuery['registers'] ?? []);
		if (is_array($registers) === true) {
			foreach ($registers as $registerId) {
				try {
					$this->registers->find($registerId);
				} catch (Throwable $denied) {
					unset($denied);
					return false;
				}
			}
		}

		$schemas = ($viewQuery['schemas'] ?? []);
		if (is_array($schemas) === true) {
			foreach ($schemas as $schemaId) {
				try {
					$this->schemas->find($schemaId);
				} catch (Throwable $denied) {
					unset($denied);
					return false;
				}
			}
		}

		return true;
	}//end mayReadEverythingTheViewNames()
}//end class
