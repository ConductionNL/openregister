<?php

/**
 * SelectielijstImportServiceInterface: the part of SelectielijstImportService that the selectielijst controller uses.
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

use InvalidArgumentException;

/**
 * The methods of SelectielijstImportService that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SelectielijstImportServiceInterface {

	/**
	 * Read a selectielijst out of the file an archivist was handed.
	 *
	 * CSV with a header row, or JSON holding a list of objects. A row without a
	 * category is refused rather than imported as an anonymous rule: a rule
	 * nothing can match is a rule nobody notices is broken.
	 *
	 * @param string $contents The file contents.
	 * @param string $filename The file name, which says which format to expect.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws InvalidArgumentException When the format is unknown, unparseable, empty or too large.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function parse(string $contents, string $filename): array;

	/**
	 * Store a parsed selectielijst as a named version.
	 *
	 * @param array<int, array<string, mixed>> $rows    The parsed rows.
	 * @param string                           $version The version these rows are.
	 * @param string|null                      $source  Where the file came from, recorded on each row.
	 *
	 * @return array{version: string, imported: int, failed: int} What was stored.
	 *
	 * @throws InvalidArgumentException When no selectielijst register and schema are configured,
	 *                                  or the version is blank, or it is already present.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function import(array $rows, string $version, ?string $source = null): array;

	/**
	 * Every selectielijst version stored here, with how many rows each holds.
	 *
	 * Rows written before versioning existed carry no version and are reported
	 * under `unversioned`, which is the honest answer and the one that tells an
	 * archivist there is a list here nobody named.
	 *
	 * @return array<string, int> Version mapped to its row count.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function versions(): array;

	/**
	 * Compare two stored versions, category by category.
	 *
	 * 🔴 THE DIFF SAYS WHAT A CHANGE WOULD DO, not merely that there is one. A
	 * bewaartermijn moving from P7Y to P10Y is three more years on every record
	 * nominated under that category, and an archivist switching lists has to see
	 * that before switching rather than after.
	 *
	 * @param string $from The version in use.
	 * @param string $to   The version being considered.
	 *
	 * @return array{from: string, to: string, added: array<int, string>, removed: array<int, string>,
	 *               changed: array<int, array<string, mixed>>} The comparison.
	 *
	 * @throws InvalidArgumentException When either version holds no rows.
	 *
	 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
	 */
	public function diff(string $from, string $to): array;
}//end interface
