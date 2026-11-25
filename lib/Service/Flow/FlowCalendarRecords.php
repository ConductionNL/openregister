<?php

/**
 * Reads the records of a calendar trigger's schema, for the index to place them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Runs in the background job when a trigger is first seen enabled, with no
 * user present: the index holds dates, never data, and is never shown to a
 * user, so the read is unscoped by RBAC and organisation.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use JsonSerializable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Container\ContainerInterface;

/**
 * The records of one register and schema, as uuid and data.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarRecords {

	/**
	 * At most this many records are placed when a trigger is enabled.
	 *
	 * @var integer
	 */
	public const LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the object service lazily; it is heavy and only the job needs it.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The records of a register and schema, by uuid.
	 *
	 * @param string $register The register, by id or slug.
	 * @param string $schema   The schema, by id or slug.
	 *
	 * @return array<string, array<string, mixed>> Data by record uuid.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	public function recordsOf(string $register, string $schema): array {
		$objects = $this->container->get(ObjectOperationsInterface::class)->findAll(
			config: ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => self::LIMIT],
			_rbac: false,
			_multitenancy: false
		);

		$records = [];
		foreach ($objects as $object) {
			[$uuid, $data] = self::split(object: $object);
			if ($uuid !== '') {
				$records[$uuid] = $data;
			}
		}

		return $records;
	}//end recordsOf()

	/**
	 * A rendered record's uuid and data.
	 *
	 * @param mixed $object An ObjectEntity or a rendered array.
	 *
	 * @return array{0: string, 1: array<string, mixed>}
	 */
	private static function split(mixed $object): array {
		if ($object instanceof ObjectEntity) {
			return [(string)$object->getUuid(), $object->getObject()];
		}

		if ($object instanceof JsonSerializable) {
			$object = $object->jsonSerialize();
		}

		if (is_array($object) === false) {
			return ['', []];
		}

		$uuid = (string)($object['@self']['id'] ?? ($object['id'] ?? ($object['uuid'] ?? '')));

		return [$uuid, $object];
	}//end split()
}//end class
