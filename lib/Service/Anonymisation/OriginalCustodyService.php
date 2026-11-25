<?php

/**
 * OpenRegister OriginalCustodyService
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Kernel\Contracts\Files\RedactionSettingsInterface;
use DateInterval;
use DateTime;
use OCA\OpenRegister\Db\OriginalCustody;
use OCA\OpenRegister\Db\OriginalCustodyMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where the unredacted original of a redacted copy lives and when it goes.
 *
 * Publishing a redacted copy schedules its original's deletion at the
 * publication moment plus `anonymisation.originalRetention`; with the
 * default `keep` nothing is scheduled and the original is the record. The
 * statement names both files, the retention and the deletion date.
 *
 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
 */
class OriginalCustodyService {

	/**
	 * The suffix an anonymised copy carries before its extension.
	 */
	public const COPY_SUFFIX = '_anonymized';

	/**
	 * The status of an original no deletion is scheduled for.
	 */
	public const STATUS_KEPT = 'kept';

	/**
	 * Constructor.
	 *
	 * @param OriginalCustodyMapper $custody    The custody rows.
	 * @param RedactionSettingsInterface $settings   Reads `anonymisation.originalRetention`.
	 * @param IRootFolder           $rootFolder Resolves the files a row names.
	 * @param ITimeFactory          $time       The clock.
	 * @param LoggerInterface       $logger     Logs a copy whose original cannot be found.
	 */
	public function __construct(
		private readonly OriginalCustodyMapper $custody,
		private readonly RedactionSettingsInterface $settings,
		private readonly IRootFolder $rootFolder,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Schedule the original's deletion now that its redacted copy is published.
	 *
	 * Nothing is written for the retention `keep`, for a file that is not an
	 * anonymised copy, or when the original cannot be found beside it.
	 *
	 * @param File   $copy       The published redacted copy.
	 * @param string $objectUuid The object the files belong to.
	 *
	 * @return OriginalCustody|null The scheduled row, or null when nothing was scheduled.
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public function scheduleOnPublication(File $copy, string $objectUuid): ?OriginalCustody {
		$retention = $this->settings->originalRetention();
		if ($retention === RedactionSettingsInterface::ORIGINAL_RETENTION_KEEP || self::isCopy(name: (string) $copy->getName()) === false) {
			return null;
		}

		$original = $this->sibling(file: $copy, name: (string) self::originalNameFor(copyName: (string) $copy->getName()));
		if ($original === null) {
			$this->logger->warning(
				message: '[OriginalCustodyService] The original of a published redacted copy was not found beside it; nothing is scheduled',
				context: ['copyFileId' => (int) $copy->getId()]
			);
			return null;
		}

		$publishedAt = DateTime::createFromInterface($this->time->getDateTime());
		$row         = new OriginalCustody();
		$row->setOriginalFileId((int) $original->getId());
		$row->setCopyFileId((int) $copy->getId());
		$row->setObjectUuid($objectUuid);
		$row->setRetention($retention);
		$row->setPublishedAt($publishedAt);
		$row->setDeleteAfter((clone $publishedAt)->add(new DateInterval($retention)));
		$row->setStatus(OriginalCustody::STATUS_SCHEDULED);

		return $this->custody->insert(entity: $row);
	}//end scheduleOnPublication()

	/**
	 * The custody statement of a file, given as the original or as the copy.
	 *
	 * @param File $file The file asked about.
	 *
	 * @return array{
	 *     original: array{fileId: int, path: string}|null,
	 *     redactedCopy: array{fileId: int, path: string}|null,
	 *     retention: string,
	 *     deletionDate: string|null,
	 *     status: string,
	 *     heldBecause: string|null
	 * }
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public function statement(File $file): array {
		$row = $this->custody->findLatestForFile(fileId: (int) $file->getId());
		if ($row !== null) {
			return [
				'original' => $this->describe(node: $this->byId(fileId: $row->getOriginalFileId())),
				'redactedCopy' => $this->describe(node: $this->byId(fileId: $row->getCopyFileId())),
				'retention' => $row->getRetention(),
				'deletionDate' => $row->getDeleteAfter()?->format('Y-m-d'),
				'status' => $row->getStatus(),
				'heldBecause' => $row->getOutcomeReason(),
			];
		}

		[$original, $copy] = $this->pair(file: $file);

		return [
			'original' => $this->describe(node: $original),
			'redactedCopy' => $this->describe(node: $copy),
			'retention' => $this->settings->originalRetention(),
			'deletionDate' => null,
			'status' => self::STATUS_KEPT,
			'heldBecause' => null,
		];
	}//end statement()

	/**
	 * Whether a file name is an anonymised copy's.
	 *
	 * @param string $name The file name.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public static function isCopy(string $name): bool {
		return self::originalNameFor(copyName: $name) !== null;
	}//end isCopy()

	/**
	 * The original's name for an anonymised copy's name, or null when the
	 * name is not a copy's (`besluit_anonymized.pdf` → `besluit.pdf`).
	 *
	 * @param string $copyName The copy's name.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public static function originalNameFor(string $copyName): ?string {
		$extension = pathinfo($copyName, PATHINFO_EXTENSION);
		$base      = pathinfo($copyName, PATHINFO_FILENAME);
		if (str_ends_with($base, self::COPY_SUFFIX) === false || $base === self::COPY_SUFFIX) {
			return null;
		}

		$original = substr($base, 0, -strlen(self::COPY_SUFFIX));
		if ($extension !== '') {
			$original .= '.' . $extension;
		}

		return $original;
	}//end originalNameFor()

	/**
	 * The copy's name for an original's name (`besluit.pdf` → `besluit_anonymized.pdf`).
	 *
	 * @param string $originalName The original's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/redaction-release-safeguards/specs/redaction-output-guarantee/spec.md#requirement-the-unredacted-originals-custody-is-stated-and-its-deletion-scheduled-req-rrs-005
	 */
	public static function copyNameFor(string $originalName): string {
		$extension = pathinfo($originalName, PATHINFO_EXTENSION);
		$copy      = pathinfo($originalName, PATHINFO_FILENAME) . self::COPY_SUFFIX;
		if ($extension !== '') {
			$copy .= '.' . $extension;
		}

		return $copy;
	}//end copyNameFor()

	/**
	 * The original and the copy of a file without a custody row, found by name beside it.
	 *
	 * @param File $file The original or the copy.
	 *
	 * @return array{0: Node|null, 1: Node|null}
	 */
	private function pair(File $file): array {
		$name         = (string) $file->getName();
		$originalName = self::originalNameFor(copyName: $name);
		if ($originalName !== null) {
			return [$this->sibling(file: $file, name: $originalName), $file];
		}

		return [$file, $this->sibling(file: $file, name: self::copyNameFor(originalName: $name))];
	}//end pair()

	/**
	 * A file in the same folder, or null.
	 *
	 * @param File   $file The file whose folder is searched.
	 * @param string $name The name looked for.
	 *
	 * @return Node|null
	 */
	private function sibling(File $file, string $name): ?Node {
		try {
			$parent = $file->getParent();
			if ($parent->nodeExists($name) === false) {
				return null;
			}

			return $parent->get($name);
		} catch (Throwable $unreachable) {
			return null;
		}
	}//end sibling()

	/**
	 * A file by id, or null when it is gone.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return Node|null
	 */
	private function byId(int $fileId): ?Node {
		try {
			return ($this->rootFolder->getById($fileId)[0] ?? null);
		} catch (Throwable $unreachable) {
			return null;
		}
	}//end byId()

	/**
	 * A file's id and path, or null.
	 *
	 * @param Node|null $node The file.
	 *
	 * @return array{fileId: int, path: string}|null
	 */
	private function describe(?Node $node): ?array {
		if ($node === null) {
			return null;
		}

		return ['fileId' => (int) $node->getId(), 'path' => (string) $node->getPath()];
	}//end describe()
}//end class
