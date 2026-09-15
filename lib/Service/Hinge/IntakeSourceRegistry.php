<?php

/**
 * IntakeSourceRegistry — the intake sources an instance has, as objects.
 *
 * Ours used to be one mailbox in a settings screen, which made a second mailbox
 * a code change (D-6). A source is an object in the `intake-sources` register,
 * so there can be several, each switchable off, each carrying the access rules
 * and the audit trail any object carries. Nothing here polls: this answers which
 * sources exist and which are switched on, and the app that reads it does the
 * polling.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Hinge
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Hinge;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Repair\SeedIntakeSourceRegister;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the intake sources an instance has declared.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Hinge
 */
class IntakeSourceRegistry {

	/**
	 * Wire the object read the registry goes through.
	 *
	 * @param ObjectService   $objectService Read side of the object layer.
	 * @param LoggerInterface $logger        PSR logger for an absent register.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every declared intake source, switched on or off.
	 *
	 * @param string|null $kind Restrict to one kind: watchedFolder, mailbox or endpoint.
	 *
	 * @return array<int, array> The sources, each as its object data.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function all(?string $kind = null): array {
		$filters = [];
		if ($kind !== null && $kind !== '') {
			$filters['kind'] = $kind;
		}

		return $this->read(filters: $filters);
	}//end all()

	/**
	 * The intake sources that are polled.
	 *
	 * A source is polled when it is not switched off. Absence of the flag reads
	 * as on, because the schema defaults it to true and an object saved before
	 * the property existed should not go silently dark.
	 *
	 * @param string|null $kind Restrict to one kind: watchedFolder, mailbox or endpoint.
	 *
	 * @return array<int, array> The enabled sources.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function enabled(?string $kind = null): array {
		return array_values(
			array_filter(
				$this->all(kind: $kind),
				static fn (array $source): bool => (($source['enabled'] ?? true) !== false)
			)
		);
	}//end enabled()

	/**
	 * Read the sources out of the register, or nothing when it is not there.
	 *
	 * An instance that never ran the seed step has no register to read, and that
	 * is not an error: it means nobody declared a source. The distinction is
	 * logged rather than thrown, so a poller asking on a fresh instance gets an
	 * empty list instead of a stack trace.
	 *
	 * @param array $filters Object filters to apply.
	 *
	 * @return array<int, array> The sources, each as its object data.
	 */
	private function read(array $filters): array {
		try {
			$objects = $this->objectService
				->setRegister(register: SeedIntakeSourceRegister::REGISTER_SLUG)
				->setSchema(schema: SeedIntakeSourceRegister::SCHEMA_SLUG)
				->findAll(config: ['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->debug(
				sprintf('[IntakeSourceRegistry] No intake-sources register to read: %s', $e->getMessage())
			);
			return [];
		}

		$sources = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$sources[] = $object->getObject();
				continue;
			}

			if (is_array($object) === true) {
				$sources[] = $object;
			}
		}

		return $sources;
	}//end read()
}//end class
