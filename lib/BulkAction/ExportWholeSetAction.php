<?php

/**
 * ExportWholeSetAction — the datawarehouse extract, as a bulk act.
 *
 * A whole-set extract runs for minutes and touches every register. That is
 * exactly what the bulk action mechanism already specifies, down to the
 * progress counter, the skips and the per-row outcome, so this is a bulk
 * action rather than a second long-running export path (design D-5).
 *
 * ONE FILE PER SCHEMA, APPENDED ROW BY ROW. The engine hands an action one
 * object at a time, across batches, in separate background runs; an in-memory
 * buffer would be lost between them. So each row is appended to the file its
 * schema owns, opened in append mode and closed again. That is one file handle
 * per object and no rewrite of what is already written, which is the shape that
 * survives a hundred thousand rows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category  BulkAction
 * @package   OCA\OpenRegister\BulkAction
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ExportProfile;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Export\ExportAuditRecorder;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportProfileWriter;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use RuntimeException;
use Throwable;

/**
 * The built-in whole-set export.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A row of the extract needs
 *     the profile, the schema, the writer, the verb, the trail and the owner's
 *     Files folder. Splitting the class would move the collaborators, not
 *     reduce them.
 *
 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
 */
class ExportWholeSetAction implements BulkActionInterface {

	/**
	 * The action id.
	 *
	 * @var string
	 */
	public const ID = 'openregister:export-whole-set';

	/**
	 * The folder the extract writes into, under the actor's Files.
	 *
	 * @var string
	 */
	public const FOLDER = 'Exports';

	/**
	 * Wire the collaborators.
	 *
	 * @param ExportProfileService $profiles     Profile lookups and scope.
	 * @param ExportProfileWriter  $writer       Projection and CSV lines.
	 * @param ExportRightService   $rightService The export verb.
	 * @param ExportAuditRecorder  $recorder     The audit trail.
	 * @param SchemaMapper         $schemaMapper Schema lookups for the per-schema file.
	 * @param IRootFolder          $rootFolder   The actor's Files folder.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExportProfileService $profiles,
		private readonly ExportProfileWriter $writer,
		private readonly ExportRightService $rightService,
		private readonly ExportAuditRecorder $recorder,
		private readonly SchemaMapper $schemaMapper,
		private readonly IRootFolder $rootFolder,
	) {
	}//end __construct()

	/**
	 * The action's stable id.
	 *
	 * @return string The action id.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function getId(): string {
		return self::ID;
	}//end getId()

	/**
	 * The label an operator reads.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function getLabel(): string {
		return 'Export the whole set';
	}//end getLabel()

	/**
	 * One sentence saying what the action does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function getDescription(): string {
		return 'Writes every selected object to a file per schema, in the field order the profile declares.';
	}//end getDescription()

	/**
	 * An extract reads, it does not change anything, so it needs no reason.
	 *
	 * @return bool False.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function requiresJustification(): bool {
		return false;
	}//end requiresJustification()

	/**
	 * The guards the engine enforces for this action.
	 *
	 * Deliberately none. Homogeneity refuses a selection spanning more than one
	 * schema version, which is exactly what a whole-set extract is made of.
	 *
	 * @return array<int, string> No guards.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function getGuards(): array {
		return [];
	}//end getGuards()

	/**
	 * Check the parameters before a job is created.
	 *
	 * @param array<string, mixed> $parameters The parameters the caller sent.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When no whole-set profile is named.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function validateParameters(array $parameters): void {
		$profileId = ($parameters['profileId'] ?? null);
		if (is_numeric($profileId) === false) {
			throw new InvalidArgumentException(
				'The action ' . self::ID . ' needs a profileId naming the export profile to run.'
			);
		}

		try {
			$profile = $this->profiles->find(id: (int)$profileId);
		} catch (Throwable $e) {
			throw new InvalidArgumentException('Export profile ' . (string)$profileId . ' does not exist.');
		}

		if ($profile->isWholeSet() === false) {
			throw new InvalidArgumentException(
				'Export profile ' . (string)$profileId . ' is not a whole-set profile. '
				. 'A whole-set profile carries no filter and puts every register in scope.'
			);
		}
	}//end validateParameters()

	/**
	 * Write one object into its schema's file, or say why it was not written.
	 *
	 * @param ObjectEntity $object The object to export.
	 * @param array<string, mixed> $parameters The job's parameters.
	 * @param bool $commit False to rehearse, true to write.
	 * @param IUser|null $actor The user the job runs as.
	 *
	 * @return BulkActionResult What happened, or what would happen.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) One executor for the
	 *     rehearsal and the commit is the design property of the bulk engine.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/data-import-export/spec.md
	 */
	public function apply(ObjectEntity $object, array $parameters, bool $commit, ?IUser $actor = null): BulkActionResult {
		if ($actor === null) {
			return BulkActionResult::refused('not-authenticated');
		}

		try {
			$profile = $this->profiles->find(id: (int)($parameters['profileId'] ?? 0));
		} catch (Throwable $e) {
			return BulkActionResult::failed('The export profile could not be read: ' . $e->getMessage());
		}

		// `ObjectEntity` holds the register and the schema as strings, and every
		// collaborator here counts them. Coerced once, at the boundary, rather
		// than at each of the six call sites.
		$registerId = $this->identifier(value: $object->getRegister());
		$schemaId = $this->identifier(value: $object->getSchema());

		$schema = null;
		if ($schemaId !== null) {
			try {
				$schema = $this->schemaMapper->find($schemaId, _rbac: false, _multitenancy: false);
			} catch (Throwable $e) {
				$schema = null;
			}
		}

		$refusal = $this->rightService->refusalForUid(schema: $schema, userId: $actor->getUID());
		if ($refusal !== null) {
			$this->recorder->recordRefused(
				profile: ($profile->getName() ?? ''),
				rule: $refusal->getRule(),
				reason: $refusal->getMessage(),
				register: $registerId,
				schema: $schemaId,
				actorId: $actor->getUID()
			);

			return BulkActionResult::refused($refusal->getRule());
		}

		$line = $this->writer->csvLineFor(profile: $profile, object: $object, schema: $schema);
		if ($line === '') {
			return BulkActionResult::skipped('The object carries none of the fields the profile declares.');
		}

		if ($commit === false) {
			return BulkActionResult::applied('Would be written to ' . $this->filenameFor(profile: $profile, schemaId: $schemaId));
		}

		try {
			$this->append(profile: $profile, schemaId: $schemaId, actor: $actor, line: $line);
		} catch (Throwable $e) {
			return BulkActionResult::failed('The row could not be written: ' . $e->getMessage());
		}

		$this->recorder->recordCompleted(
			profile: ($profile->getName() ?? ''),
			rowCount: 1,
			format: 'csv',
			valueMode: ($profile->getValueMode() ?? ExportProfile::MODE_STORED),
			register: $registerId,
			schema: $schemaId,
			actorId: $actor->getUID()
		);

		return BulkActionResult::applied('Written to ' . $this->filenameFor(profile: $profile, schemaId: $schemaId));
	}//end apply()

