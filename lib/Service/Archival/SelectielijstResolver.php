<?php

/**
 * Which selectielijst row decides, once a list has more than one revision.
 *
 * 🔴 EXTRACTED FROM `RetentionService`, WHICH WAS AT ITS SIZE CEILING AND WAS
 * ABOUT TO ANSWER A SECOND QUESTION. Looking a category up used to be one
 * store read and the first row back. Now that a selectielijst is imported WITH
 * A VERSION, one category can hold a row per revision, and something has to say
 * which of them applies. That is a different job from deciding when a record
 * may be destroyed, and it belongs beside {@see SelectielijstImportService},
 * which writes the versions this reads.
 *
 * `RetentionService::lookupSelectielijstEntry()` still exists and delegates
 * here, the same way `calculateArchiveActionDate()` delegates to
 * {@see ArchiveActionDateCalculator}: callers and the spec both name it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use DateTime;
use Exception;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Db\MagicMapper\MagicListRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterSchema;
use Psr\Log\LoggerInterface;

/**
 * Resolves a selectielijst category to the row of the version in use.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Reading a register object needs the
 *              register, the schema and the settings that name both.
 */
class SelectielijstResolver {

	/**
	 * How many revisions of one selectielijst category a lookup will consider.
	 */
	private const SELECTION_LIST_ROW_CAP = 50;

