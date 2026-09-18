<?php

/**
 * Serves what an access link opens, under the subject's own rules.
 *
 * A link is a reader, not an exemption. It unlocks exactly one named subject
 * for somebody with no account; it does not unlock anything about that subject
 * that a person could not see. So the subject is fetched with the ordinary
 * group rules switched off, because there is no session for them to judge, and
 * then every filter that protects a field is applied here, explicitly:
 *
 *   - write-only and property-authorised properties are stripped, as an
 *     anonymous reader, which qualifies for no group and therefore keeps the
 *     narrowest possible set;
 *   - the timeline is filtered to public entries, so an internal note stays
 *     internal;
 *   - the platform's own bookkeeping (`@self.authorization`, the owner, the
 *     organisation, the folder) never leaves, because publishing a record is
 *     not publishing who administers it;
 *   - only files the object actually carries are listed.
 *
 * Doing this here, in one class, is the point. `_rbac: false` means "trusted
 * internal read" everywhere else in this app, and a link is not trusted: if the
 * filters rode on that flag they would all be off exactly where they matter.
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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Timeline\PublicTimeline;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves and renders the subject behind an access link.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The dependencies ARE the
 * filters: the object service fetches the subject, the schema mapper and the
 * property handler decide which fields survive, the note service and the
 * visibility service decide which timeline entries do. Splitting them would
 * scatter the rules a link must obey across several classes, which is how one
 * of them gets forgotten.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
 */
class AccessLinkReader {

	/**
	 * The `@self` keys a link may publish.
	 *
	 * An allow-list, never a deny-list. A key added to `@self` later is absent
	 * from published output until somebody decides it belongs there, which is
	 * the safe direction for a surface that answers without a session.
	 *
	 * @var array<int, string>
	 */
	private const PUBLISHABLE_SELF_KEYS = [
		'id',
		'uuid',
		'name',
		'description',
		'summary',
		'register',
		'schema',
		'published',
		'depublished',
		'created',
		'updated',
	];

	/**
	 * The most objects a view link serves in one answer.
	 *
	 * @var int
	 */
	private const MAX_VIEW_OBJECTS = 200;

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objects The object read path.
	 * @param SchemaMapper $schemas Resolves the schema whose rules apply.
	 * @param PropertyRbacHandler $properties Strips write-only and unreadable properties.
	 * @param PublicTimeline $timeline Reads and projects the public half of the timeline.
	 * @param AccessLinkSubject $subjects Reads which object a subject names.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly SchemaMapper $schemas,
		private readonly PropertyRbacHandler $properties,
		private readonly PublicTimeline $timeline,
		private readonly AccessLinkSubject $subjects,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The object a link's subject resolves to, or null.
	 *
	 * A file link resolves to the object that carries the file, because a file
	 * has no rules of its own here: it belongs to an object and inherits them.
	 * A view link resolves to no single object, so this answers null for one.
	 *
	 * @param AccessLink $link The link.
	 *
	 * @return ObjectEntity|null The object, or null.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	public function subjectObject(AccessLink $link): ?ObjectEntity {
		if ($link->getSubjectType() === AccessLink::SUBJECT_VIEW) {
			return null;
		}

		$uuid = trim((string)$link->getSubjectId());
		if ($uuid === '') {
			return null;
		}

		if ($link->getSubjectType() === AccessLink::SUBJECT_FILE) {
			$uuid = $this->subjects->objectUuid(
				subjectType: AccessLink::SUBJECT_FILE,
				subjectId: (string)$link->getSubjectId()
			);
			if ($uuid === null) {
				return null;
			}
		}

		try {
			$object = $this->objects->find(
				id: $uuid,
				_rbac: false,
				_multitenancy: false,
				_render: false,
				_audit: false
			);
		} catch (Throwable $missing) {
			$this->logger->info(
				'[AccessLinkReader] Access-link subject no longer resolves: ' . $missing->getMessage()
			);
			return null;
		}

		if ($object instanceof ObjectEntity === false) {
			return null;
		}

		if ($object->isSoftDeleted() === true) {
			// A deleted object is gone to a link too, which the caller turns
			// into the same 404 as an unknown anchor.
			//
			// `isSoftDeleted()` and never `getDeleted() !== null`: the property
			// defaults to `[]` and the hydrator leaves that default on a live
			// row, so the null comparison is true for EVERY object and would
			// refuse every read. ObjectEntity documents the trap on the
			// accessor; the reader's own tests caught it here.
			return null;
		}

		return $object;
	}//end subjectObject()

