<?php

/**
 * OpenRegister connection registry reader.
 *
 * Reads the rows the connection report is about from integriq's register, as
 * Open Register objects, as whoever is in the session (the scheduled report's
 * owner during a run). Every read is bounded. The register and schema slugs are
 * integriq's published contract from the connection registry; there is no PHP
 * dependency on integriq.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Connection;

use JsonSerializable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Bounded reads of app_connection, synchronization_run, synchronization and source.
 *
 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
 */
class ConnectionRegistryReader {

	/**
	 * The register the connection registry lives in.
	 */
	public const REGISTER_SLUG = 'integriq';

	/**
	 * Constructor.
	 *
	 * @param RegisterMapperInterface $registers The registers.
	 * @param SchemaMapperInterface   $schemas   The schemas.
	 * @param ObjectService           $objects   The object reads.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
	 */
	public function __construct(
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
		private readonly ObjectService $objects,
	) {
	}//end __construct()

	/**
	 * Rows of one integriq schema, newest first when a sort field is given.
	 *
	 * @param string                $schemaSlug The schema slug.
	 * @param int                   $limit      The cap.
	 * @param array<string, string> $sort       Field => ASC|DESC.
	 *
	 * @return array<int, array<string, mixed>> The rows; [] when the schema is not in the register.
	 *
	 * @throws ConnectionRegistryMissingException When register `integriq` does not exist.
	 *
	 * @spec openspec/changes/connections-daily-report-mail/specs/scheduled-report-jobs/spec.md#requirement-a-scheduled-report-can-be-a-daily-connection-health-report
	 */
	public function rows(string $schemaSlug, int $limit, array $sort = []): array {
		$register = $this->register();
		$schema = $this->schemas->findBySlugInIds(slug: $schemaSlug, schemaIds: (array)($register->getSchemas() ?? []));
		if ($schema === null) {
			return [];
		}

		$found = $this->objects->setRegister(register: $register)->setSchema(schema: $schema)->findAll(
			config: ['limit' => $limit, 'sort' => $sort]
		);

		$rows = [];
		foreach ($found as $row) {
			if ($row instanceof JsonSerializable) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * The connection registry's register.
	 *
	 * @return Register The register.
	 *
	 * @throws ConnectionRegistryMissingException When it does not exist.
	 */
	private function register(): Register {
		try {
			return $this->registers->find(id: self::REGISTER_SLUG, _rbac: false, _multitenancy: false);
		} catch (DoesNotExistException $e) {
			throw new ConnectionRegistryMissingException();
		}
	}//end register()
}//end class