	/**
	 * A register or schema identifier as an int, or null when there is none.
	 *
	 * @param string|null $value The identifier the object carries.
	 *
	 * @return int|null The identifier.
	 */
	private function identifier(?string $value): ?int {
		if ($value === null || is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end identifier()

	/**
	 * Append one line to the file this schema owns, creating it with its
	 * metadata line and header when it is not there yet.
	 *
	 * @param ExportProfile $profile  The profile.
	 * @param int|null      $schemaId The object's schema.
	 * @param IUser         $actor    The user the job runs as.
	 * @param string        $line     The CSV line, newline terminated.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the export folder is not a folder.
	 * @throws \OCP\Files\NotPermittedException When the Files folder refuses the write.
	 */
	private function append(ExportProfile $profile, ?int $schemaId, IUser $actor, string $line): void {
		$userFolder = $this->rootFolder->getUserFolder(userId: $actor->getUID());
		if ($userFolder->nodeExists(path: self::FOLDER) === false) {
			$userFolder->newFolder(path: self::FOLDER);
		}

		$folder = $userFolder->get(path: self::FOLDER);
		if ($folder instanceof Folder === false) {
			// Something that is not a folder sits where the export folder should
			// be. Refusing is the only safe answer: the alternative is writing
			// rows into whatever it is.
			throw new RuntimeException(
				'The path ' . self::FOLDER . ' in this user\'s files is not a folder, so the extract has nowhere to go.'
			);
		}

		$filename = $this->filenameFor(profile: $profile, schemaId: $schemaId);

		if ($folder->nodeExists(path: $filename) === false) {
			$folder->newFile(path: $filename, content: $this->writer->csvOpeningFor(profile: $profile));
		}

		$file = $folder->get(path: $filename);
		if ($file instanceof File === false) {
			return;
		}

		// Append rather than rewrite. A whole-set extract rewriting the file it
		// has already written would be quadratic in the row count, which is the
		// one shape a datawarehouse extract cannot afford.
		$handle = $file->fopen('a');
		if (is_resource($handle) === false) {
			return;
		}

		fwrite($handle, $line);
		fclose($handle);
	}//end append()

	/**
	 * The file one schema's rows go into.
	 *
	 * @param ExportProfile $profile  The profile.
	 * @param int|null      $schemaId The object's schema.
	 *
	 * @return string The filename.
	 */
	private function filenameFor(ExportProfile $profile, ?int $schemaId): string {
		$slug = preg_replace('/[^a-z0-9]+/i', '-', (string)($profile->getName() ?? 'export'));
		$slug = trim((string)$slug, '-');
		if ($slug === '') {
			$slug = 'export';
		}

		return strtolower($slug) . '_schema-' . (string)($schemaId ?? 'unknown') . '.csv';
	}//end filenameFor()
}//end class
