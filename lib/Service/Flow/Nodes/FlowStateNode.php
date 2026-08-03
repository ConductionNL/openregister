<?php

/**
 * Reads and writes the state a flow keeps between runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStateHandle;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;

/**
 * The node that makes flow state usable from a graph.
 *
 * A scheduled flow starts blank on every tick. `FlowStateHandle` gives it
 * somewhere to remember things; this node is how a graph reaches that without
 * writing PHP.
 *
 * The interesting operation is `claim`. A capacity cap — hydra's "at most ten
 * pipelines at once", a booking, a lease — is a map of named slots where a free
 * one must be taken by exactly one holder. Expressed with `get` and `set` that
 * is a read-modify-write, and two runs doing it concurrently lose one of the
 * claims. `claim` does the whole thing in one step, inside a single run, and
 * emits `claimed: false` rather than pretending it succeeded.
 *
 * ⚠️ What makes that SAFE is that a scheduled flow never overlaps itself
 * (`FlowScheduleService::fireDueFlows()` skips a tick whose previous run is
 * still going). That is the same property the shell orchestrator this replaces
 * relies on — one supervisor holding a flock, whose own slot bookkeeping is a
 * plain check-then-write and is safe only because nothing runs beside it.
 * This node is NOT a cross-flow lock: for that, write an object with
 * `onConflict: fail`, where the database arbitrates.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */
class FlowStateNode implements IFlowNode, IFlowNodeConfigKeys
{

    /**
     * Read one key into the item.
     *
     * @var string
     */
    private const OP_GET = 'get';

    /**
     * Write one key.
     *
     * @var string
     */
    private const OP_SET = 'set';

    /**
     * Remove one key.
     *
     * @var string
     */
    private const OP_FORGET = 'forget';

    /**
     * Take a free slot from a map, or report that none was free.
     *
     * @var string
     */
    private const OP_CLAIM = 'claim';

    /**
     * Give a held slot back.
     *
     * @var string
     */
    private const OP_RELEASE = 'release';

    /**
     * Accepted operations.
     *
     * @var string[]
     */
    private const OPERATIONS = [
        self::OP_GET,
        self::OP_SET,
        self::OP_FORGET,
        self::OP_CLAIM,
        self::OP_RELEASE,
    ];

    /**
     * Constructor.
     *
     * @param IL10N         $l10n Translations.
     * @param IURLGenerator $urls Icon paths.
     *
     * @return void
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls
    ) {

    }//end __construct()

    /**
     * The type identifier.
     *
     * @return string The id.
     */
    public function getId(): string
    {
        return 'openregister.flow-state';

    }//end getId()

    /**
     * The display name.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Flow state');

    }//end getDisplayName()

    /**
     * The description.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Remember something between runs of this flow — a counter, a cursor, or a table of slots.');

    }//end getDescription()

    /**
     * The icon.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/history.svg');

    }//end getIcon()

    /**
     * Availability.
     *
     * @param integer $scope The scope constant.
     *
     * @return boolean Whether it is available.
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * The config vocabulary of a flow-state step, across all five operations.
     *
     * Deliberately the UNION rather than a per-operation set: which keys are
     * required for which operation is `validateConfig()`'s question, and it
     * answers it with a message naming the operation. This method answers only
     * "could this node ever read that key".
     *
     * @return array<int, string> The accepted config keys.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function configKeys(): array
    {
        return [
            'operation',
            'key',
            'as',
            'default',
            'value',
            'from',
            'record',
            'slots',
            'capacity',
            'holder',
            'slot',
        ];

    }//end configKeys()

    /**
     * Validate the authored configuration.
     *
     * @param array $config The step's configuration.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the configuration cannot run.
     */
    public function validateConfig(array $config): void
    {
        $operation = (string) ($config['operation'] ?? '');
        if (in_array($operation, self::OPERATIONS, true) === false) {
            throw new InvalidArgumentException(
                sprintf('operation must be one of: %s', implode(', ', self::OPERATIONS))
            );
        }

        if ($operation === self::OP_CLAIM || $operation === self::OP_RELEASE) {
            if (trim((string) ($config['slots'] ?? '')) === '') {
                throw new InvalidArgumentException('slots is required for claim and release');
            }

            if ($operation === self::OP_CLAIM && (int) ($config['capacity'] ?? 0) < 1) {
                throw new InvalidArgumentException('capacity must be at least 1 for claim');
            }

            return;
        }

        if (trim((string) ($config['key'] ?? '')) === '') {
            throw new InvalidArgumentException('key is required');
        }

    }//end validateConfig()

