<?php

/**
 * Resolves flows stored in OpenRegister itself.
 *
 * The flow engine is OpenRegister's, but until now OpenRegister could not own a
 * flow: it had no resolver of its own, so only a consuming app (hermiq's
 * agentflows) could store and run one. This is the resolver that closes that —
 * it lets a flow live as an ordinary OpenRegister object, in a register and
 * schema an admin nominates, and the engine, triggers, sub-flows and the test
 * run all work with it exactly as they do with a leaf app's flows.
 *
 * A flow is just an object with `nodes` and `edges`. Which register and schema
 * hold them is configuration (`flow_register` / `flow_schema`, defaulting to the
 * `flows` register and `flow` schema), so an instance points this at whatever
 * store it authored its flows in. Absent that store, every method resolves to
 * nothing and another app's resolver is left to answer — this never claims a
 * flow it does not own.
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
 * @spec openspec/changes/or-flow-native-store/specs/flow-native-store/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The resolver for OpenRegister's own flow objects.
 */
class OpenRegisterFlowResolver implements IFlowResolver
{

    /**
     * The app id its config lives under.
     */
    private const APP_ID = 'openregister';

    /**
     * Config keys (and defaults) naming the register and schema flows live in.
     */
    private const REGISTER_KEY = 'flow_register';

    private const REGISTER_DEFAULT = 'flows';

    private const SCHEMA_KEY = 'flow_schema';

    private const SCHEMA_DEFAULT = 'flow';

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService Loads and lists flow objects.
     * @param IAppConfig      $appConfig     Names the register and schema.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Load a flow as a flow document.
     *
     * @param string $flowId The flow uuid.
     *
     * @return array|null The flow document, or null when it is not an OR flow.
     *
     * @spec openspec/changes/or-flow-native-store/specs/flow-native-store/spec.md
     */
    public function resolveFlow(string $flowId): ?array
    {
        try {
            $object = $this->objectService->find(
                id: $flowId,
                register: $this->flowRegister(),
                schema: $this->flowSchema(),
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            // Not an OR flow (or the store does not exist) — leave it to another
            // resolver.
            return null;
        }

        if (($object instanceof ObjectEntity) === false) {
            return null;
        }

        $data = $object->getObject();

        // An object in the flow store that is not shaped like a flow is not one.
        if (isset($data['nodes']) === false && isset($data['edges']) === false) {
            return null;
        }

        return [
            'id'     => $flowId,
            'nodes'  => (array) ($data['nodes'] ?? []),
            'edges'  => (array) ($data['edges'] ?? []),
            'limits' => (array) ($data['limits'] ?? []),
        ];

    }//end resolveFlow()

    /**
     * Load the object a run is about.
     *
     * @param string $uuid     The subject uuid.
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     *
     * @return object|null The subject object, or null when it cannot be found.
     *
     * @spec openspec/changes/or-flow-native-store/specs/flow-native-store/spec.md
     */
    public function resolveSubject(string $uuid, string $register, string $schema): ?object
    {
        if ($uuid === '' || $register === '' || $schema === '') {
            return null;
        }

        try {
            $object = $this->objectService->find(
                id: $uuid,
                register: $register,
                schema: $schema,
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            return null;
        }

        if (($object instanceof ObjectEntity) === true) {
            return $object;
        }

        return null;

    }//end resolveSubject()

    /**
     * Which flows are wired to a fired event.
     *
     * A flow declares its trigger with `trigger`, `triggerRegister` and
     * `triggerSchema` fields. A flow matches when it is enabled, its trigger
     * equals the event and its register/schema match (an empty flow
     * register/schema is a wildcard — "any").
     *
     * @param string $event    The event id.
     * @param string $register The register the event fired on.
     * @param string $schema   The schema the event fired on.
     *
     * @return array<int, string> The ids of the matching flows.
     *
     * @spec openspec/changes/or-flow-native-store/specs/flow-native-store/spec.md
     */
    public function flowsForTrigger(string $event, string $register, string $schema): array
    {
        try {
            $flows = $this->objectService->findAll(
                config: ['filters' => ['register' => $this->flowRegister(), 'schema' => $this->flowSchema()]],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            // No flow store yet — nothing to trigger.
            return [];
        }

        $ids = [];
        foreach ($flows as $flow) {
            if (($flow instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $flow->getObject();
            if (($data['enabled'] ?? false) !== true) {
                continue;
            }

            if ((string) ($data['trigger'] ?? '') !== $event) {
                continue;
            }

            $flowRegister = (string) ($data['triggerRegister'] ?? '');
            $flowSchema   = (string) ($data['triggerSchema'] ?? '');
            if ($flowRegister !== '' && $flowRegister !== $register) {
                continue;
            }

            if ($flowSchema !== '' && $flowSchema !== $schema) {
                continue;
            }

            $ids[] = (string) $flow->getUuid();
        }//end foreach

        return $ids;

    }//end flowsForTrigger()

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
