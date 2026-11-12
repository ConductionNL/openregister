<?php

/**
 * A migration pack mapping error as an import summary row.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
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

namespace OCA\OpenRegister\Service\Import;

/**
 * Formats one mapping error for the `errors` list of an import summary.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class MappingErrorRow {

	/**
	 * The summary row of a mapping error.
	 *
	 * @param array<string, mixed> $error The error, as MappingEngine::mapRow() reports it.
	 *
	 * @return array<string, mixed> The summary row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function format(array $error): array {
		$label = 'source column "' . $error['source'] . '"';
		if ($error['transform'] !== null) {
			$label .= ' (transform: ' . $error['transform'] . ')';
		}

		return [
			'row' => $error['row'],
			'field' => $error['target'] ?? $error['source'],
			'error' => '[migration-pack] ' . $error['message'] . ' — ' . $label,
			'type' => 'MigrationPackMappingError',
			'original_value' => $error['source'],
		];
	}//end format()
}//end class
