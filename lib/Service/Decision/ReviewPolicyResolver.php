<?php

/**
 * OpenRegister ReviewPolicyResolver
 *
 * Finds the review policy that governs an entity relation: its schema's
 * `x-openregister-review`, reached through the relation's own object or the
 * object a file belongs to, else the instance default for files.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\ManagedFileOwnerLookup;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves a relation's policy and describes its subject for listeners.
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
 */
class ReviewPolicyResolver {

	/**
	 * The subject type of a decision on an entity relation.
	 */
	public const SUBJECT_TYPE = 'entity-relation';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper            $schemaMapper Reads the schema's declaration.
	 * @param FileSettingsHandler     $fileSettings Holds the instance default.
	 * @param IRootFolder             $rootFolder   Finds a relation's file.
	 * @param ManagedFileOwnerLookup $owners        Finds the object a file belongs to.
	 * @param LoggerInterface         $logger       Records a policy that could not be read.
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly FileSettingsHandler $fileSettings,
		private readonly IRootFolder $rootFolder,
		private readonly ManagedFileOwnerLookup $owners,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The policy for a relation and a description of what it is on.
	 *
	 * @param EntityRelation $relation The relation.
	 *
	 * @return array{policy: ReviewPolicy, subject: array<string, mixed>}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ReviewPolicy::fromDeclaration is its named constructor.
	 *
	 * @spec openspec/changes/reviewer-owns-their-decisions/specs/entity-relation-grondslagen/spec.md#requirement-a-reviewers-decision-is-changed-only-by-that-reviewer-or-a-supervisor-req-rod-001
	 */
	public function forRelation(EntityRelation $relation): array {
		$subject = $this->subjectOf(relation: $relation);
		$lookupFailed = (($subject['lookupFailed'] ?? false) === true);
		unset($subject['lookupFailed']);
		$schema = (string)($subject['schema'] ?? '');

		if ($lookupFailed === true) {
			// Fail closed: the file's object could not be found, so the schema
			// that may hide or restrict the decision is unknown. Never fall
			// to the instance default, which is open when unset.
			return ['policy' => $this->closedPolicy(), 'subject' => $subject];
		}

		if ($schema === '') {
			return ['policy' => $this->instanceDefault(), 'subject' => $subject];
		}

		try {
			$configuration = ($this->schemaMapper->find(id: $schema, _rbac: false, _multitenancy: false)->getConfiguration() ?? []);
		} catch (Throwable $error) {
			// Fail closed: a policy that cannot be read is decider-owned with
			// no supervisors and hides the decision from other reviewers, so
			// only the decider and an administrator may see or change it.
			$this->logger->warning(
				message: '[ReviewPolicyResolver] The schema could not be read; the decision stays with its decider.',
				context: ['schema' => $schema, 'error' => $error->getMessage()]
			);
			return ['policy' => $this->closedPolicy(), 'subject' => $subject];
		}

		if (array_key_exists(ReviewPolicy::ANNOTATION, $configuration) === false) {
			return ['policy' => $this->instanceDefault(), 'subject' => $subject];
		}

		return ['policy' => ReviewPolicy::fromDeclaration(declaration: $configuration[ReviewPolicy::ANNOTATION]), 'subject' => $subject];
	}//end forRelation()

	/**
	 * What the relation is on: its own object, or the object its file belongs to.
	 *
	 * @param EntityRelation $relation The relation.
	 *
	 * @return array<string, mixed> subjectType, subjectId, fileId, objectId,
	 *                              register, schema and objectUuid; `lookupFailed`
	 *                              (true) when the file-to-object lookup threw or
	 *                              the file itself could not be found. A file that
	 *                              exists and belongs to no object is not a failure.
	 */
	private function subjectOf(EntityRelation $relation): array {
		$subject = [
			'subjectType' => self::SUBJECT_TYPE,
			'subjectId' => (string)$relation->getId(),
			'fileId' => $relation->getFileId(),
			'objectId' => $relation->getObjectId(),
			'register' => $relation->getRegisterId(),
			'schema' => $relation->getSchemaId(),
			'objectUuid' => $relation->getObjectUuid(),
		];

		$fileId = (int)($relation->getFileId() ?? 0);
		if ((string)$subject['schema'] !== '' || $fileId <= 0) {
			return $subject;
		}

		try {
			$node = $this->rootFolder->getFirstNodeById($fileId);
			if ($node === null) {
				// The file cannot be found, so nothing says which object (and schema) it belongs to: that is an
				// unknown owner, not a file without one, and an unknown policy fails closed.
				$subject['lookupFailed'] = true;

				return $subject;
			}

			$object = $this->owners->find(file: $node);
		} catch (Throwable $error) {
			$this->logger->info(
				message: '[ReviewPolicyResolver] The file\'s object could not be found; the decision stays with its decider.',
				context: ['fileId' => $fileId, 'error' => $error->getMessage()]
			);
			$subject['lookupFailed'] = true;
			return $subject;
		}

		if ($object !== null) {
			$subject['register'] = $object->getRegister();
			$subject['schema'] = $object->getSchema();
			$subject['objectUuid'] = $object->getUuid();
		}

		return $subject;
	}//end subjectOf()

	/**
	 * The policy for a declaration that cannot be read: decider-owned, no supervisors, others hidden.
	 *
	 * @return ReviewPolicy The policy.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ReviewPolicy::fromDeclaration is its named constructor.
	 */
	private function closedPolicy(): ReviewPolicy {
		return ReviewPolicy::fromDeclaration(declaration: ['ownership' => ReviewPolicy::OWNERSHIP_DECIDER, 'hideOthers' => true]);
	}//end closedPolicy()

	/**
	 * The instance default, for files that belong to no schema. Unset is open.
	 *
	 * @return ReviewPolicy The policy.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ReviewPolicy::fromDeclaration is its named constructor.
	 */
	private function instanceDefault(): ReviewPolicy {
		$settings = $this->fileSettings->getFileSettingsOnly();

		return ReviewPolicy::fromDeclaration(declaration: ($settings[ReviewPolicy::SETTINGS_KEY] ?? null));
	}//end instanceDefault()
}//end class
