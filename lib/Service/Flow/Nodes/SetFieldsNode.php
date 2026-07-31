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

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Reshapes each item's record.
 */
class SetFieldsNode implements IFlowNode
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
        $set    = (array) ($config['set'] ?? []);
        $rename = (array) ($config['rename'] ?? []);
        $remove = (array) ($config['remove'] ?? []);

        if ($set === [] && $rename === [] && $remove === []) {
            throw new UnexpectedValueException(
                $this->l10n->t('Choose at least one field to set, rename or remove.')
            );
        }

    }//end validateConfig()

    /**
     * Apply the configured reshaping to every item.
     *
     * Order is remove, then rename, then set. Rename before set so setting a
     * field the author also renamed away is not silently undone, and remove
     * first so a rename can legitimately reuse a removed name.
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
        $rename      = (array) ($config['rename'] ?? []);
        $remove      = (array) ($config['remove'] ?? []);
        $keepOnlySet = (($config['keepOnlySet'] ?? false) === true);

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
            foreach ($set as $field => $value) {
                $json[(string) $field] = FlowValueTemplate::render(value: $value, json: $json);
            }

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
}//end class
