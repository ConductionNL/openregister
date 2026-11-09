<?php

/**
 * OpenRegister TemporalSchemaScan
 *
 * Enumerates the schemas and registers the temporal sweep walks, in system
 * scope, and finds the register a schema belongs to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation\TemporalSweep
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calculation\TemporalSweep;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Schema and register enumeration for the temporal sweep.
 *
 * @spec openspec/specs/computed-fields/spec.md
 */
final class TemporalSchemaScan {

	/**
	 * Wire the mappers and the logger.
	 *
	 * @param SchemaMapperInterface $schemaMapper Schema enumeration (system scope).
	 * @param RegisterMapperInterface $registerMapper Register enumeration for schema→register resolution.
	 * @param LoggerInterface $logger Structured logging.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every schema (system scope), or null when the enumeration failed.
	 *
	 * @return array<int, Schema>|null The schemas, or null after logging the failure.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function schemas(): ?array {
		try {
			return $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[TemporalCalculationSweep] schema enumeration failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end schemas()

	/**
	 * Load every register once (system scope); empty when the enumeration failed.
	 *
	 * @return array<int, Register> All registers.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function registers(): array {
		try {
			return $this->registerMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[TemporalCalculationSweep] register enumeration failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return [];
		}
	}//end registers()

	/**
	 * Find the register a schema belongs to (first register whose schema-id
	 * list contains it), or null for orphaned schemas.
	 *
	 * @param Schema $schema The schema.
	 * @param array<int, Register> $registers All registers.
	 *
	 * @return Register|null The owning register, or null.
	 *
	 * @spec openspec/specs/computed-fields/spec.md
	 */
	public function registerOf(Schema $schema, array $registers): ?Register {
		$schemaId = (int)$schema->getId();
		foreach ($registers as $register) {
			$memberIds = array_map('intval', ($register->getSchemas() ?? []));
			if (in_array($schemaId, $memberIds, true) === true) {
				return $register;
			}
		}

		return null;
	}//end registerOf()
}//end class
