<?php

/**
 * The state a flow carries BETWEEN runs, as a writable handle.
 *
 * `FlowToken` is the value that belongs to one RUN — a correlation id, a
 * reference resolved once, an envelope threaded through a pipeline — and it
 * dies when that run ends. This is its long-lived sibling: the value that
 * belongs to the FLOW and outlives every run of it.
 *
 * A scheduled flow starts blank on every tick, so anything it needs to
 * remember — a capacity table, a cursor, "what did I already process" — has
 * nowhere to live. Before this, every such value had to become a separate
 * register object, which is how hydra's concurrency cap became object writes
 * and walked into the lost update in OR#2212.
 *
 * A node reaches it at `$context['flowState']` and may WRITE to it, for the
 * same reason `FlowToken` is a class and not an array: `IFlowNode::execute()`
 * takes `$context` by value, so a plain array could only ever be read. An
 * object is a handle, and the handle survives the copy — which buys write
 * access for every node without changing the signature any node implements.
 *
 * WHAT IT IS NOT: a lock. Two runs of one flow reading, mutating and writing
 * this back would race exactly as OR#2212 described. Safety comes from the
 * scheduler refusing to overlap a flow with itself (see
 * `FlowScheduleService::fireDueFlows()`), which is the same property that makes
 * the shell orchestrator this replaces safe today — one process holding a
 * flock, not an atomic claim. Do not reach for this to coordinate ACROSS
 * flows; use an object write with `onConflict: fail` for that.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use JsonSerializable;

/**
 * A mutable view of one flow's persistent state.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class FlowStateHandle implements JsonSerializable
{

    /**
     * Where a node finds this in the step context.
     *
     * @var string
     */
    public const CONTEXT_KEY = 'flowState';

    /**
     * The stored values.
     *
     * @var array
     */
    private array $values = [];

    /**
     * Whether anything was written since the handle was built.
     *
     * Tracked so a run that only READS state does not rewrite the row. A flow
     * polling every five minutes would otherwise touch its state table on every
     * tick forever, for nothing.
     *
     * @var boolean
     */
    private bool $dirty = false;

    /**
     * Build a handle over stored values.
     *
     * @param array|null $values The values already stored for this flow.
     *
     * @return void
     */
    public function __construct(?array $values=null)
    {
        $this->values = ($values ?? []);

    }//end __construct()

    /**
     * Read one value.
     *
     * @param string $key     The key to read.
     * @param mixed  $default Returned when the key was never written.
     *
     * @return mixed The stored value, or the default.
     */
    public function get(string $key, mixed $default=null): mixed
    {
        return ($this->values[$key] ?? $default);

    }//end get()

    /**
     * Write one value.
     *
     * @param string $key   The key to write.
     * @param mixed  $value The value to store.
     *
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
        $this->dirty        = true;

    }//end set()

    /**
     * Remove one value.
     *
     * @param string $key The key to remove.
     *
     * @return void
     */
    public function forget(string $key): void
    {
        if (array_key_exists($key, $this->values) === false) {
            return;
        }

        unset($this->values[$key]);
        $this->dirty = true;

    }//end forget()

    /**
     * Whether this flow has ever stored the key.
     *
     * Distinct from `get() === null`: a flow may deliberately store null.
     *
     * @param string $key The key to test.
     *
     * @return boolean True when the key exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);

    }//end has()

    /**
     * Whether anything was written since this handle was built.
     *
     * @return boolean True when the state needs persisting.
     */
    public function isDirty(): bool
    {
        return $this->dirty;

    }//end isDirty()

    /**
     * All stored values.
     *
     * @return array The values.
     */
    public function all(): array
    {
        return $this->values;

    }//end all()

    /**
     * Serialise for storage.
     *
     * @return array The values.
     */
    public function jsonSerialize(): array
    {
        return $this->values;

    }//end jsonSerialize()
}//end class
