<?php

/**
 * ReportReferenceLoopSchemas: name every stored schema that holds a reference loop.
 *
 * A schema save refuses a `$ref` that comes back to itself without moving
 * through the data (ReferenceLoopCheck), but schemas stored before that check
 * keep theirs, and validation passes such a branch at its second visit. This
 * step runs the same check over every stored schema and warns per schema, so
 * an administrator can correct it. It changes nothing.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\ReferenceLoopCheck;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: warn about stored schemas whose references loop.
 *
 * @spec openspec/specs/object-validation/spec.md
 */
class ReportReferenceLoopSchemas implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the schema mapper lazily, as the other schema scans do.
	 * @param LoggerInterface    $logger    Receives one warning per looping schema.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function getName(): string {
		return 'Report schemas whose references loop back to themselves';
	}//end getName()

	/**
	 * Check every stored schema and warn about each one with a reference loop.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$schemas = $this->container->get('OCA\\OpenRegister\\Db\\SchemaMapper')->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$output->info('[OpenRegister] Schema mapper unavailable; reference loop scan skipped.');
			return;
		}

		$check = new ReferenceLoopCheck();
		$found = 0;
		foreach ($schemas as $schema) {
			if (($schema instanceof Schema) === false) {
				continue;
			}

			try {
				$check->check(schema: $schema);
			} catch (InvalidArgumentException $loop) {
				$found++;
				$message = sprintf(
					'[OpenRegister] Schema "%s" (id=%s) holds a reference loop that validates nothing; correct the schema. %s',
					(string)$schema->getSlug(),
					(string)$schema->getId(),
					$loop->getMessage()
				);
				$this->logger->warning($message);
				$output->warning($message);
			}
		}

		if ($found === 0) {
			$output->info('[OpenRegister] No stored schema holds a reference loop.');
		}
	}//end run()
}//end class
