<?php

/**
 * The checks that tie a preview to the source file it is of.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
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
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ImportPreview;

/**
 * Pure checks on a preview's source file: that one is named, and that a
 * commit names the same file the preview decided.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ImportSourceCheck {

	/**
	 * The source file a preview is of, which must exist.
	 *
	 * @param array<string, mixed> $params The preview parameters.
	 *
	 * @return string The file path.
	 *
	 * @throws InvalidArgumentException When no readable file is named.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function requireFile(array $params): string {
		$filePath = (string)($params['filePath'] ?? '');

		if ($filePath === '' || is_file($filePath) === false) {
			throw new InvalidArgumentException('A source file is required to preview an import.');
		}

		return $filePath;
	}//end requireFile()

	/**
	 * Refuse a commit that does not name the file the preview was of.
	 *
	 * @param ImportPreview $preview The preview.
	 * @param string|null $sourceHash The hash the caller offered.
	 *
	 * @return void
	 *
	 * @throws ImportPreviewRefusedException When the hash is missing or differs.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function assertSameSource(ImportPreview $preview, ?string $sourceHash): void {
		if ($sourceHash === null || $sourceHash === '') {
			throw new ImportPreviewRefusedException(
				'A commit must name the source file it is committing. Send the file again, or pass its sha256 as sourceHash.'
			);
		}

		if (hash_equals((string)$preview->getSourceHash(), $sourceHash) === false) {
			throw new ImportPreviewRefusedException(
				'The source file changed since it was previewed, so the preview no longer describes it. '
				.'Preview the new file and commit that.'
			);
		}
	}//end assertSameSource()
}//end class
