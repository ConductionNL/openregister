<?php

/**
 * OpenRegister ObjectSourcePageReader
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\ObjectSourceRead;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads one page from an object-source provider and shapes it like a native search result.
 *
 * A missing or disabled provider, or a missing register context, yields an empty
 * list, never the database.
 *
 * @spec openspec/specs/dbal-virtual-registers/spec.md
 */
final class ObjectSourcePageReader {

	/**
	 * Hold the logger.
	 *
	 * @param LoggerInterface $logger Receives the degraded reads.
	 */
	public function __construct(private readonly LoggerInterface $logger) {
	}//end __construct()

	/**
	 * The paginated result of a read.
	 *
	 * @param ObjectSourceRead $read     The provider, schema, query and config.
	 * @param Register|null    $register The register context.
	 *
	 * @return array{results: array, total: int, '@self': array} The paginated result.
	 */
	public function read(ObjectSourceRead $read, ?Register $register): array {
		$window = new ObjectSourceWindow(query: $read->query);
		if ($read->provider?->isEnabled() !== true || $register === null) {
			$this->warnUnavailable(read: $read);
			return $window->page(results: [], counted: null);
		}

		$results = $read->provider->findAll(
			source: new SourcedTable(table: new RegisterSchema(register: $register, schema: $read->schema), config: $read->config),
			query: $read->query
		);
		return $window->page(results: $results, counted: $this->countedTotal(read: $read, register: $register));
	}//end read()

	/**
	 * Log that the schema's provider cannot answer.
	 *
	 * @param ObjectSourceRead $read The read.
	 *
	 * @return void
	 */
	private function warnUnavailable(ObjectSourceRead $read): void {
		$this->logger->warning(
			message: sprintf(
				'[ObjectSource] schema "%s" declares provider "%s" but it is missing/disabled or has no register context — returning empty list',
				(string) $read->schema->getSlug(),
				$read->schema->getObjectSource()['provider']
			)
		);
	}//end warnUnavailable()

	/**
	 * The provider's count(), or null when it has none to give.
	 *
	 * @param ObjectSourceRead $read     The read, its provider enabled.
	 * @param Register         $register The register context.
	 *
	 * @return int|null The count, or null when count() throws.
	 */
	private function countedTotal(ObjectSourceRead $read, Register $register): ?int {
		try {
			return $read->provider->count(
				source: new SourcedTable(table: new RegisterSchema(register: $register, schema: $read->schema), config: $read->config),
				query: $read->query
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[ObjectSource] provider "'.$read->provider->getId().'" count() unavailable — falling back to in-memory total: '.$e->getMessage()
			);
			return null;
		}
	}//end countedTotal()
}//end class
