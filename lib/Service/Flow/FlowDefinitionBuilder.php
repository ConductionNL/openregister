<?php

/**
 * Builds a symfony/workflow Definition from a stored OpenRegister flow object.
 *
 * This is the translation layer between what users author (a graph of nodes and
 * edges, drawn on CnGraphCanvas) and what executes (a Petri net). It is the only
 * place that knows both shapes, so the engine never parses user JSON and the
 * stored document never mentions Symfony.
 *
 * Why a Petri net (ADR-065): it is a superset of the two flow models this fleet
 * already has. A single-token marking is a state machine (procest: `case.status`
 * is the marking); a multi-token marking expresses parallel splits and
 * synchronising joins, which no existing fleet engine can do — openconnector's
 * `order`-indexed step list explicitly cannot, and that limit is why a canvas
 * could not ship against it.
 *
 * The vocabulary maps as:
 *
 *   flow node  -> place       (a position in the graph)
 *   flow edge  -> transition  (a move between positions)
 *   marking    -> where the run currently is (one token, or several)
 *
 * A transition with several `from` places is a **join**: symfony/workflow will
 * not enable it until every one of them holds a token. A transition with several
 * `to` places is a **split**: firing it puts a token on each. That is the whole
 * mechanism behind parallel branches — we do not implement it, we declare it.
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use InvalidArgumentException;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\Transition;

/**
 * Translates a stored flow document into an executable Petri-net definition.
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */
class FlowDefinitionBuilder
{
    /**
     * Build a Definition from a stored flow document.
     *
     * The document is user data and is therefore treated as hostile: every
     * reference is resolved against the declared nodes before it reaches
     * symfony/workflow, which would otherwise fail later and less legibly.
     *
     * @param array $flow The flow document: `{nodes: [{id}], edges: [{id, from, to}], initial?}`.
     *
     * @return Definition The executable definition.
     *
     * @throws InvalidArgumentException When the document does not describe a runnable graph.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    public function build(array $flow): Definition
    {
        $places = $this->extractPlaces(flow: $flow);
        if (empty($places) === true) {
            throw new InvalidArgumentException(message: 'Flow declares no nodes; nothing to run.');
        }

        $transitions = $this->extractTransitions(flow: $flow, places: $places);
        $initial     = $this->resolveInitialPlaces(flow: $flow, places: $places, transitions: $transitions);

        return new Definition(places: $places, transitions: $transitions, initialPlaces: $initial);

    }//end build()

    /**
     * Collect the node ids that become Petri-net places.
     *
     * @param array $flow The flow document.
     *
     * @return array<string> The place names, in declaration order.
     *
     * @throws InvalidArgumentException When a node has no usable id, or ids collide.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    private function extractPlaces(array $flow): array
    {
        $places = [];
        foreach (($flow['nodes'] ?? []) as $index => $node) {
            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException(
                    message: sprintf('Flow node at index %d has no id.', $index)
                );
            }

            // A duplicate id would silently merge two nodes into one place, so the
            // graph would run but not be the graph the user drew.
            if (in_array($id, $places, true) === true) {
                throw new InvalidArgumentException(
                    message: sprintf('Flow declares node id "%s" more than once.', $id)
                );
            }

            $places[] = $id;
        }

        return $places;

    }//end extractPlaces()

    /**
     * Convert edges into Petri-net transitions.
     *
     * Edges sharing a `name` are deliberately NOT merged: two edges named "approve"
     * from different nodes are two transitions, exactly as drawn. Merging them
     * would turn distinct user intent into an accidental join.
     *
     * @param array         $flow   The flow document.
     * @param array<string> $places The known place names.
     *
     * @return array<Transition> The transitions.
     *
     * @throws InvalidArgumentException When an edge references an unknown node.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    private function extractTransitions(array $flow, array $places): array
    {
        $transitions = [];
        foreach (($flow['edges'] ?? []) as $index => $edge) {
            $from = $this->normaliseEndpoints(value: ($edge['from'] ?? $edge['source'] ?? null));
            $to   = $this->normaliseEndpoints(value: ($edge['to'] ?? $edge['target'] ?? null));

            if (empty($from) === true || empty($to) === true) {
                throw new InvalidArgumentException(
                    message: sprintf('Flow edge at index %d must declare both "from" and "to".', $index)
                );
            }

            // Resolve every endpoint now. A dangling edge that reaches
            // symfony/workflow surfaces as an opaque failure at run time; here we
            // can name the edge and the missing node.
            foreach (array_merge($from, $to) as $endpoint) {
                if (in_array($endpoint, $places, true) === false) {
                    throw new InvalidArgumentException(
                        message: sprintf(
                            'Flow edge "%s" references unknown node "%s".',
                            (string) ($edge['id'] ?? $index),
                            $endpoint
                        )
                    );
                }
            }

            $name = trim((string) ($edge['name'] ?? $edge['id'] ?? ''));
            if ($name === '') {
                $name = sprintf('edge-%d', $index);
            }

            $transitions[] = new Transition(name: $name, froms: $from, tos: $to);
        }//end foreach

        return $transitions;

    }//end extractTransitions()

    /**
     * Normalise an edge endpoint to a list of place names.
     *
     * A scalar endpoint is an ordinary edge; an array endpoint declares a split
     * (several `to`) or a join (several `from`).
     *
     * @param mixed $value The raw endpoint value.
     *
     * @return array<string> The endpoint place names.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    private function normaliseEndpoints(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $list = [$value];
        if (is_array($value) === true) {
            $list = $value;
        }

        $names = [];
        foreach ($list as $item) {
            $name = trim((string) $item);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;

    }//end normaliseEndpoints()

    /**
     * Decide which place(s) a run starts on.
     *
     * Explicit `initial` wins. Otherwise the start is inferred as the nodes no
     * edge points at — the graph's sources. Inference keeps simple flows free of
     * boilerplate while still letting a user override it.
     *
     * @param array             $flow        The flow document.
     * @param array<string>     $places      The known place names.
     * @param array<Transition> $transitions The transitions.
     *
     * @return array<string> The initial place names.
     *
     * @throws InvalidArgumentException When `initial` names an unknown node.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    private function resolveInitialPlaces(array $flow, array $places, array $transitions): array
    {
        $declared = $this->normaliseEndpoints(value: ($flow['initial'] ?? null));
        if (empty($declared) === false) {
            foreach ($declared as $place) {
                if (in_array($place, $places, true) === false) {
                    throw new InvalidArgumentException(
                        message: sprintf('Flow initial node "%s" is not a declared node.', $place)
                    );
                }
            }

            return $declared;
        }

        $targeted = [];
        foreach ($transitions as $transition) {
            foreach ($transition->getTos() as $to) {
                $targeted[$to] = true;
            }
        }

        $sources = array_values(
                array_filter(
            $places,
            static fn (string $place): bool => isset($targeted[$place]) === false
        )
                );

        // A fully cyclic graph has no source. Rather than refuse to run it, start
        // on the first declared node: the author drew a loop, which is legitimate,
        // and declaration order is the only signal available.
        if (empty($sources) === true) {
            return [$places[0]];
        }

        return $sources;

    }//end resolveInitialPlaces()
}//end class
