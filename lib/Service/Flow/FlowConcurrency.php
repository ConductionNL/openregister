<?php

/**
 * Runs a node's per-item work concurrently, within a bound.
 *
 * A node that performs one outbound call per item loops, so N items cost N
 * sequential round-trips. openconnector's synchronisation reader — the code a
 * flow-based openconnector replaces — instead settles promises through
 * `Each::ofLimit()` behind a concurrency cap. The difference is structural: N
 * serial round-trips against ceil(N/limit) waves.
 *
 * This is that behaviour, once, so every node does not reimplement it. It
 * deliberately lives BESIDE the dispatcher rather than inside it: the engine
 * hands a node its whole item list and the node decides how to walk it, so
 * putting concurrency in the dispatch path would change how every node is
 * invoked in order to speed up the few that call out.
 *
 * THE THREE PROPERTIES A NAIVE FAN-OUT LOSES
 * ------------------------------------------
 * The serial loop gets all three for free, which is why they are asserted here
 * rather than left to each caller:
 *
 *   BOUND      An unbounded fan-out turns one flow over a large list into a
 *              burst against an upstream that did nothing to deserve it.
 *   ORDER      Results come back in INPUT order, never completion order. A run
 *              log whose order depends on which call returned first is not
 *              comparable between two runs of the same flow.
 *   ISOLATION  One item's failure yields that item's failure, not the loss of
 *              the other results — so the caller can apply its `onError` policy
 *              exactly as it does from a loop.
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
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-that-calls-out-per-item-must-be-able-to-do-so-concurrently
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

/**
 * Settles per-item work behind a concurrency cap, in input order.
 */
class FlowConcurrency
{

    /**
     * Concurrent calls in flight when a node names no limit of its own.
     *
     * Five, matching openconnector's `FETCH_CONCURRENCY_DEFAULT`. A flow node
     * and a synchronisation are hitting the same upstreams, often the same
     * ones, so a second and different default would mean the load an API sees
     * depends on which of the two happened to read it.
     *
     * @var integer
     */
    public const DEFAULT_LIMIT = 5;

    /**
     * Hard ceiling, whatever a node asks for.
     *
     * Twenty, matching openconnector's `FETCH_CONCURRENCY_MAX`. A node's
     * configuration is authored by a person, and a misconfiguration must not be
     * able to turn one step into an unbounded burst.
     *
     * @var integer
     */
    public const MAX_LIMIT = 20;

    /**
     * Map items through per-item work, concurrently and in order.
     *
     * `$work` is called with `(item, index)` and returns EITHER a promise or a
     * plain value. A plain value is wrapped, so a node whose work is sometimes
     * synchronous — a cache hit, a short-circuit on an empty item — does not
     * have to fabricate a promise to use this.
     *
     * The generator is deliberately lazy: `EachPromise` pulls the next item
     * only as a slot frees, so `$work` is not invoked for item 500 while five
     * are in flight. Building the promises eagerly would start every call at
     * once and make the limit decorative — the failure this class exists to
     * prevent, arrived at from the inside.
     *
     * @param array<int, mixed> $items The item list.
     * @param callable          $work  `fn(mixed $item, int $index): PromiseInterface|mixed`.
     * @param int|null          $limit Concurrent calls in flight; clamped to MAX_LIMIT.
     *
     * @return array<int, array{ok: bool, value: mixed, error: Throwable|null}> One
     *         result per input item, in INPUT order.
     *
     * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-that-calls-out-per-item-must-be-able-to-do-so-concurrently
     *
     * @SuppressWarnings(PHPMD.StaticAccess) `GuzzleHttp\Promise\Create` is a
     * third-party STATIC FACTORY with no instance API — `rejectionFor()` and
     * `promiseFor()` are the library's only supported way to lift a value or a
     * throwable into a promise. Satisfying the rule would mean injecting a
     * wrapper whose entire body is the same two static calls, which moves the
     * static access one file sideways and buys nothing. The two calls are
     * two lines apart in one generator, both visible above.
     */
    public function map(array $items, callable $work, ?int $limit=null): array
    {
        if ($items === []) {
            return [];
        }

        $indexed = array_values($items);
        $bound   = $this->boundedLimit(limit: $limit);
        $results = [];

        $promises = (function () use ($indexed, $work): \Generator {
            foreach ($indexed as $index => $item) {
                try {
                    $produced = $work($item, $index);
                } catch (Throwable $e) {
                    // A `$work` that throws SYNCHRONOUSLY — before it ever
                    // returns a promise — must be caught here. It is not a
                    // rejected promise, so `EachPromise`'s rejection handler
                    // never sees it, and it would escape `map()` and take the
                    // other items' results with it: the exact loss of isolation
                    // this class promises not to have.
                    yield $index => Create::rejectionFor($e);
                    continue;
                }

                if (($produced instanceof PromiseInterface) === true) {
                    yield $index => $produced;
                    continue;
                }

                yield $index => Create::promiseFor($produced);
            }//end foreach
        })();

        (new EachPromise(
            $promises,
            [
                'concurrency' => $bound,
                'fulfilled'   => static function ($value, $index) use (&$results): void {
                    $results[$index] = ['ok' => true, 'value' => $value, 'error' => null];
                },
                'rejected'    => static function ($reason, $index) use (&$results): void {
                    $error = $reason;
                    if (($reason instanceof Throwable) === false) {
                        $error = new RuntimeException((string) $reason);
                    }

                    $results[$index] = ['ok' => false, 'value' => null, 'error' => $error];
                },
            ]
        ))->promise()->wait();

        // INPUT order, not completion order. `$results` was filled as calls
        // returned, so without this the run log's sample would be ordered by
        // whichever upstream answered first — different on every run of the
        // same flow, and therefore not comparable between two of them.
        ksort($results);

        return array_values($results);

    }//end map()

    /**
     * The concurrency to actually use.
     *
     * Clamped rather than trusted: the limit reaches here from a node's
     * configuration, which a person authored, and a typo must not become a
     * burst. Below one is meaningless and becomes one — a step that ran zero
     * items at a time would hang rather than fail.
     *
     * @param int|null $limit The requested limit.
     *
     * @return int The bounded limit.
     */
    private function boundedLimit(?int $limit): int
    {
        if ($limit === null) {
            return self::DEFAULT_LIMIT;
        }

        return max(1, min($limit, self::MAX_LIMIT));

    }//end boundedLimit()
}//end class