    /**
     * Run the step.
     *
     * @param array $items   The input items.
     * @param array $config  The step's configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The output items.
     *
     * @throws InvalidArgumentException When flow state is unavailable.
     */
    public function execute(array $items, array $config, array $context): array
    {
        $state = ($context[FlowStateHandle::CONTEXT_KEY] ?? null);
        if (($state instanceof FlowStateHandle) === false) {
            // A run with no flow id has no state to keep. Failing loudly beats
            // silently behaving as though every key were empty, which would
            // make a capacity cap wave everything through.
            throw new InvalidArgumentException('This run has no flow state — flow state needs a flow-scoped run.');
        }

        $operation = (string) ($config['operation'] ?? '');
        $output    = [];

        foreach ($items as $index => $item) {
            $json = (array) ($item['json'] ?? []);

            $json = match ($operation) {
                self::OP_GET => $this->doGet(state: $state, config: $config, json: $json),
                self::OP_SET => $this->doSet(state: $state, config: $config, json: $json),
                self::OP_FORGET => $this->doForget(state: $state, config: $config, json: $json),
                self::OP_CLAIM => $this->doClaim(state: $state, config: $config, json: $json),
                self::OP_RELEASE => $this->doRelease(state: $state, config: $config, json: $json),
                default => $json,
            };

            $output[] = FlowItems::item(
                json: $json,
                binary: (array) ($item['binary'] ?? []),
                fromItemIndex: (int) $index
            );
        }//end foreach

        return $output;

    }//end execute()

    /**
     * Read one key into the item.
     *
     * @param FlowStateHandle $state  The state handle.
     * @param array           $config The step's configuration.
     * @param array           $json   The item's json.
     *
     * @return array The item's json.
     */
    private function doGet(FlowStateHandle $state, array $config, array $json): array
    {
        $key       = (string) $config['key'];
        $as        = (string) ($config['as'] ?? $key);
        $json[$as] = $state->get($key, ($config['default'] ?? null));

        return $json;

    }//end doGet()

    /**
     * Write one key.
     *
     * @param FlowStateHandle $state  The state handle.
     * @param array           $config The step's configuration.
     * @param array           $json   The item's json.
     *
     * @return array The item's json.
     */
    private function doSet(FlowStateHandle $state, array $config, array $json): array
    {
        $key = (string) $config['key'];

        // `from` reads the value off the item, so a step can store something it
        // just computed; `value` is a literal for authored constants.
        $value = ($config['value'] ?? null);
        if (isset($config['from']) === true) {
            $value = ($json[(string) $config['from']] ?? null);
        }

        $state->set($key, $value);

        return $json;

    }//end doSet()

    /**
     * Remove one key.
     *
     * @param FlowStateHandle $state  The state handle.
     * @param array           $config The step's configuration.
     * @param array           $json   The item's json.
     *
     * @return array The item's json.
     */
    private function doForget(FlowStateHandle $state, array $config, array $json): array
    {
        $state->forget((string) $config['key']);

        return $json;

    }//end doForget()

    /**
     * Take a free slot, or report that none was free.
     *
     * Emits `claimed` and `slot` onto the item so a router can branch on them.
     * A caller that ignores `claimed` and proceeds anyway has overrun its own
     * cap — which is why this reports rather than throws: the flow author
     * decides whether "no capacity" means wait, stop or escalate.
     *
     * WHAT A SLOT HOLDS, AND WHY IT IS A RECORD
     * -----------------------------------------
     * An occupied slot holds `{holder, since, …}` rather than a bare holder
     * string, and every slot from 1 to `capacity` is present — a free one as
     * `null`. Both are so `GET /api/flow/{flowId}/state` is answerable on its
     * own: "what is running right now, and since when" needs a timestamp, and
     * "slot 3 of 10 is free" needs slot 3 to EXIST. The previous shape stored a
     * scalar and omitted unclaimed slots entirely, so a reader could not tell an
     * empty slot from one outside the cap, and had to be told the capacity
     * separately — which is the cap living in two places again.
     *
     * `record` names item fields to copy onto the slot, so a caller decides what
     * "what is running" means for its own flow (hydra: stage, repo, issue).
     * A named field the item does not carry is recorded as null rather than
     * skipped, so the shape is the same for every slot.
     *
     * @param FlowStateHandle $state  The state handle.
     * @param array           $config The step's configuration.
     * @param array           $json   The item's json.
     *
     * @return array The item's json.
     */
    private function doClaim(FlowStateHandle $state, array $config, array $json): array
    {
        $slotsKey = (string) $config['slots'];
        $capacity = (int) $config['capacity'];
        $holder   = (string) ($json[(string) ($config['holder'] ?? 'holder')] ?? '');

        $slots = $this->slotTable(stored: (array) $state->get($slotsKey, []), capacity: $capacity);

        // A slot is free when it holds nothing. Slots are numbered from 1 so
        // the value reads naturally in a dashboard ("slot 3 of 10").
        for ($n = 1; $n <= $capacity; $n++) {
            $slot = (string) $n;
            if (($slots[$slot] ?? null) !== null) {
                continue;
            }

            $held = [
                'holder' => null,
                'since'  => (new DateTime())->format(DateTimeInterface::ATOM),
            ];
            if ($holder !== '') {
                $held['holder'] = $holder;
            }

            foreach ((array) ($config['record'] ?? []) as $field) {
                $field = (string) $field;
                if ($field === '' || $field === 'holder' || $field === 'since') {
                    continue;
                }

                $held[$field] = ($json[$field] ?? null);
            }

            $slots[$slot] = $held;
            $state->set($slotsKey, $slots);

            $json['claimed'] = true;
            $json['slot']    = $n;

            return $json;
        }//end for

        // Persist even when nothing was claimed: the table may have just been
        // materialised to full capacity for the first time, and a dashboard
        // reading "0 of 10 free" is more useful than one reading nothing at all.
        $state->set($slotsKey, $slots);

        $json['claimed'] = false;
        $json['slot']    = null;

        return $json;

    }//end doClaim()

