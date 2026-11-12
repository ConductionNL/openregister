<?php

/**
 * Counts and names the schemas an import rejected.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Configuration;

use Psr\Log\LoggerInterface;

/**
 * A rejected schema is contained (its siblings still import) but never silent.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class RejectedSchemaReport
{

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Count and name a schema pass 1 rejected.
	 *
	 * It is named in the result, so the operator sees it here rather than three
	 * layers downstream.
	 *
	 * @param ImportRun       $run       The import.
	 * @param ImportComponent $rejection The schema component and the exception that rejected it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function record(ImportRun $run, ImportComponent $rejection): void {
		$error = $rejection->error;
		$run->result['skipped']['schemas']++;
		$run->result['failed']['schemas'][] = [
			'key' => (string)$rejection->key,
			'slug' => (string)($rejection->data['slug'] ?? $rejection->key),
			'error' => $error->getMessage(),
		];
		$context = ['file' => __FILE__, 'line' => __LINE__, 'schemaKey' => $rejection->key];
		$context += ['error' => $error->getMessage(), 'trace' => $error->getTraceAsString()];
		$this->logger->error(message: ImportLogTag::PREFIX.'Failed to create schema (Pass 1)', context: $context);
	}//end record()

	/**
	 * Warn once, naming every schema pass 1 rejected.
	 *
	 * The per-schema `error` lines are easy to miss in a log that is almost all
	 * `debug`; this is the line that says the import was PARTIAL.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function warn(ImportRun $run): void {
		$failed = $run->result['failed']['schemas'];
		if ($failed === []) {
			return;
		}

		$this->logger->warning(
			message: $this->partialImportMessage(run: $run),
			context: ['file' => __FILE__, 'line' => __LINE__, 'appId' => $run->appId, 'failedSchemas' => $failed]
		);
	}//end warn()

	/**
	 * The line that names what a partial import left out.
	 *
	 * @param ImportRun $run The import.
	 *
	 * @return string The message.
	 */
	private function partialImportMessage(ImportRun $run): string {
		$rejectedSlugs = array_column($run->result['failed']['schemas'], 'slug');

		return sprintf(
			ImportLogTag::PREFIX.'PARTIAL IMPORT: %d of %d schema(s) were rejected and are NOT '
			. 'linked to any register in this import: %s. Registers declaring them were '
			. 'created without those schema references.',
			count($rejectedSlugs),
			count($run->data['components']['schemas']),
			implode(', ', $rejectedSlugs)
		);
	}//end partialImportMessage()
}//end class
