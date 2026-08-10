<?php

/**
 * Flows as a shareable configuration type.
 *
 * This is the reframed #2065 "integration network": a flow is not a special kind
 * of shareable thing, it is one type among many that plug into the fleet's one
 * federated-config seam. A flow is a row in the one native flow store, so
 * serialising it is stripping the instance-specific fields and keeping its
 * portable shape (name, trigger, nodes, edges, cron); installing it is writing
 * that back into the store.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Config\Types
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Config\Types;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\Flow\FlowService;
use Throwable;

/**
 * Serialises and installs flows through the federated-config engine.
 */
class FlowShareableConfigType implements IShareableConfigType
{

    /**
     * The portable fields a flow carries when shared (never id/uuid/owner/org).
     *
     * @var array<int, string>
     */
    private const PORTABLE_FIELDS = [
        'name',
        'description',
        'enabled',
        'trigger',
        'triggerRegister',
        'triggerSchema',
        'cron',
        'nodes',
        'edges',
        'limits',
    ];

    /**
     * Constructor.
     *
     * The mapper is the WRITE side (install), which runs the flows this
     * instance is importing. The READ side goes through `FlowService`, because
     * `FlowMapper::findByUuid()` applies no organisation scoping — the engine
     * needs that (a queue worker has no session), a request does not.
     *
     * @param FlowMapper  $mapper Writes flow definitions on install.
     * @param FlowService $flows  Reads flows the CALLER is allowed to see.
     */
    public function __construct(
        private readonly FlowMapper $mapper,
        private readonly FlowService $flows
    ) {

    }//end __construct()

    /**
     * The type id.
     *
     * @return string The id.
     */
    public function getId(): string
    {
        return 'openregister.flows';

    }//end getId()

    /**
     * The display name.
     *
     * @return string The name.
     */
    public function getDisplayName(): string
    {
        return 'Flows';

    }//end getDisplayName()

    /**
     * The discovery topic.
     *
     * @return string The topic.
     */
    public function getTopic(): string
    {
        return 'openregister-flow';

    }//end getTopic()

    /**
     * Package the selected flows into a portable bundle.
     *
     * `$selection` is `{flowIds: [uuid, ...]}`. Each flow is reduced to its
     * portable fields — no id, uuid, owner or organisation.
     *
     * Resolution goes through `FlowService::find()`, which refuses a flow that
     * is not the caller's with the same "no such flow" as one that does not
     * exist. This used to call `FlowMapper::findByUuid()` directly, so a caller
     * who named another organisation's flow uuid got that flow's full
     * definition — nodes, edges, cron and all — in the bundle.
     *
     * @param array $selection `{flowIds: [...]}`.
     *
     * @return array `{type, version, flows: [...]}`.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function serialise(array $selection): array
    {
        $flows = [];
        foreach ((array) ($selection['flowIds'] ?? []) as $flowId) {
            try {
                $flow = $this->flows->find(uuid: (string) $flowId);
            } catch (Throwable $e) {
                continue;
            }

            $flows[] = $this->portable(data: $flow->jsonSerialize());
        }

        return [
            'type'    => $this->getId(),
            'version' => '1.0',
            'flows'   => $flows,
        ];

    }//end serialise()

    /**
     * Install the flows in a bundle into this instance's flow store.
     *
     * @param array $bundle A bundle produced by this type.
     *
     * @return array `{installed: [uuid, ...]}`.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    public function deserialise(array $bundle): array
    {
        $installed = [];
        foreach ((array) ($bundle['flows'] ?? []) as $incoming) {
            if (is_array($incoming) === false) {
                continue;
            }

            $portable = $this->portable(data: $incoming);

            $flow = new Flow();
            $flow->setUuid($this->newUuid());
            $flow->setApp('openregister');
            $flow->setCreated(new DateTime());
            $flow->setUpdated(new DateTime());

            $flow->setName((string) ($portable['name'] ?? 'Imported flow'));
            $flow->setDescription(($portable['description'] ?? null));
            $flow->setTrigger(($portable['trigger'] ?? null));
            $flow->setTriggerRegister(($portable['triggerRegister'] ?? null));
            $flow->setTriggerSchema(($portable['triggerSchema'] ?? null));
            $flow->setCron(($portable['cron'] ?? null));
            $flow->setNodes((array) ($portable['nodes'] ?? []));
            $flow->setEdges((array) ($portable['edges'] ?? []));
            $flow->setLimits((array) ($portable['limits'] ?? []));

            // An imported flow lands DISABLED and OWNERLESS, whatever the
            // bundle said. `owner` and `organisation` are not portable fields —
            // they name an identity on the SENDING instance that means nothing
            // here — and a flow with no owner cannot dispatch. So a bundle can
            // never arrive and start executing against the receiving tenant's
            // data before a local person has adopted it and switched it on.
            $flow->setEnabled(false);
            $flow->setOwner(null);
            $flow->setOrganisation(null);

            $stored      = $this->mapper->insert($flow);
            $installed[] = (string) $stored->getUuid();
        }//end foreach

        return ['installed' => $installed];

    }//end deserialise()

    /**
     * Mint a v4 uuid for an imported flow.
     *
     * An imported flow gets a FRESH id rather than the sender's: two instances
     * that both installed the same bundle would otherwise hold flows with the
     * same id, and a sub-flow reference or a run row would become ambiguous the
     * moment those instances ever exchanged anything again.
     *
     * @return string The uuid.
     *
     * @spec openspec/changes/federated-config-sharing/specs/federated-config-sharing/spec.md
     */
    private function newUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    }//end newUuid()

    /**
     * Keep only the portable fields of a flow.
     *
     * @param array $data The flow's stored data.
     *
     * @return array The portable subset.
     */
    private function portable(array $data): array
    {
        $out = [];
        foreach (self::PORTABLE_FIELDS as $field) {
            if (array_key_exists($field, $data) === true) {
                $out[$field] = $data[$field];
            }
        }

        return $out;

    }//end portable()
}//end class