	/**
	 * What the link serves, filtered, or null when the subject is gone.
	 *
	 * @param AccessLink $link The link.
	 * @param ObjectEntity|null $object The subject, when the caller already resolved it.
	 *
	 * @return array<string, mixed>|null The body, or null when there is nothing to serve.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	public function read(AccessLink $link, ?ObjectEntity $object = null): ?array {
		if ($link->getSubjectType() === AccessLink::SUBJECT_VIEW) {
			return $this->readView(link: $link);
		}

		// The caller usually resolved the object already, because it needs it to
		// attribute the audit entry. Taking it as an argument keeps the public
		// read path to ONE database fetch instead of two.
		$object = ($object ?? $this->subjectObject(link: $link));
		if ($object === null) {
			return null;
		}

		$body = ['subject' => $this->project(object: $object)];

		if ($link->getSubjectType() === AccessLink::SUBJECT_FILE) {
			$body['file'] = $this->fileDescriptor(
				object: $object,
				fileId: (string)$this->subjects->fileId(subjectId: (string)$link->getSubjectId())
			);
			if ($body['file'] === null) {
				// A file the object does not carry is a file that is not there.
				return null;
			}
		}

		$body['timeline'] = $this->publicTimeline(object: $object);

		return $body;
	}//end read()

	/**
	 * The objects a view link serves, each filtered.
	 *
	 * @param AccessLink $link The link.
	 *
	 * @return array<string, mixed>|null The body, or null when the view is gone.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-publication-link-opens-one-object-view-or-file-as-its-own-principal-req-abl-001
	 */
	private function readView(AccessLink $link): ?array {
		$viewId = trim((string)$link->getSubjectId());
		if ($viewId === '') {
			return null;
		}

		try {
			$results = $this->objects->searchObjects(
				query: ['_limit' => self::MAX_VIEW_OBJECTS],
				_rbac: false,
				_multitenancy: false,
				views: [$viewId]
			);
		} catch (Throwable $missing) {
			$this->logger->info(
				'[AccessLinkReader] Access-link view no longer resolves: ' . $missing->getMessage()
			);
			return null;
		}

		if (is_array($results) === false) {
			return null;
		}

		$rows = [];
		foreach ($results as $candidate) {
			if ($candidate instanceof ObjectEntity === false || $candidate->isSoftDeleted() === true) {
				continue;
			}

			$rows[] = $this->project(object: $candidate);
		}

		return ['results' => $rows, 'total' => count($rows)];
	}//end readView()

	/**
	 * One object, reduced to what a link may publish.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The published projection.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	private function project(ObjectEntity $object): array {
		$data = $this->filteredProperties(object: $object);

		$serialised = $object->jsonSerialize();
		$self = [];
		if (isset($serialised['@self']) === true && is_array($serialised['@self']) === true) {
			foreach (self::PUBLISHABLE_SELF_KEYS as $key) {
				if (array_key_exists($key, $serialised['@self']) === true) {
					$self[$key] = $serialised['@self'][$key];
				}
			}
		}

		$data['@self'] = $self;
		$data['id'] = $object->getUuid();

		return $data;
	}//end project()

	/**
	 * An object's own properties, with everything a reader may not see removed.
	 *
	 * Runs as whoever is calling, which on a link read is nobody. An anonymous
	 * reader is in no group, so a property-authorised property is dropped and a
	 * write-only one is dropped for everybody. A schema that cannot be resolved
	 * yields nothing rather than everything: failing open here would publish a
	 * record whose rules could not be read.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The readable properties.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	private function filteredProperties(ObjectEntity $object): array {
		$data = $object->getObject();

		$schemaId = trim((string)$object->getSchema());
		if ($schemaId === '') {
			return [];
		}

		try {
			$schema = $this->schemas->find(id: $schemaId, _rbac: false, _multitenancy: false);
		} catch (Throwable $missing) {
			$this->logger->warning(
				'[AccessLinkReader] Refusing to publish an object whose schema does not resolve: '
				. $missing->getMessage()
			);
			return [];
		}

		return $this->properties->filterReadableProperties(schema: $schema, object: $data);
	}//end filteredProperties()

	/**
	 * The public half of an object's timeline.
	 *
	 * Fixed at `public`, never negotiated. `effectiveFilter()` would answer
	 * `public` for an anonymous caller too, but it answers `null` for a caller
	 * who may manage the object, and a link must publish the public half
	 * whatever session happens to be around it.
	 *
	 * PROJECTED, NOT PASSED THROUGH. This used to hand the link holder each
	 * public note exactly as the note service shapes it, which carries the
	 * author's user id and display name: a stranger with a link learned who
	 * at the organisation wrote every line. It also read notes only, so a
	 * kinded entry that exists as a record and not as a comment never
	 * appeared. {@see PublicTimeline} reads both and lets five keys out.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int, array<string, mixed>> The published timeline entries.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	private function publicTimeline(ObjectEntity $object): array {
		return $this->timeline->forObject(object: $object);
	}//end publicTimeline()

	/**
	 * The descriptor of one file the object carries, or null.
	 *
	 * Asked of the object rather than of the filesystem, so a file id that
	 * belongs to some other object cannot be served through this link.
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $fileId The file the link names.
	 *
	 * @return array<string, mixed>|null The file, or null when the object does not carry it.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-never-sees-past-the-objects-own-rules-req-abl-004
	 */
	private function fileDescriptor(ObjectEntity $object, string $fileId): ?array {
		$wanted = trim($fileId);
		if ($wanted === '') {
			return null;
		}

		foreach ($this->filesOf(object: $object) as $file) {
			if (is_array($file) === false) {
				continue;
			}

			foreach (['id', 'fileId', 'uuid'] as $key) {
				if (isset($file[$key]) === true && (string)$file[$key] === $wanted) {
					return $file;
				}
			}
		}

		return null;
	}//end fileDescriptor()

	/**
	 * The files an object carries, as the object reports them.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<int|string, mixed> The files.
	 */
	private function filesOf(ObjectEntity $object): array {
		$files = $object->getFiles();
		if (is_array($files) === false) {
			return [];
		}

		return $files;
	}//end filesOf()

}//end class
