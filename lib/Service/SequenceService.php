<?php

/**
 * OpenRegister SequenceService
 *
 * Hands out stable, atomic, per-scope running numbers backing the declarative
 * `sequence` calculation operator. A leaf app declares
 * `{ "sequence": { "scope": "yearly", "pad": 4 } }` and OpenRegister reserves
 * the next number on object CREATE — so an identifier like `2026-0042` is
 * declarative, with no leaf-app code.
 *
 * Concurrency contract: reserveNext() runs inside a DB transaction and issues a
 * single atomic `UPDATE … SET next_value = next_value + 1`. The UPDATE's row
 * lock (held by both Postgres and MySQL/InnoDB until commit) serialises
 * concurrent reservations on the same scope, so each value is handed out
 * exactly once. The scope row is created lazily on first use; a lost insert
 * race is resolved by retrying the increment.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Sequence;
use OCA\OpenRegister\Db\SequenceMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use Throwable;

/**
 * Reserves the next running number for a (register, schema, scope) triple.
 */
class SequenceService
{
    /**
     * Wire the sequence mapper and DB connection used for the reservation transaction.
     *
     * @param SequenceMapper $mapper The sequence mapper.
     * @param IDBConnection  $db     DB connection (for the reservation transaction).
     *
     * @return void
     */
    public function __construct(
        private readonly SequenceMapper $mapper,
        private readonly IDBConnection $db
    ) {
    }//end __construct()

    /**
     * Atomically reserve and return the next number for the given scope.
     *
     * The returned value is never handed out twice for the same
     * (register, schema, scope) triple. Numbering starts at 1.
     *
     * @param int    $registerId The register the sequence is scoped to.
     * @param int    $schemaId   The schema the sequence is scoped to.
     * @param string $scopeKey   The scope discriminator (e.g. "2026", "2026-06" or "" for global).
     *
     * @return int The reserved running number (>= 1).
     *
     * @throws DbException When the reservation cannot be completed.
     */
    public function reserveNext(int $registerId, int $schemaId, string $scopeKey): int
    {
        $this->db->beginTransaction();
        try {
            $affected = $this->mapper->incrementScope(
                registerId: $registerId,
                schemaId: $schemaId,
                scopeKey: $scopeKey
            );

            if ($affected === 0) {
                // Scope row does not exist yet — seed it reserving value 1 by
                // storing next_value = 2. A concurrent seeder may win the
                // unique index; on that violation, fall back to the increment
                // path so we still reserve a fresh value.
                $reserved = $this->seedScope(
                    registerId: $registerId,
                    schemaId: $schemaId,
                    scopeKey: $scopeKey
                );

                $this->db->commit();
                return $reserved;
            }

            $row = $this->mapper->findForScope(
                registerId: $registerId,
                schemaId: $schemaId,
                scopeKey: $scopeKey
            );

            $next = (int) ($row?->getNextValue() ?? 1);
            $this->db->commit();

            // The next_value column now points at the NEXT hand-out; the value
            // we just reserved is one below it.
            return ($next - 1);
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }//end try
    }//end reserveNext()

    /**
     * Seed a brand-new scope row, reserving value 1.
     *
     * On a unique-index race (another transaction inserted the row first) the
     * insert throws; we then re-run the atomic increment so the caller still
     * receives a fresh, unique value.
     *
     * @param int    $registerId The register the sequence is scoped to.
     * @param int    $schemaId   The schema the sequence is scoped to.
     * @param string $scopeKey   The scope discriminator.
     *
     * @return int The reserved running number.
     *
     * @throws DbException When the fallback increment cannot read the row.
     */
    private function seedScope(int $registerId, int $schemaId, string $scopeKey): int
    {
        $entity = new Sequence();
        $entity->setRegisterId($registerId);
        $entity->setSchemaId($schemaId);
        $entity->setScopeKey($scopeKey);
        // Storing 2 means value 1 has just been reserved.
        $entity->setNextValue(2);

        try {
            $this->mapper->insert($entity);
            return 1;
        } catch (DbException $e) {
            // Lost the insert race — the row now exists; increment it instead.
            $this->mapper->incrementScope(
                registerId: $registerId,
                schemaId: $schemaId,
                scopeKey: $scopeKey
            );
            $row  = $this->mapper->findForScope(
                registerId: $registerId,
                schemaId: $schemaId,
                scopeKey: $scopeKey
            );
            $next = (int) ($row?->getNextValue() ?? 1);
            return ($next - 1);
        }//end try
    }//end seedScope()
}//end class
