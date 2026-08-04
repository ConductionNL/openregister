<?php

/**
 * The run-level value a flow carries across its steps.
 *
 * The item list is the data channel: records travel there, one per item, and a
 * step that fans out or filters changes how many there are. The token is the
 * other thing a run needs — a value that belongs to the RUN rather than to any
 * record. A correlation id, a reference resolved once and reused, the amended
 * request/response envelope a synchronisation threads through its whole
 * pipeline. Put a per-record value here and it stops being per-record on the
 * first fan-out, which is the same trap `FlowItems` documents from the other
 * side.
 *
 * A node reaches it at `$context['token']` and may WRITE to it, which is the
 * point. `IFlowNode::execute()` takes `$context` by value, so a plain array
 * could only ever be read; an object is a handle, and the handle survives the
 * copy. That is why this is a class and not an array — it buys write access for
 * every node without changing the signature any node implements, and ten
 * registered nodes plus two leaf apps therefore need no change at all.
 *
 * It survives a suspension. `FlowRunService` serialises it into the run's
 * `context` JSON when the run stops and rehydrates it when the run resumes, so
 * a value written before a `Wait` is still there days later when the worker
 * picks the run back up.
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
 * @spec openspec/changes/openregister-flow-executionmode-and-token/specs/flow-token/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use JsonSerializable;

/**
 * A mutable bag of run-level values, shared by reference across a run's steps.
 */
final class FlowToken implements JsonSerializable
{

    /**
     * The context key the token is reachable at.
     *
     * @var string
     */
    public const CONTEXT_KEY = 'token';

    /**
     * The values held by this token.
     *
     * @var array<string, mixed>
     */
    private array $values = [];

    /**
     * Build a token over a set of values.
     *
     * @param array<string, mixed> $values The initial values.
     */
    public function __construct(array $values=[])
    {
        $this->values = $values;

    }//end __construct()

    /**
     * Read a value.
     *
     * @param string $key     The value's key.
     * @param mixed  $default Returned when the key is not held.
     *
     * @return mixed The held value, or the default.
     */
    public function get(string $key, mixed $default=null): mixed
    {
        return ($this->values[$key] ?? $default);

    }//end get()

    /**
     * Write a value.
     *
     * @param string $key   The value's key.
     * @param mixed  $value The value to hold.
     *
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;

    }//end set()

    /**
     * Whether a key is held.
     *
     * @param string $key The value's key.
     *
     * @return boolean Whether the key is held.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);

    }//end has()

    /**
     * Every value held.
     *
     * @return array<string, mixed> The values.
     */
    public function all(): array
    {
        return $this->values;

    }//end all()

    /**
     * Merge another set of values in, the incoming values winning on conflict.
     *
     * This is what a waited-on sub-flow's return uses: the child ran later and
     * is the more specific writer, so where both hold a key the child's value
     * is the one that survives.
     *
     * @param array<string, mixed> $values The values to merge in.
     *
     * @return void
     */
    public function merge(array $values): void
    {
        $this->values = array_merge($this->values, $values);

    }//end merge()

    /**
     * Build a token from whatever a stored context happens to hold.
     *
     * Deliberately total: a run persisted before tokens existed holds nothing, a
     * corrupted column holds a scalar, and a run being handed straight back holds
     * an object already. None of those is a reason to fail a run, so each
     * resolves to a usable token.
     *
     * @param mixed $stored The stored value, of any shape.
     *
     * @return self The token.
     */
    public static function fromArray(mixed $stored): self
    {
        if ($stored instanceof self === true) {
            return $stored;
        }

        if (is_array($stored) === false) {
            return new self();
        }

        // A JSON round trip turns a list into an array with integer keys, which
        // is not a value bag; only string-keyed entries are meaningful here.
        $values = [];
        foreach ($stored as $key => $value) {
            if (is_string($key) === true) {
                $values[$key] = $value;
            }
        }

        return new self($values);

    }//end fromArray()

    /**
     * The storable form.
     *
     * @return array<string, mixed> The values.
     */
    public function jsonSerialize(): array
    {
        return $this->values;

    }//end jsonSerialize()
}//end class
