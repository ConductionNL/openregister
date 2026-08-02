<?php

/**
 * Set, rename and remove fields on every item.
 *
 * The equivalent of n8n's "Edit Fields (Set)", and the node that proves the
 * item model end to end: it is configured once and applied per item, without
 * the author drawing a loop.
 *
 * Also the reference implementation of {@see IFlowNode}. An app contributing a
 * node type has this to copy, and needs nothing else — no dispatcher, no
 * engine, no graph walking.
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
 *
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowExpression;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Reshapes each item's record.
 */
class SetFieldsNode implements IFlowNode, IFlowNodeConfigKeys
{
    /**
     * Constructor.
     *
     * @param IL10N         $l10n Translations.
     * @param IURLGenerator $urls For the palette icon.
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls
    ) {

    }//end __construct()

    /**
     * The step type.
     *
     * @return string The id.
     */
    public function getId(): string
    {
        return 'openregister.set-fields';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Edit fields');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Set, rename or remove fields on every item passing through.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/rename.svg');

    }//end getIcon()

    /**
     * Available in both scopes — reshaping data grants no privilege.
     *
     * @param int $scope The scope constant.
     *
     * @return bool Whether it is available.
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * The config vocabulary of a set-fields step.
     *
     * `fields` is NOT here. It is the plausible-looking name an author reaches
     * for, it is what `openregister.object-write` calls its payload, and
     * hydra-analyze-verdicts shipped a step using it that set nothing at all.
     *
     * @return array<int, string> The accepted config keys.
     *
     * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
     */
    public function configKeys(): array
    {
        return ['set', 'compute', 'rename', 'remove', 'keepOnlySet'];

    }//end configKeys()

    /**
     * Reject a configuration that would do nothing.
     *
     * A step that silently does nothing is worse than one that refuses to
     * save: the author believes their flow reshapes data and it does not.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When nothing is configured.
     */
    public function validateConfig(array $config): void
    {
        $set     = (array) ($config['set'] ?? []);
        $compute = (array) ($config['compute'] ?? []);
        $rename  = (array) ($config['rename'] ?? []);
        $remove  = (array) ($config['remove'] ?? []);

        if ($set === [] && $compute === [] && $rename === [] && $remove === []) {
            throw new UnexpectedValueException(
                $this->l10n->t('Choose at least one field to set, compute, rename or remove.')
            );
        }

        // An empty or blank path is refused when the flow is SAVED. It can only
        // write to a key nothing reads, and the author should not have to find
        // that out from an item that came back looking fine.
        $this->assertPathsAreNamed(bag: $set, key: 'set');
        $this->assertPathsAreNamed(bag: $compute, key: 'compute');

        // A malformed expression is caught HERE, when the flow is saved, rather
        // than evaluating to null on every item at 03:00 — `FlowExpression`
        // swallows a broken rule and returns null, which is indistinguishable
        // from a field that legitimately resolved to nothing.
        foreach ($compute as $field => $logic) {
            if (FlowExpression::isValid(logic: $logic) === false) {
                throw new UnexpectedValueException(
                    $this->l10n->t('The expression for computed field "%s" is not valid.', [(string) $field])
                );
            }
        }

    }//end validateConfig()

    /**
     * Apply the configured reshaping to every item.
     *
     * Order is remove, then rename, then set, then compute. Rename before set
     * so setting a field the author also renamed away is not silently undone,
     * and remove first so a rename can legitimately reuse a removed name.
     * Compute last so a derived value can build on one just substituted.
     *
     * `keepOnlySet` mirrors n8n's option of the same name: drop everything the
     * step did not explicitly set, for building a clean payload.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The output items, one per input item.
     */
    public function execute(array $items, array $config, array $context): array
    {
        $set         = (array) ($config['set'] ?? []);
        $compute     = (array) ($config['compute'] ?? []);
        $rename      = (array) ($config['rename'] ?? []);
        $remove      = (array) ($config['remove'] ?? []);
        $keepOnlySet = (($config['keepOnlySet'] ?? false) === true);
        $count       = count($items);

        $out = [];
        foreach ($items as $index => $item) {
            $json = (array) ($item[FlowItems::JSON] ?? []);

            foreach ($remove as $field) {
                unset($json[(string) $field]);
            }

            foreach ($rename as $from => $to) {
                $from = (string) $from;
                $to   = (string) $to;
                if ($from === '' || $to === '' || array_key_exists($from, $json) === false) {
                    continue;
                }

                $json[$to] = $json[$from];
                unset($json[$from]);
            }

            if ($keepOnlySet === true) {
                $json = [];
            }

            // Values are rendered against the item, the way every other node
            // resolves its authored config — `source-call` its endpoint and
            // body, `agent-step` its prompt, `object-write` its fields.
            //
            // This node could previously only write CONSTANTS: `{{retries}}`
            // was stored as the literal seven characters. That makes the one
            // node whose entire job is setting fields the only one that cannot
            // refer to the item it is setting them on — and it is also the
            // reference implementation other node authors copy.
            //
            // A value that is exactly one placeholder keeps its TYPE, so a
            // counter stays a number and a list stays a list.
            // The PATH is templated as well as the value. Without this the one
            // node whose job is setting fields could refer to the item in its
            // values but not in its positions, so no flow could write to a
            // place the run decides — `stages.{{stage}}.status` created a key
            // literally called `stages.{{stage}}.status`. Same invisible no-op
            // as the dotted-path bug, same fix.
            $this->applySet(json: $json, set: $set);

            // `compute` is the same idea as `set` for values that have to be
            // DERIVED rather than substituted. `{{retries}}` can copy a
            // counter; nothing could add one to it, because a template
            // substitutes and does not calculate.
            //
            // That gap was not academic — it is what stopped hydra's
            // retry/escalation flow: read the count, increment, store. The
            // read and the store were expressible and the `+ 1` was not, so
            // the counter sat at 0 and a run "retried" forever.
            //
            // The expression language is the one the routers already use
            // (`FlowExpression`), so an author who can write a branch condition
            // can write this, and the same `{"var": "json.…"}` paths work in
            // both. It is applied AFTER `set`, so a computed value can build on
            // one that was just substituted.
            // `compute` gets the same path treatment as `set`, deliberately.
            // It used to write FLAT — `$json['a.b'] = …` — so it could express
            // neither a nested position nor a templated one, and an author who
            // learned the path rules from `set` got a silently different result
            // from the node's other half.
            $this->applyCompute(
                json: $json,
                compute: $compute,
                itemIndex: (int) $index,
                itemCount: $count,
                context: $context
            );

            // Provenance: this item's own index, one in and one out, so the
            // chain back to the input that caused it stays intact.
            $out[] = FlowItems::item(
                json: $json,
                binary: (array) ($item[FlowItems::BINARY] ?? []),
                fromItemIndex: $index
            );
        }//end foreach

        return $out;

    }//end execute()

    /**
     * Refuse a configured field path that names nothing.
     *
     * @param array  $bag The `set` or `compute` mapping.
     * @param string $key The configuration key, for the message.
     *
     * @return void
     *
     * @throws UnexpectedValueException When a path is empty or blank.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    private function assertPathsAreNamed(array $bag, string $key): void
    {
        foreach (array_keys($bag) as $field) {
            if (trim((string) $field) === '') {
                throw new UnexpectedValueException(
                    $this->l10n->t('A "%s" entry has an empty field path.', [$key])
                );
            }
        }

    }//end assertPathsAreNamed()

    /**
     * Apply the `set` mapping to one item's record.
     *
     * Both halves of a pair are resolved against the record as it stands, and
     * the record is mutated as we go, so a later entry can read what an earlier
     * one wrote. That ordering is part of the contract.
     *
     * @param array $json The item's record, modified in place.
     * @param array $set  The configured field mapping.
     *
     * @return void
     *
     * @throws UnexpectedValueException When a path segment cannot be rendered.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    private function applySet(array &$json, array $set): void
    {
        foreach ($set as $field => $value) {
            self::assign(
                json: $json,
                path: $this->renderPath(path: (string) $field, json: $json),
                value: FlowValueTemplate::render(value: $value, json: $json)
            );
        }

    }//end applySet()

    /**
     * Apply the `compute` mapping to one item's record.
     *
     * @param array $json      The item's record, modified in place.
     * @param array $compute   The configured expression mapping.
     * @param int   $itemIndex This item's position in the batch.
     * @param int   $itemCount The batch size.
     * @param array $context   The run context.
     *
     * @return void
     *
     * @throws UnexpectedValueException When a path segment cannot be rendered.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    private function applyCompute(
        array &$json,
        array $compute,
        int $itemIndex,
        int $itemCount,
        array $context
    ): void {
        foreach ($compute as $field => $logic) {
            self::assign(
                json: $json,
                path: $this->renderPath(path: (string) $field, json: $json),
                value: FlowExpression::evaluate(
                    logic: $logic,
                    data: FlowExpression::dataFor(
                        item: [FlowItems::JSON => $json],
                        itemIndex: $itemIndex,
                        itemCount: $itemCount,
                        context: $context
                    )
                )
            );
        }

    }//end applyCompute()

    /**
     * Write a value at a possibly-dotted path, creating the containers it needs.
     *
     * `{{dotted.path}}` has always READ through nested structures; writing one
     * did not, so `"entry.owner"` created a top-level key literally CALLED
     * `entry.owner` beside any real `entry`. Nothing failed — the flow ran, the
     * item came out, and the object the author meant to build was simply never
     * there. That is indistinguishable from success until something downstream
     * reads the shape and finds it empty.
     *
     * It matters because composing a NESTED record is the reason a flow sets
     * fields at all: hydra's run record is `cycles[].stages[]`, and without this
     * a flow can only ever produce a flat bag. `compute` is no help — JsonLogic
     * evaluates expressions and cannot construct an object.
     *
     * A segment whose current value is not an array is REPLACED by a container.
     * The alternative — merging into a scalar — has no meaning, and silently
     * skipping would reintroduce the same invisible no-op this fixes.
     *
     * The path reaching here is already rendered, and a rendered segment can
     * never contain a `.` (renderPath() refuses one), so splitting again is
     * safe: the nesting is exactly what the configuration spelled out.
     *
     * @param array  $json  The item's record, modified in place.
     * @param string $path  The field name, optionally dotted.
     * @param mixed  $value The value to write.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    private static function assign(array &$json, string $path, mixed $value): void
    {
        if (str_contains($path, '.') === false) {
            $json[$path] = $value;

            return;
        }

        $segments = explode('.', $path);
        $last     = array_pop($segments);
        $cursor   = &$json;

        foreach ($segments as $segment) {
            if (isset($cursor[$segment]) === false || is_array($cursor[$segment]) === false) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor[$last] = $value;
        unset($cursor);

    }//end assign()

    /**
     * Render a configured path against the item, one segment at a time.
     *
     * A path is a POSITION, not a value, so it cannot be rendered the way a
     * value is. Rendering the whole string and then splitting it would let item
     * data decide the SHAPE of the path: a `{{stage}}` that happened to contain
     * a dot would silently become two levels of nesting, writing somewhere the
     * author never named. That is a data-controlled write position, and it is
     * the sort of thing that is discovered much later and from the wrong end.
     *
     * So the literal path is split on `.` FIRST, and each segment is rendered
     * on its own. That makes the structure a property of the CONFIGURATION and
     * only the segment names a property of the data.
     *
     * Two decisions taken explicitly rather than by accident:
     *
     *  - **A dot in a rendered segment is REFUSED, not escaped.** There is no
     *    escape syntax anywhere in this node — `assign()` splits on `.`
     *    unconditionally — so an escaped segment would produce a key no other
     *    node could address, including this one's own `rename` and `remove`.
     *    Inventing half an escape mechanism here would be worse than saying no.
     *  - **A segment that renders EMPTY is REFUSED.** Writing to `""` is a
     *    silent write to a key nothing reads, which is exactly the failure this
     *    change exists to remove. An unresolved placeholder is a broken path,
     *    not a path to an empty name.
     *
     * A path containing no placeholder renders to itself, so every existing
     * configuration is unaffected.
     *
     * @param string $path The configured path, optionally dotted and templated.
     * @param array  $json The item's record, for placeholder resolution.
     *
     * @return string The rendered path.
     *
     * @throws UnexpectedValueException When a segment renders empty or introduces a dot.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    private function renderPath(string $path, array $json): string
    {
        // Untouched fast path: nothing to render, so nothing can go wrong.
        if (str_contains($path, '{{') === false) {
            return $path;
        }

        $segments = explode('.', $path);
        $rendered = [];

        foreach ($segments as $segment) {
            if (str_contains($segment, '{{') === false) {
                $rendered[] = $segment;
                continue;
            }

            $value = FlowValueTemplate::render(value: $segment, json: $json);

            // An unresolved placeholder renders to null. That is a BROKEN path,
            // not a path to a field called "": say so in those terms, because
            // the author's mistake is the missing value, not the empty name.
            if ($value === null) {
                throw new UnexpectedValueException(
                    $this->l10n->t(
                        'The path segment "%s" resolved to nothing; a field position is never empty.',
                        [$segment]
                    )
                );
            }

            // A container has no meaning as a key, and casting one would give a
            // key like "Array" or a JSON blob.
            if (is_scalar($value) === false) {
                throw new UnexpectedValueException(
                    $this->l10n->t(
                        'The path segment "%s" resolved to a value that cannot name a field.',
                        [$segment]
                    )
                );
            }

            $value = trim((string) $value);

            if ($value === '') {
                throw new UnexpectedValueException(
                    $this->l10n->t(
                        'The path segment "%s" resolved to nothing; a field position is never empty.',
                        [$segment]
                    )
                );
            }

            if (str_contains($value, '.') === true) {
                throw new UnexpectedValueException(
                    $this->l10n->t(
                        'The path segment "%1$s" resolved to "%2$s", which contains a dot. '
                        .'A rendered segment names one field and cannot introduce nesting.',
                        [$segment, $value]
                    )
                );
            }

            $rendered[] = $value;
        }//end foreach

        return implode('.', $rendered);

    }//end renderPath()
}//end class
