<?php

/**
 * OpenRegister schema listing for repair steps
 *
 * Every schema, read past RBAC and multitenancy, for a repair step that walks
 * them all; a mapper that cannot list them skips the step with an info log
 * instead of stopping the upgrade.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lists the schemas a repair step walks.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
final class SchemaListing {

	/**
	 * Hold the mapper and the log.
	 *
	 * @param SchemaMapperInterface $schemaMapper Lists the schemas.
	 * @param LoggerInterface       $logger       Logs why the step is skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every schema, or null after logging, tagged with the step, why they cannot be listed.
	 *
	 * @param string $step The repair step's short class name.
	 *
	 * @return array|null The schemas.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function allOrSkip(string $step): ?array {
		try {
			return $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info('[' . $step . '] Could not list schemas, skipping: ' . $e->getMessage());
			return null;
		}
	}//end allOrSkip()
}//end class
