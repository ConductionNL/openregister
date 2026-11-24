<?php

/**
 * ExpiredObjectStoreInterface: what the expired-object sweep reads and writes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Retention
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Retention;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The storage the expired-object sweep works on, kept apart from its rules.
 */
interface ExpiredObjectStoreInterface {

	/**
	 * Every register and schema pair whose schema opted in to expiry and whose table exists.
	 *
	 * @return array<int, array{register: Register, schema: Schema}>
	 */
	public function optedInTables(): array;

	/**
	 * The objects of one table whose expiry is before $now, oldest expiry first, at most $limit.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $now      The moment of the run.
	 * @param int               $limit    The most objects to return.
	 *
	 * @return array<int, ObjectEntity>
	 */
	public function expired(Register $register, Schema $schema, DateTimeImmutable $now, int $limit): array;

	/**
	 * How many objects of one table have an expiry before $now.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $now      The moment of the run.
	 *
	 * @return int
	 */
	public function countExpired(Register $register, Schema $schema, DateTimeImmutable $now): int;

	/**
	 * Delete one object as the system.
	 *
	 * @param Register     $register The register.
	 * @param Schema       $schema   The schema.
	 * @param ObjectEntity $object   The object.
	 *
	 * @return bool Whether it was deleted.
	 */
	public function delete(Register $register, Schema $schema, ObjectEntity $object): bool;

	/**
	 * Replace one object's data, remove its files and clear its expiry, without validation.
	 *
	 * @param Register             $register The register.
	 * @param Schema               $schema   The schema.
	 * @param ObjectEntity         $object   The object.
	 * @param array<string, mixed> $data     The anonymised data.
	 *
	 * @return void
	 */
	public function anonymise(Register $register, Schema $schema, ObjectEntity $object, array $data): void;

	/**
	 * Write one audit entry for an object.
	 *
	 * @param ObjectEntity         $object  The object.
	 * @param string               $action  The audit action.
	 * @param array<string, mixed> $context What to record with it.
	 *
	 * @return void
	 */
	public function audit(ObjectEntity $object, string $action, array $context): void;
}//end interface
