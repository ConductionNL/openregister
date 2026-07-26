<?php

/**
 * Flows as a shareable configuration type.
 *
 * This is the reframed #2065 "integration network": a flow is not a special kind
 * of shareable thing, it is one type among many that plug into the fleet's one
 * federated-config seam. A flow lives as an OpenRegister object in the flow store
 * ({@see OpenRegisterFlowResolver}), so serialising it is stripping the
 * instance-specific fields and keeping its portable shape (name, trigger, nodes,
 * edges, cron); installing it is writing that back into the store.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Config\IShareableConfigType;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Throwable;

/**
 * Serialises and installs flows through the federated-config engine.
 */
class FlowShareableConfigType implements IShareableConfigType
{

    /**
     * The app its config lives under, and the flow-store config keys/defaults.
     */
    private const APP_ID = 'openregister';

    private const REGISTER_KEY = 'flow_register';

    private const REGISTER_DEFAULT = 'flows';

    private const SCHEMA_KEY = 'flow_schema';

    private const SCHEMA_DEFAULT = 'flow';

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
     * @param ObjectService $objectService Reads and writes flow objects.
     * @param IAppConfig    $appConfig     Names the flow store.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppConfig $appConfig
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
                $object = $this->objectService->find(
                    id: (string) $flowId,
                    register: $this->flowRegister(),
                    schema: $this->flowSchema(),
                    _rbac: false,
                    _multitenancy: false
                );
            } catch (Throwable $e) {
                continue;
            }

            if (($object instanceof ObjectEntity) === true) {
                $flows[] = $this->portable(data: $object->getObject());
            }
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
        foreach ((array) ($bundle['flows'] ?? []) as $flow) {
            if (is_array($flow) === false) {
                continue;
            }

            $object = $this->objectService->saveObject(
                object: $this->portable(data: $flow),
                register: $this->flowRegister(),
                schema: $this->flowSchema()
            );

            $installed[] = (string) $object->getUuid();
        }

        return ['installed' => $installed];

    }//end deserialise()

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

    /**
     * The register flows are stored in.
     *
     * @return string The register slug.
     */
    private function flowRegister(): string
    {
        return $this->appConfig->getValueString(self::APP_ID, self::REGISTER_KEY, self::REGISTER_DEFAULT);

    }//end flowRegister()

    /**
     * The schema flows are stored under.
     *
     * @return string The schema slug.
     */
    private function flowSchema(): string
    {
        return $this->appConfig->getValueString(self::APP_ID, self::SCHEMA_KEY, self::SCHEMA_DEFAULT);

    }//end flowSchema()
}//end class
