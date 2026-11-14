<?php

/**
 * OpenRegister TextGroupFinder
 *
 * Reads the stored text signatures of a set of files and groups the near-identical ones.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Quality
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

use InvalidArgumentException;
use OCA\OpenRegister\Db\TextSignatureMapper;
use OCP\IAppConfig;

/**
 * The near-duplicate groups of a set of files, and the files that could not be compared.
 *
 * Read rights are not checked here: the caller hands in only files it has
 * already resolved as readable, before anything is compared.
 *
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
 */
class TextGroupFinder {

	/**
	 * The app config key of the default threshold.
	 *
	 * @var string
	 */
	public const THRESHOLD_SETTING = 'duplicates.textThreshold';

	/**
	 * Constructor.
	 *
	 * @param TextSignatureMapper $signatures The stored signatures.
	 * @param IAppConfig          $appConfig  The app settings (the default threshold).
	 * @param TextGroups          $groups     The grouping.
	 */
	public function __construct(
		private readonly TextSignatureMapper $signatures,
		private readonly IAppConfig $appConfig,
		private readonly TextGroups $groups = new TextGroups(),
	) {
	}//end __construct()

	/**
	 * Group the given files by their text.
	 *
	 * A file without a stored signature row is `notExtracted`, one whose text
	 * held no words `noText`, and one too short to sign `tooShort`. The
	 * representative is the file created first; file ids grow in creation
	 * order, so it is the lowest file id of the group.
	 *
	 * @param list<int>  $fileIds   The files, already limited to those the caller may read.
	 * @param float|null $threshold The threshold, or null for the `duplicates.textThreshold` setting (default 0.9).
	 *
	 * @return array{
	 *     groups: list<array{representative: int, members: list<array{fileId: int, similarity: float, identicalText: bool}>}>,
	 *     notCompared: list<array{fileId: int, reason: string}>
	 * } The groups and the files not compared.
	 *
	 * @throws InvalidArgumentException When the threshold lies outside 0.5 to 1.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-near-identical-documents-are-grouped-by-their-text-req-ndt-002
	 */
	public function find(array $fileIds, ?float $threshold=null): array {
		$threshold = $this->groups->checkedThreshold(threshold: ($threshold ?? $this->configuredThreshold()));
		$fileIds   = array_values(array_unique(array_map('intval', $fileIds)));
		sort($fileIds);

		$rows        = $this->signatures->findByFileIds(fileIds: $fileIds);
		$signed      = [];
		$notCompared = [];
		foreach ($fileIds as $fileId) {
			$row    = ($rows[$fileId] ?? null);
			$values = $row?->getSignatureValues();
			if ($values !== null) {
				$signed[$fileId] = ['signature' => $values, 'textSha256' => (string)$row->getTextSha256(), 'created' => null];
				continue;
			}

			$notCompared[] = ['fileId' => $fileId, 'reason' => self::reason(shingleCount: $row?->getShingleCount())];
		}

		return ['groups' => $this->groups->group(files: $signed, threshold: $threshold), 'notCompared' => $notCompared];
	}//end find()

	/**
	 * Why a file without a usable signature was not compared.
	 *
	 * @param int|null $shingleCount The stored shingle count, or null when there is no row.
	 *
	 * @return string notExtracted, noText or tooShort.
	 */
	private static function reason(?int $shingleCount): string {
		if ($shingleCount === null) {
			return 'notExtracted';
		}

		if ($shingleCount === 0) {
			return 'noText';
		}

		return 'tooShort';
	}//end reason()

	/**
	 * The threshold from the app settings, or the default when unset or not a number.
	 *
	 * @return float The threshold, not yet checked against the range.
	 */
	private function configuredThreshold(): float {
		$value = trim($this->appConfig->getValueString('openregister', self::THRESHOLD_SETTING, ''));
		if (is_numeric($value) === false) {
			return TextGroups::DEFAULT_THRESHOLD;
		}

		return (float)$value;
	}//end configuredThreshold()
}//end class
