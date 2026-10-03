<?php

/**
 * ExportProfileService — administering the profiles, and running one.
 *
 * A profile is an object with an owner, a name and a history (design D-3), so
 * this is where it is validated on the way in and resolved on the way out. The
 * export verb is checked here as well as at the controller, because the
 * scheduled runner and the whole-set job reach this service without passing a
 * controller at all.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Export
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ExportProfileMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Creates, validates and runs export profiles.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A profile run needs the
 *     register, the schema, the selection, the writer, the verb and the trail.
 *     Splitting the run across two classes would move the collaborators, not
 *     reduce them.
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class ExportProfileService {

	/**
	 * Wire the collaborators.
	 *
	 * @param ExportProfileMapper  $mapper        Profile persistence.
	 * @param RegisterMapper       $registerMapper Register lookups.
	 * @param SchemaMapper         $schemaMapper  Schema lookups.
	 * @param ExportService        $exportService The selection every export shares.
	 * @param ExportProfileWriter  $writer        Projection and file writing.
	 * @param ExportRightService    $rightService  The export verb.
	 * @param ExportAuditRecorder   $recorder      The audit trail.
	 * @param ExportProfileValidator $validator    Judges a submitted profile.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExportProfileMapper $mapper,
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
		private readonly ExportService $exportService,
		private readonly ExportProfileWriter $writer,
		private readonly ExportRightService $rightService,
		private readonly ExportAuditRecorder $recorder,
		private readonly ExportProfileValidator $validator,
	) {
	}//end __construct()

	/**
	 * Find a profile by id.
	 *
	 * @param int $id The profile id.
	 *
	 * @return ExportProfile The profile.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no row matches.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function find(int $id): ExportProfile {
		return $this->mapper->find($id);
	}//end find()

	/**
	 * The profiles a caller may list.
	 *
	 * @param string $callerUid     The caller.
	 * @param bool   $callerIsAdmin Whether the caller is an administrator.
	 *
	 * @return ExportProfile[] The profiles.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The admin listing is the
	 *     same listing with a wider scope, not a second method.
	 */
	public function listFor(string $callerUid, bool $callerIsAdmin): array {
		if ($callerIsAdmin === true) {
			return $this->mapper->findAll();
		}

		return $this->mapper->findByOwner($callerUid);
	}//end listFor()

	/**
	 * Create a profile.
	 *
	 * @param array<string, mixed> $data     The submitted profile.
	 * @param string               $ownerUid The owning user.
	 *
	 * @return ExportProfile The persisted profile.
	 *
	 * @throws InvalidArgumentException When the submission does not make sense.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4 is the standard Symfony UID
	 *     pattern, as AuditTrailMapper::createToolInvocationEntry().
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function create(array $data, string $ownerUid): ExportProfile {
		$this->validator->validate(data: $data, partial: false);

		$profile = new ExportProfile();
		$profile->setUuid((string)Uuid::v4());
		$profile->setOwner($ownerUid);
		$profile->setCreatedAt(new DateTime());
		$profile->setUpdatedAt(new DateTime());
		$this->apply(profile: $profile, data: $data);

		return $this->mapper->insert($profile);
	}//end create()

	/**
	 * Update a profile.
	 *
	 * @param ExportProfile        $profile The profile to change.
	 * @param array<string, mixed> $data    The submitted changes.
	 *
	 * @return ExportProfile The persisted profile.
	 *
	 * @throws InvalidArgumentException When the submission does not make sense.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function update(ExportProfile $profile, array $data): ExportProfile {
		$this->validator->validate(data: $data, partial: true);
		$this->apply(profile: $profile, data: $data);
		$profile->setUpdatedAt(new DateTime());

		return $this->mapper->update($profile);
	}//end update()

	/**
	 * Delete a profile.
	 *
	 * @param ExportProfile $profile The profile to delete.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function delete(ExportProfile $profile): void {
		$this->mapper->delete($profile);
	}//end delete()

	/**
	 * Refuse a caller who is neither the owner nor an administrator.
	 *
	 * @param ExportProfile $profile       The profile.
	 * @param string        $callerUid     The caller.
	 * @param bool          $callerIsAdmin Whether the caller is an administrator.
	 *
	 * @return void
	 *
	 * @throws ExportRefusedException When the caller may not touch the profile.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The administrator bypass is
	 *     a property of the caller, not a second code path.
	 */
	public function assertOwnerOrAdmin(ExportProfile $profile, string $callerUid, bool $callerIsAdmin): void {
		if ($callerIsAdmin === true || $profile->getOwner() === $callerUid) {
			return;
		}

		throw new ExportRefusedException(
			rule: 'profile-not-yours',
			reason: 'This export profile belongs to another user.',
			statusCode: 403
		);
	}//end assertOwnerOrAdmin()

	/**
	 * Run a profile as a named principal, and record what left.
	 *
	 * @param ExportProfile $profile The profile to run.
	 * @param string        $actorUid The principal the export runs as.
	 *
	 * @return array{bytes: string, rowCount: int, metadata: array<string, mixed>, filename: string} The file.
	 *
	 * @throws ExportRefusedException When the principal does not hold the export verb.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function run(ExportProfile $profile, string $actorUid): array {
		$register = $this->registerOf(profile: $profile);
		$schema = $this->schemaOf(profile: $profile);

		$refusal = $this->rightService->refusalForUid(schema: $schema, userId: $actorUid);
		if ($refusal !== null) {
			$this->recorder->recordRefused(
				profile: ($profile->getName() ?? ''),
				rule: $refusal->getRule(),
				reason: $refusal->getMessage(),
				register: $profile->getRegisterId(),
				schema: $profile->getSchemaId(),
				actorId: $actorUid
			);

			throw $refusal;
		}

		$objects = $this->exportService->fetchExportObjects(
			register: $register,
			schema: $schema,
			filters: $profile->getFiltersArray()
		);

		$written = $this->writer->write(profile: $profile, objects: $objects, schema: $schema);

		$this->recorder->recordCompleted(
			profile: ($profile->getName() ?? ''),
			rowCount: $written['rowCount'],
			format: ($profile->getFormat() ?? 'csv'),
			valueMode: ($profile->getValueMode() ?? ExportProfile::MODE_STORED),
			register: $profile->getRegisterId(),
			schema: $profile->getSchemaId(),
			actorId: $actorUid
		);

		$written['filename'] = $this->filenameFor(profile: $profile);

		return $written;
	}//end run()

	/**
	 * The filename a profile writes under.
	 *
	 * @param ExportProfile $profile The profile.
	 *
	 * @return string The filename.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	private function filenameFor(ExportProfile $profile): string {
		$slug = preg_replace('/[^a-z0-9]+/i', '-', (string)($profile->getName() ?? 'export'));
		$slug = trim((string)$slug, '-');
		if ($slug === '') {
			$slug = 'export';
		}

		return strtolower($slug) . '_' . (new DateTime())->format('Y-m-d_His')
			. '.' . ($profile->getFormat() ?? 'csv');
	}//end filenameFor()

	/**
	 * The register a profile exports, or null when it does not resolve.
	 *
	 * @param ExportProfile $profile The profile.
	 *
	 * @return Register|null The register.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	private function registerOf(ExportProfile $profile): ?Register {
		if ($profile->getRegisterId() === null) {
			return null;
		}

		try {
			return $this->registerMapper->find($profile->getRegisterId(), _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end registerOf()

	/**
	 * The schema a profile exports, or null on a whole-set profile.
	 *
	 * @param ExportProfile $profile The profile.
	 *
	 * @return Schema|null The schema.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	private function schemaOf(ExportProfile $profile): ?Schema {
		if ($profile->getSchemaId() === null) {
			return null;
		}

		try {
			return $this->schemaMapper->find($profile->getSchemaId(), _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}
	}//end schemaOf()





	/**
	 * Copy a validated submission onto the entity.
	 *
	 * @param ExportProfile        $profile The entity.
	 * @param array<string, mixed> $data    The submission.
	 *
	 * @return void
	 */
	private function apply(ExportProfile $profile, array $data): void {
		$this->applyIdentity(profile: $profile, data: $data);
		$this->applyScope(profile: $profile, data: $data);
		$this->applyShape(profile: $profile, data: $data);
	}//end apply()

	/**
	 * The name and the description.
	 *
	 * @param ExportProfile        $profile The entity.
	 * @param array<string, mixed> $data    The submission.
	 *
	 * @return void
	 */
	private function applyIdentity(ExportProfile $profile, array $data): void {
		if (isset($data['name']) === true) {
			$profile->setName((string)$data['name']);
		}

		if (array_key_exists('description', $data) === false) {
			return;
		}

		$description = null;
		if ($data['description'] !== null) {
			$description = (string)$data['description'];
		}

		$profile->setDescription($description);
	}//end applyIdentity()

	/**
	 * What the profile reads: the register, the schema, the filter and the flag.
	 *
	 * @param ExportProfile        $profile The entity.
	 * @param array<string, mixed> $data    The submission.
	 *
	 * @return void
	 */
	private function applyScope(ExportProfile $profile, array $data): void {
		if (isset($data['registerId']) === true) {
			$profile->setRegisterId((int)$data['registerId']);
		}

		if (array_key_exists('schemaId', $data) === true) {
			$schemaId = null;
			if ($data['schemaId'] !== null) {
				$schemaId = (int)$data['schemaId'];
			}

			$profile->setSchemaId($schemaId);
		}

		if (array_key_exists('filters', $data) === true) {
			$filters = null;
			if ($data['filters'] !== null) {
				$filters = (string)json_encode($data['filters']);
			}

			$profile->setFilters($filters);
		}

		if (array_key_exists('wholeSet', $data) === true) {
			$profile->setWholeSet((bool)$data['wholeSet']);
		}
	}//end applyScope()

	/**
	 * What the file looks like: the field order, the value mode and the format.
	 *
	 * @param ExportProfile        $profile The entity.
	 * @param array<string, mixed> $data    The submission.
	 *
	 * @return void
	 */
	private function applyShape(ExportProfile $profile, array $data): void {
		if (isset($data['fields']) === true) {
			$profile->setFields((string)json_encode(array_values($data['fields'])));
		}

		$profile->setValueMode((string)($data['valueMode'] ?? $profile->getValueMode() ?? ExportProfile::MODE_STORED));
		$profile->setFormat((string)($data['format'] ?? $profile->getFormat() ?? 'csv'));
	}//end applyShape()
}//end class