    /**
     * The slot table as stored, normalised to every slot from 1 to capacity.
     *
     * Free slots are `null` and present. Values written by an older revision —
     * a bare holder string, or `true` — are carried forward into the record
     * shape rather than dropped, so a flow that was mid-run when this changed
     * does not lose track of what it was holding.
     *
     * @param array $stored   The stored table.
     * @param int   $capacity The configured capacity.
     *
     * @return array<string, array|null> The normalised table.
     */
    private function slotTable(array $stored, int $capacity): array
    {
        $table = [];
        for ($n = 1; $n <= $capacity; $n++) {
            $slot  = (string) $n;
            $value = ($stored[$slot] ?? null);

            if ($value === null) {
                $table[$slot] = null;
                continue;
            }

            $table[$slot] = $this->slotRecord(value: $value);
        }//end for

        // A slot beyond the current capacity is still OCCUPIED by somebody, and
        // dropping it would let the flow exceed a cap the operator has just
        // lowered. Kept until it is released.
        foreach ($stored as $slot => $value) {
            if (array_key_exists((string) $slot, $table) === false && $value !== null) {
                $table[(string) $slot] = $this->slotRecord(value: $value);
            }
        }

        return $table;

    }//end slotTable()

    /**
     * One stored slot value as a record.
     *
     * An array is already in shape. A scalar is what an older revision wrote —
     * the holder string, or `true` when the claim named no holder — and becomes
     * a record with a null `since`, because that moment was never recorded.
     *
     * @param mixed $value The stored value.
     *
     * @return array The record.
     */
    private function slotRecord(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        $holder = null;
        if ($value !== true) {
            $holder = (string) $value;
        }

        return ['holder' => $holder, 'since' => null];

    }//end slotRecord()

    /**
     * Give a held slot back.
     *
     * @param FlowStateHandle $state  The state handle.
     * @param array           $config The step's configuration.
     * @param array           $json   The item's json.
     *
     * @return array The item's json.
     */
    private function doRelease(FlowStateHandle $state, array $config, array $json): array
    {
        $slotsKey = (string) $config['slots'];
        $slots    = (array) $state->get($slotsKey, []);

        // Release by slot number when the caller knows it, otherwise by holder
        // — a stage that crashed and is being cleaned up knows who it was, not
        // which slot it got.
        $slot = ($json[(string) ($config['slot'] ?? 'slot')] ?? null);
        if ($slot !== null && array_key_exists((string) $slot, $slots) === true) {
            $slots[(string) $slot] = null;
            $state->set($slotsKey, $slots);
            $json['released'] = true;

            return $json;
        }

        // By holder. This reads INTO the slot record rather than comparing the
        // slot's value, which is what a bare `array_search($holder, $slots)`
        // did — correct only while a slot held the holder string itself. Now a
        // slot holds `{holder, since, …}`, so a value comparison would never
        // match and a crashed stage's slot would leak forever.
        $holder = (string) ($json[(string) ($config['holder'] ?? 'holder')] ?? '');
        if ($holder !== '') {
            foreach ($slots as $key => $value) {
                $held = $value;
                if (is_array($value) === true) {
                    $held = ($value['holder'] ?? null);
                }

                if ($held === null || $held === true || (string) $held !== $holder) {
                    continue;
                }

                $slots[(string) $key] = null;
                $state->set($slotsKey, $slots);
                $json['released'] = true;

                return $json;
            }
        }

        $json['released'] = false;

        return $json;

    }//end doRelease()
}//end class
