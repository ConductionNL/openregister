<?php

/**
 * Keeps a run's Petri-net marking on its FlowRun row.
 *
 * `symfony/workflow` reads and writes the marking through a
 * `MarkingStoreInterface`, and the in-memory implementations put it on a
 * property of the subject. That is fine for a run that starts and finishes
 * inside one request, and useless for one that does not.
 *
 * This store puts the marking where it survives: on the run. Resuming a
 * suspended run is then handing the stored places back to Symfony, not
 * replaying the graph from the beginning — which matters because replaying
 * would re-run every side effect the run already performed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * A marking store backed by a FlowRun row.
 */
class FlowRunMarkingStore implements MarkingStoreInterface
{
    /**
     * Constructor.
     *
     * @param FlowRun $run The run whose marking this store reads and writes.
     */
    public function __construct(private readonly FlowRun $run)
    {

    }//end __construct()

    /**
     * Read the marking.
     *
     * The subject is ignored: the marking belongs to the RUN, not to the object
     * the run is about. Two runs over one object hold two independent markings,
     * which is the whole reason this is not stored on the subject.
     *
     * @param object $subject The subject (unused).
     *
     * @return Marking The current marking.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function getMarking(object $subject): Marking
    {
        $places = ($this->run->getMarking() ?? []);
        if (is_array($places) === false || $places === []) {
            return new Marking();
        }

        // Stored as `place => tokens`. A list of place names is also accepted,
        // because that is the shape a hand-authored fixture tends to take.
        $normalised = [];
        foreach ($places as $key => $value) {
            if (is_int($key) === true) {
                $normalised[(string) $value] = 1;
                continue;
            }

            $normalised[(string) $key] = max(1, (int) $value);
        }

        return new Marking($normalised);

    }//end getMarking()

    /**
     * Write the marking back onto the run.
     *
     * Only mutates the entity; persisting is the caller's job, so a whole hop
     * (marking, items, log) is written in one update rather than three.
     *
     * @param object              $subject The subject (unused).
     * @param Marking             $marking The new marking.
     * @param array<string,mixed> $context Transition context (unused).
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function setMarking(object $subject, Marking $marking, array $context=[]): void
    {
        $this->run->setMarking($marking->getPlaces());

    }//end setMarking()
}//end class
