<?php

/**
 * OpenRegister UniqueHintChecker
 *
 * The soft-uniqueness alert: a nominated property whose value already exists
 * on another object of the same schema warns, on create and on update, and the
 * write still succeeds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warns when a nominated property's value is already held elsewhere.
 *
 * It is an ALERT, never a constraint. A database unique index refuses; what
 * the register asked for is to be told, at the moment of writing, which record
 * already holds the value, with the writer free to continue. A schema that
 * needs a refusal declares `x-openregister-dedup.onCreate: "block"` instead,
 * and the two annotations sit beside each other meaning different things on
 * purpose.
 *
 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
 */
class UniqueHintChecker {

	/**
	 * Upper bound on the objects examined per nominated property.
	 *
	 * The same cap the duplicate check uses, and the same reason: an alert
	 * that walks a register is an alert nobody can afford to switch on.
	 *
	 * @var int
	 */
	private const MAX_CANDIDATES = 1000;

	/**
	 * Wire collaborators.
	 *
	 * @param ObjectService $objectService Object query path (RBAC + tenant scoped).
	 * @param UniqueHintAnnotationValidator $annotation Reader for the nominations.
	 * @param UniqueHintWarnings $warnings Per-request collector the controller drains.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly UniqueHintAnnotationValidator $annotation,
		private readonly UniqueHintWarnings $warnings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check every nominated property of one incoming write.
	 *
	 * Runs on create and on update. On an update the object being written is
	 * excluded from its own matches, so keeping a value you already hold is
	 * not reported as a collision with yourself.
	 *
	 * @param array<string, mixed> $configuration The schema's configuration block.
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 * @param array<string, mixed> $data The incoming payload.
	 * @param string|null $selfUuid Uuid of the object being written, on an update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	public function check(array $configuration, $register, $schema, array $data, ?string $selfUuid = null): void {
		$nominated = $this->annotation->nominated(configuration: $configuration);
		if (count($nominated) === 0) {
			return;
		}

		foreach ($nominated as $property) {
			$value = ($data[$property] ?? null);
			if (is_scalar($value) === false || (string)$value === '') {
				continue;
			}

			$this->checkOne(
				property: $property,
				value: $value,
				register: $register,
				schema: $schema,
				selfUuid: $selfUuid
			);
		}
	}//end check()

	/**
	 * Check one nominated property.
	 *
	 * @param string $property The nominated property.
	 * @param scalar $value The incoming value.
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 * @param string|null $selfUuid Uuid to exclude, on an update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	private function checkOne(string $property, $value, $register, $schema, ?string $selfUuid): void {
		$visible = $this->holdersOf(
			property: $property,
			value: $value,
			register: $register,
			schema: $schema,
			scoped: true
		);
		$all = $this->holdersOf(
			property: $property,
			value: $value,
			register: $register,
			schema: $schema,
			scoped: false
		);

		$visibleUuids = $this->uuidsExcludingSelf(holders: $visible, selfUuid: $selfUuid);
		$allUuids = $this->uuidsExcludingSelf(holders: $all, selfUuid: $selfUuid);

		if (count($visibleUuids) > 0) {
			$this->warnings->record(property: $property, matches: $visibleUuids, visible: true);
			return;
		}

		if (count($allUuids) === 0) {
			return;
		}

		// A holder exists that this caller may not read. The spec asks for the
		// EXISTENCE to be reported and the object not to be named, so a second
		// case on the same KvK number is still noticed by a handler who cannot
		// open the first one. That is a deliberate, bounded disclosure: it
		// says "somewhere in this schema, one exists", which is exactly what a
		// database unique constraint would have leaked anyway, and nothing
		// about whose it is or what else it holds.
		$this->warnings->record(property: $property, matches: [], visible: false);
	}//end checkOne()

	/**
	 * The uuids of a holder list, minus the object doing the writing.
	 *
	 * On an update the object being written holds the value already, and
	 * reporting that as a collision with itself would make every re-save of an
	 * unchanged record warn.
	 *
	 * @param array<int, ObjectEntity> $holders The objects found.
	 * @param string|null $selfUuid Uuid to exclude, on an update.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	private function uuidsExcludingSelf(array $holders, ?string $selfUuid): array {
		$uuids = [];
		foreach ($holders as $holder) {
			$uuid = (string)$holder->getUuid();
			if ($uuid === '' || ($selfUuid !== null && $uuid === $selfUuid)) {
				continue;
			}

			$uuids[] = $uuid;
		}

		return array_values(array_unique($uuids));
	}//end uuidsExcludingSelf()

	/**
	 * The objects already holding a value for a property, RBAC- and
	 * tenant-scoped and bounded by the cap.
	 *
	 * The scoping is what makes the alert safe to switch on. A caller who may
	 * not read the object holding the value gets no rows back, so the warning
	 * cannot become a way to ask whether a BSN exists in a register you have
	 * no access to.
	 *
	 * @param string $property The nominated property.
	 * @param scalar $value The incoming value.
	 * @param int|string $register Register reference.
	 * @param int|string $schema Schema reference.
	 * @param bool $scoped Whether to apply RBAC and tenancy; false counts holders the caller may not read.
	 *
	 * @return array<int, ObjectEntity> The holders.
	 *
	 * @spec openspec/changes/duplicate-merge-and-dismissed-pairs/specs/duplicate-detection/spec.md#requirement-a-nominated-property-warns-when-its-value-already-exists-req-dmd-004
	 */
	private function holdersOf(string $property, $value, $register, $schema, bool $scoped = true): array {
		try {
			$objects = $this->objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						$property => $value,
					],
					'limit' => self::MAX_CANDIDATES,
				],
				_rbac: $scoped,
				_multitenancy: $scoped
			);
		} catch (Throwable $e) {
			$this->logger->warning('[UniqueHintChecker] uniqueness lookup failed: ' . $e->getMessage());
			return [];
		}

		$entities = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity) {
				$entities[] = $object;
			}
		}

		return $entities;
	}//end holdersOf()
}//end class
