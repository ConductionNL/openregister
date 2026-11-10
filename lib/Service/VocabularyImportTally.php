<?php

/**
 * VocabularyImportTally — running counts of one vocabulary import.
 *
 * Collects the per-concept upsert outcomes of {@see VocabularyImportService}
 * and the uri => uuid map the relation and deprecation passes need.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Counts created/updated/unchanged concepts.
 */
class VocabularyImportTally {
	/**
	 * Outcome => count.
	 *
	 * @var array{created: int, updated: int, unchanged: int}
	 */
	private array $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

	/**
	 * Every imported concept uri, in import order.
	 *
	 * @var array<string>
	 */
	public array $seenUris = [];

	/**
	 * Uri => uuid of every imported concept.
	 *
	 * @var array<string,string>
	 */
	public array $uuidByUri = [];

	/**
	 * Record one concept's upsert outcome.
	 *
	 * @param string $uri The concept's source uri.
	 * @param array{status: string, uuid: string} $result The upsert outcome.
	 *
	 * @return void
	 */
	public function record(string $uri, array $result): void {
		$this->seenUris[] = $uri;
		$this->uuidByUri[$uri] = $result['uuid'];
		$status = $result['status'];
		if (isset($this->counts[$status]) === false) {
			$status = 'unchanged';
		}

		$this->counts[$status]++;
	}//end record()

	/**
	 * The import report.
	 *
	 * @param string $schemeUri The scheme's source uri.
	 * @param int $deprecated Count of concepts newly marked deprecated.
	 *
	 * @return array{scheme: string, created: int, updated: int, unchanged: int, deprecated: int}
	 */
	public function report(string $schemeUri, int $deprecated): array {
		return array_merge(['scheme' => $schemeUri], $this->counts, ['deprecated' => $deprecated]);
	}//end report()
}//end class
