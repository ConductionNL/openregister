<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A publication window on the file metadata row.
 *
 * Several apps grew their own `document` object purely to hold a publication
 * window over an attached file. Everything else such an object carried is
 * already on the file or on the object it hangs from: the filename and mime
 * type are the file, `description` / `category` / `labels` are the OR-side
 * metadata row, the owning publication is the folder the file lives in, and the
 * file's text is already extracted into `openregister_chunks` and searchable.
 *
 * The window was the one thing with nowhere to live. `publishFile()` is a
 * boolean: it creates a public share or it does not. So an attachment could not
 * be depublished on a date independently of the record it belongs to, which is
 * exactly what a WOO bijlage needs.
 *
 * Two nullable datetimes, no row rewritten, both guarded so the step is
 * re-runnable. `depublished` is indexed because the sweep that stops serving an
 * expired file scans on it.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-file-carries-its-own-publication-window-req-fpw-101
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `published` and `depublished` to the file metadata row.
 *
 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-file-carries-its-own-publication-window-req-fpw-101
 */
class Version1Date20260903090000 extends SimpleMigrationStep {

	/**
	 * The OpenRegister file metadata table.
	 */
	private const TABLE_FILES = 'openregister_files';

	/**
	 * Add the window columns and the expiry index, idempotently.
	 *
	 * @param IOutput                   $output        Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface fixes the signature.
	 *
	 * @spec openspec/changes/file-publication-window/specs/file-publication-window/spec.md#requirement-a-file-carries-its-own-publication-window-req-fpw-101
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE_FILES) === false) {
			$output->warning(message: 'openregister_files is absent; skipping the publication window');
			return null;
		}

		$table = $schema->getTable(self::TABLE_FILES);
		$added = [];

		if ($table->hasColumn('published') === false) {
			$table->addColumn(
				'published',
				Types::DATETIME_MUTABLE,
				[
					'notnull' => false,
					'comment' => 'When this file becomes public. Null means it has never been published.',
				]
			);
			$added[] = 'published';
		}

		if ($table->hasColumn('depublished') === false) {
			$table->addColumn(
				'depublished',
				Types::DATETIME_MUTABLE,
				[
					'notnull' => false,
					'comment' => 'When this file stops being public. Null means no end date.',
				]
			);
			$added[] = 'depublished';
		}

		if ($table->hasIndex('openregister_file_depub_idx') === false) {
			$table->addIndex(['depublished'], 'openregister_file_depub_idx');
			$added[] = 'index:depublished';
		}

		if ($added === []) {
			return null;
		}

		$output->info(message: 'File publication window added: ' . implode(', ', $added));

		return $schema;
	}//end changeSchema()
}//end class
