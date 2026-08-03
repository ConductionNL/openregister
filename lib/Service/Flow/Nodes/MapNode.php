<?php

/**
 * Runs a stored mapping over the item list.
 *
 * The engine had fifteen node types and none of them transformed data, so any
 * flow that needed to reshape a payload had to route it out to an endpoint rule
 * and back. That is the whole reason an integration ended up expressed as an
 * endpoint chain rather than a flow: not because endpoints fitted better, but
 * because the flow engine could not map.
 *
 * The mapping is applied PER ITEM, so one authored mapping reshapes a whole
 * collection without the author drawing a loop — the same shape as FilterNode.
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
 * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\MappingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Transforms the item list through a stored mapping.
 */
class MapNode implements IFlowNode, IFlowNodeConfigKeys
{

    /**
     * Constructor.
     *
     * @param IL10N          $l10n     Translations.
     * @param IURLGenerator  $urls     For the palette icon.
     * @param MappingService $mappings Evaluates the mapping.
     * @param MappingMapper  $mapper   Resolves the mapping by id, uuid or slug.
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urls,
        private readonly MappingService $mappings,
        private readonly MappingMapper $mapper
    ) {

    }//end __construct()

    /**
     * The step type.
     *
     * @return string The id.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function getId(): string
    {
        return 'openregister.map';

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Map');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Reshape each item through a stored mapping.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/rename.svg');

    }//end getIcon()

    /**
     * Mapping transforms data in memory and grants no privilege of its own.
     *
     * @param int $scope The scope constant.
     *
     * @return boolean Whether it is available.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * The config vocabulary of a map step.
     *
     * @return array<int, string> The accepted config keys.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function configKeys(): array
    {
        return ['mapping'];

    }//end configKeys()

    /**
     * Reject a map step that names no mapping.
     *
     * A map with no mapping would pass every item through untouched — a step
     * that does nothing while looking like it does something, which is the
     * failure this whole programme exists to remove.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When no mapping is named.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function validateConfig(array $config): void
    {
        $mapping = trim((string) ($config['mapping'] ?? ''));
        if ($mapping === '') {
            throw new UnexpectedValueException($this->l10n->t('A map step needs a mapping.'));
        }

    }//end validateConfig()

    /**
     * Reshape every item through the mapping.
     *
     * An unresolvable mapping THROWS rather than returning the items unchanged.
     * Passing them through would record the step as completed while nothing was
     * transformed, and a downstream step reading the un-mapped shape would fail
     * somewhere else entirely — the error would surface far from its cause.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata.
     *
     * @return array The transformed items.
     *
     * @throws RuntimeException When the mapping cannot be resolved or applied.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    public function execute(array $items, array $config, array $context): array
    {
        $reference = trim((string) ($config['mapping'] ?? ''));
        $mapping   = $this->resolve(reference: $reference);

        $mapped = [];
        foreach ($items as $index => $item) {
            $input = (array) ($item[FlowItems::JSON] ?? []);

            try {
                $result = $this->mappings->executeMapping(mapping: $mapping, input: $input);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    sprintf(
                        'Mapping "%s" failed on item %d: %s',
                        $reference,
                        $index,
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }

            $mapped[] = FlowItems::item(
                json: $result,
                binary: (array) ($item[FlowItems::BINARY] ?? []),
                fromItemIndex: $index
            );
        }//end foreach

        return $mapped;

    }//end execute()

    /**
     * Resolve a mapping by numeric id, uuid, slug or reference.
     *
     * Authors name mappings by whichever identifier they have in front of them,
     * and a flow definition is portable between instances where the numeric id
     * differs — so slug and reference must resolve too, or an exported flow
     * breaks on import while looking correct.
     *
     * @param string $reference The mapping identifier.
     *
     * @return \OCA\OpenRegister\Db\Mapping The resolved mapping.
     *
     * @throws RuntimeException When no mapping matches.
     *
     * @spec openspec/changes/flow-parity-mapping-and-webhooks/specs/flow-mapping/spec.md
     */
    private function resolve(string $reference)
    {
        if (ctype_digit($reference) === true) {
            try {
                return $this->mapper->find((int) $reference);
            } catch (DoesNotExistException $e) {
                throw new RuntimeException(
                    sprintf('No mapping with id "%s".', $reference),
                    0,
                    $e
                );
            }
        }

        try {
            $byRef = $this->mapper->findByRef($reference);
        } catch (Throwable $e) {
            $byRef = [];
        }

        if (empty($byRef) === false) {
            return $byRef[0];
        }

        throw new RuntimeException(
            sprintf('No mapping matches "%s" by id, uuid, slug or reference.', $reference)
        );

    }//end resolve()

}//end class
