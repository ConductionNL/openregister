<?php

/**
 * OpenRegister ObjectTextSimilarity
 *
 * Scores the `text` match rule of x-openregister-dedup: how alike two objects
 * are by the text signatures of the files attached to them.
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
 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-a-schema-can-declare-text-similarity-as-a-match-rule-req-ndt-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

use OCA\OpenRegister\Db\TextSignatureMapper;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\Quality\TextSignature;

/**
 * The highest signature similarity between the attached files of two objects.
 */
final class ObjectTextSimilarity {

	/**
	 * The match rule method this scores.
	 */
	public const METHOD = 'text';

	/**
	 * The field name a scored text rule reports in `matchedOn`.
	 */
	public const MATCHED_ON = 'text';

	/**
	 * Constructor.
	 *
	 * @param FileMapperInterface $files      Which files are attached to an object.
	 * @param TextSignatureMapper $signatures The stored signatures.
	 * @param TextSignature       $signer     Estimates the similarity of two signatures.
	 */
	public function __construct(
		private readonly FileMapperInterface $files,
		private readonly TextSignatureMapper $signatures,
		private readonly TextSignature $signer=new TextSignature(),
	) {
	}//end __construct()

	/**
	 * Whether a match rule is a text rule.
	 *
	 * @param mixed $rule The rule.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-a-schema-can-declare-text-similarity-as-a-match-rule-req-ndt-003
	 */
	public static function isTextRule(mixed $rule): bool {
		return is_array($rule) === true && ($rule['method'] ?? null) === self::METHOD;
	}//end isTextRule()

	/**
	 * The signatures of the attached files of each object, in two queries plus one per thousand files.
	 *
	 * An object without attached files, or whose files carry no signature, maps to an empty list.
	 *
	 * @param list<string> $uuids The objects.
	 *
	 * @return array<string, list<list<int>>> Uuid => the signatures of its files.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-a-schema-can-declare-text-similarity-as-a-match-rule-req-ndt-003
	 */
	public function signaturesOf(array $uuids): array {
		$uuids = array_values(array_unique(array_filter($uuids, static fn (string $uuid): bool => $uuid !== '')));
		if ($uuids === []) {
			return [];
		}

		$fileIdsByObject = $this->files->getFileIdsForObjects(uuids: $uuids);
		$allFileIds      = [];
		foreach ($fileIdsByObject as $fileIds) {
			array_push($allFileIds, ...array_map('intval', $fileIds));
		}

		$rows = [];
		if ($allFileIds !== []) {
			$rows = $this->signatures->findByFileIds(fileIds: $allFileIds);
		}

		$signatures = [];
		foreach ($uuids as $uuid) {
			$signatures[$uuid] = [];
			foreach (($fileIdsByObject[$uuid] ?? []) as $fileId) {
				$values = ($rows[(int) $fileId] ?? null)?->getSignatureValues();
				if ($values !== null) {
					$signatures[$uuid][] = $values;
				}
			}
		}

		return $signatures;
	}//end signaturesOf()

	/**
	 * The highest estimated similarity between any file of one object and any file of the other.
	 *
	 * @param list<list<int>> $first  The signatures of the first object's files.
	 * @param list<list<int>> $second The signatures of the second object's files.
	 *
	 * @return float 0 to 1; 0 when either object has no signed file.
	 *
	 * @spec openspec/changes/near-duplicate-documents-by-text/specs/duplicate-detection/spec.md#requirement-a-schema-can-declare-text-similarity-as-a-match-rule-req-ndt-003
	 */
	public function similarity(array $first, array $second): float {
		$best = 0.0;
		foreach ($first as $left) {
			foreach ($second as $right) {
				$best = max($best, $this->signer->estimate(first: $left, second: $right));
			}
		}

		return $best;
	}//end similarity()
}//end class
