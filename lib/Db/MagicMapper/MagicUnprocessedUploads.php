<?php

/**
 * MagicUnprocessedUploads: keep base64 data URLs out of file columns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Psr\Log\LoggerInterface;

/**
 * Remove the base64 data URLs FilePropertyHandler did not process.
 *
 * Such a value would fail PostgreSQL with "invalid input syntax for type json". A file
 * property holding one becomes null; an array of files loses those items, and becomes
 * null when none is left. Each is logged as a warning.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicUnprocessedUploads {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Receives each value removed.
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * A property value without unprocessed uploads.
	 *
	 * @param array{name: int|string, config: mixed} $property The property's name and schema definition.
	 * @param mixed                                  $value    The value.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function without(array $property, mixed $value): mixed {
		if (($property['config']['type'] ?? 'string') === 'file' && $this->isDataUrl(value: $value) === true) {
			$this->logFile(propertyName: $property['name'], value: $value);
			return null;
		}

		if (is_array($value) === true && $this->holdsFiles(config: $property['config']) === true) {
			return $this->withoutItems(propertyName: $property['name'], items: $value);
		}

		return $value;
	}//end without()

	/**
	 * Whether a property is an array of files.
	 *
	 * @param mixed $config The property's schema definition.
	 *
	 * @return bool
	 */
	private function holdsFiles(mixed $config): bool {
		return ($config['type'] ?? 'string') === 'array' && ($config['items']['type'] ?? '') === 'file';
	}//end holdsFiles()

	/**
	 * Whether a value is an unprocessed base64 data URL.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isDataUrl(mixed $value): bool {
		return is_string($value) === true && strpos($value, 'data:') === 0;
	}//end isDataUrl()

	/**
	 * An array of files without its data URLs, or null when none is left.
	 *
	 * @param int|string $propertyName The property.
	 * @param array      $items        The files.
	 *
	 * @return array|null
	 */
	private function withoutItems(int|string $propertyName, array $items): ?array {
		$kept = [];
		foreach ($items as $item) {
			if ($this->isDataUrl(value: $item) === true) {
				$this->logItem(propertyName: $propertyName, item: $item);
				continue;
			}

			$kept[] = $item;
		}

		if ($kept === []) {
			return null;
		}

		return $kept;
	}//end withoutItems()

	/**
	 * Log a file property that is set to null.
	 *
	 * @param int|string $propertyName The property.
	 * @param string     $value        The data URL.
	 *
	 * @return void
	 */
	private function logFile(int|string $propertyName, string $value): void {
		$this->logger->warning(
			message: '[MagicMapper] File property contains unprocessed base64 data URL - setting to null to prevent DB error',
			context: ['propertyName' => $propertyName, 'valueLength' => strlen($value)]
		);
	}//end logFile()

	/**
	 * Log an item of an array of files that is skipped.
	 *
	 * @param int|string $propertyName The property.
	 * @param string     $item         The data URL.
	 *
	 * @return void
	 */
	private function logItem(int|string $propertyName, string $item): void {
		$this->logger->warning(
			message: '[MagicMapper] Array file item contains unprocessed base64 data URL - skipping item',
			context: ['propertyName' => $propertyName, 'valueLength' => strlen($item)]
		);
	}//end logItem()
}//end class
