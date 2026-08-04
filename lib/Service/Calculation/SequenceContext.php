<?php

/**
 * OpenRegister SequenceContext
 *
 * Immutable, per-save context that authorises the calculation evaluator to
 * CONSUME running sequence numbers. The evaluator is otherwise a pure
 * function: it is handed a SequenceContext ONLY on the create path by
 * CalculationOnSaveListener. On the read path (and on update) no context is
 * supplied, so a `{ "sequence": … }` node resolves to null instead of
 * reserving (and burning) a number.
 *
 * The context binds the register + schema scope the sequence belongs to and
 * delegates the atomic reservation to SequenceService::reserveNext().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calculation
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

namespace OCA\OpenRegister\Service\Calculation;

use OCA\OpenRegister\Service\SequenceService;

/**
 * Authorises (and scopes) sequence consumption for a single save.
 */
final class SequenceContext
{
    /**
     * Wire the reservation service and the register/schema scope.
     *
     * @param SequenceService $service    Atomic sequence reservation service.
     * @param int             $registerId The register the sequence is scoped to.
     * @param int             $schemaId   The schema the sequence is scoped to.
     *
     * @return void
     */
    public function __construct(
        private readonly SequenceService $service,
        private readonly int $registerId,
        private readonly int $schemaId
    ) {
    }//end __construct()

    /**
     * Atomically reserve the next number for the given scope key.
     *
     * @param string $scopeKey The scope discriminator (e.g. "2026", "2026-06" or "" for global).
     *
     * @return int The reserved, never-reused running number.
     */
    public function reserveNext(string $scopeKey): int
    {
        return $this->service->reserveNext(
            registerId: $this->registerId,
            schemaId: $this->schemaId,
            scopeKey: $scopeKey
        );
    }//end reserveNext()
}//end class