	/**
	 * Constructor.
	 *
	 * @param SelectielijstStorage $storage Names the register, the schema and the version in use, and reads the stored rows.
	 * @param LoggerInterface      $logger  Reports a lookup that failed.
	 */
	public function __construct(
		private readonly SelectielijstStorage $storage,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the provenance of the entry that was applied: which version, read when.
	 *
	 * @param ObjectEntity $entry The selectielijst entry that was applied.
	 *
	 * @return array<string, string> The provenance keys, possibly empty.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function provenanceOf(ObjectEntity $entry): array {
		return $this->selectielijstProvenance(entry: $entry);
	}//end provenanceOf()

	/**
	 * The entry for a category, as an entity.
	 *
	 * @param string $category The selectielijst category code.
	 *
	 * @return ObjectEntity|null The entry, or null when unconfigured or absent.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function entryFor(string $category): ?ObjectEntity {
		return $this->findSelectielijstEntry(category: $category);
	}//end entryFor()

	/**
	 * Look up a selectielijst entry by categorie code.
	 *
	 * @param string $category The selectielijst category code (e.g., B1, A1)
	 *
	 * @return array|null The selectielijst entry data or null if not found
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	public function lookupSelectielijstEntry(string $category): ?array {
		$entry = $this->findSelectielijstEntry(category: $category);

		if ($entry === null) {
			return null;
		}

		return $entry->getObject();
	}//end lookupSelectielijstEntry()

	/**
	 * Find the selectielijst entry ENTITY for a categorie code.
	 *
	 * Split out from lookupSelectielijstEntry because `getObject()` drops the
	 * `@self` envelope, and the envelope is where the row's own version and
	 * update timestamp live. Gap B1 in openspec/changes/archival-conformance:
	 * without them a disposal decision can say WHICH list it came from but not
	 * WHICH VERSION OF THAT LIST, and the same category carries different
	 * retention periods across selectielijst revisions. Five years on, that is
	 * the difference between a decision you can justify and one you cannot.
	 *
	 * @param string $category The selectielijst category code (e.g., B1, A1)
	 *
	 * @return ObjectEntity|null The entry, or null when unconfigured or absent
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function findSelectielijstEntry(string $category): ?ObjectEntity {
		$settings = $this->storage->settings->getArchivalSettingsOnly();

		$registerId = $settings['selectielijstRegister'] ?? null;
		$schemaId = $settings['selectielijstSchema'] ?? null;

		if ($registerId === null || $schemaId === null) {
			return null;
		}

		try {
			$catalog = $this->storage->catalog;
			$table = new RegisterSchema(register: $catalog->registers()->find((int)$registerId), schema: $catalog->schemas()->find((int)$schemaId));
			$results = $this->rowsFor(category: $category, table: $table);

			return $this->pickApplicableRow(results: $results);
		} catch (Exception $e) {
			$this->logger->warning('[RetentionService] Failed to lookup selectielijst entry for ' . $category, ['exception' => $e]);
			return null;
		}
	}//end findSelectielijstEntry()

	/**
	 * Every stored row of a category, up to the cap.
	 *
	 * 🔴 NOT `limit: 1`. Since a selectielijst is imported with a VERSION, one
	 * category can have a row per revision, and taking the first row the store
	 * returns means a nomination silently resolves against whichever revision
	 * happened to sort first. The version in use is selected in the READING
	 * (pickApplicableRow), because a filter key the search handler does not
	 * recognise becomes `1 = 0` rather than an error: a mis-spelled version
	 * filter would report "no such category" on an instance that holds it, and
	 * every nomination would fall back to the schema default without saying so.
	 *
	 * @param string         $category The selectielijst category code.
	 * @param RegisterSchema $table    The selectielijst register and schema.
	 *
	 * @return array<int, ObjectEntity> The rows.
	 *
	 * @throws Exception When the register, the schema or the rows cannot be read.
	 */
	private function rowsFor(string $category, RegisterSchema $table): array {
		return $this->storage->catalog->tables()->findAllListed(
			request: new MagicListRequest(
				table: $table,
				query: new ListQuery(limit: self::SELECTION_LIST_ROW_CAP, filters: ['object->categorie' => $category])
			)
		);
	}//end rowsFor()

	/**
	 * Choose which revision of a category's row applies.
	 *
	 * The instance names the version it applies, under `selectielijstVersion`.
	 * When it names one, only a row of that version may decide; a category
	 * present in the store but not in the applied version resolves to nothing,
	 * which is the honest answer and reaches the reader as
	 * `selection_list_not_consulted` rather than as a plausible wrong period.
	 *
	 * When the instance names no version, the first row is taken, which is
	 * exactly the behaviour before versions existed.
	 *
	 * @param array<int, ObjectEntity> $results The rows for one category.
	 *
	 * @return ObjectEntity|null The applicable row.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	private function pickApplicableRow(array $results): ?ObjectEntity {
		if (empty($results) === true) {
			return null;
		}

		$settings = $this->storage->settings->getArchivalSettingsOnly();
		$applied = ArchivalText::fromString(value: ($settings['selectielijstVersion'] ?? null));
		if ($applied === null) {
			return $results[0];
		}

		return $this->rowOfVersion(results: $results, applied: $applied);
	}//end pickApplicableRow()

	/**
	 * The first row of the applied version.
	 *
	 * @param array<int, ObjectEntity> $results The rows for one category.
	 * @param string                   $applied The version in use, trimmed.
	 *
	 * @return ObjectEntity|null The row, or null (and a warning) when no row has that version.
	 */
	private function rowOfVersion(array $results, string $applied): ?ObjectEntity {
		foreach ($results as $row) {
			$data = ($row->getObject() ?? []);
			if (is_array($data) === true && $this->declaredVersion(data: $data) === $applied) {
				return $row;
			}
		}

		$this->logger->warning(
			'[RetentionService] The selectielijst version in use is "' . $applied
			. '" and no row of that version exists for this category, so nothing was applied'
		);

		return null;
	}//end rowOfVersion()

	/**
	 * The version a row declares, trimmed; '' when it declares none.
	 *
	 * @param array<string, mixed> $data The row's data.
	 *
	 * @return string The version.
	 */
	private function declaredVersion(array $data): string {
		return trim((string)($data[SelectielijstImportService::VERSION_KEY] ?? ($data['versie'] ?? ($data['version'] ?? ''))));
	}//end declaredVersion()

	/**
	 * Read the provenance of a selectielijst entry: which version, read when.
	 *
	 * Three sources, in the order an auditor would trust them:
	 *
	 *  1. a `versie` or `version` the row itself declares, which is the list
	 *     publisher's own numbering and the only one that means anything
	 *     outside this install;
	 *  2. failing that, the entry object's own `@self.version`, which says
	 *     which revision of the stored row was read even when the publisher
	 *     numbered nothing;
	 *  3. the moment it was read, always, because a version alone does not say
	 *     whether the decision predates a later revision.
	 *
	 * Returns an empty array rather than nulls when nothing can be
	 * established: an absent key is honest, and a key holding null reads as a
	 * recorded answer of "no version".
	 *
	 * @param ObjectEntity $entry The selectielijst entry that was applied
	 *
	 * @return array<string, string> The provenance keys, possibly empty
	 *
	 * @spec openspec/specs/archival-destruction-workflow/spec.md
	 */
	private function selectielijstProvenance(ObjectEntity $entry): array {
		$provenance = ['selectionListConsultedAt' => (new DateTime())->format('c')];

		$version = $this->publishedVersionOf(entry: $entry);
		if ($version === null) {
			$version = ArchivalText::fromStringOrInt(value: $entry->getVersion());
		}

		if ($version !== null) {
			$provenance['selectionListVersion'] = $version;
		}

		return $provenance;
	}//end selectielijstProvenance()

	/**
	 * The `versie` or `version` the row itself declares: the list publisher's own numbering.
	 *
	 * @param ObjectEntity $entry The selectielijst entry.
	 *
	 * @return string|null The version, or null when the row declares none.
	 */
	private function publishedVersionOf(ObjectEntity $entry): ?string {
		$data = $entry->getObject();
		if (is_array($data) === false) {
			return null;
		}

		return ArchivalText::fromStringOrInt(value: ($data['versie'] ?? ($data['version'] ?? null)));
	}//end publishedVersionOf()
}//end class
