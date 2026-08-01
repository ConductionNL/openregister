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
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The resolver for OpenRegister's own flow objects.
 *
 * @spec openspec/changes/or-flow-native-store/specs/flow-native-store/spec.md
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
     * Cache key the compact trigger index is stored under.
     */
    private const INDEX_CACHE_KEY = 'flow-trigger-index';

    /**
     * Config key overriding how long the trigger index stays cached.
     */
    private const INDEX_TTL_KEY = 'flow_trigger_index_ttl';

    /**
     * Seconds the trigger index stays cached (matches AggregationCache).
     */
    private const INDEX_TTL_DEFAULT = 60;

    /**
     * Request-level memo of the trigger index, avoiding a cache round trip
     * per object in a bulk save.
     *
     * @var array<int, array<string, string>>|null
     */
    private ?array $indexMemo = null;

    /**
     * Constructor.
     *
     * @param ObjectService   $objectService Loads and lists flow objects.
     * @param IAppConfig      $appConfig     Names the register and schema.
     * @param ICacheFactory   $cacheFactory  Builds the distributed index cache.
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly IAppConfig $appConfig,
        private readonly ICacheFactory $cacheFactory,
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
            'id'            => $flowId,
            'nodes'         => (array) ($data['nodes'] ?? []),
            'edges'         => (array) ($data['edges'] ?? []),
            'limits'        => (array) ($data['limits'] ?? []),
            'executionMode' => (string) ($data['executionMode'] ?? ''),
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
        $ids = [];
        foreach ($this->triggerIndex() as $entry) {
            if ($entry['trigger'] !== $event) {
                continue;
            }

            $flowRegister = $entry['triggerRegister'];
            $flowSchema   = $entry['triggerSchema'];
            if ($flowRegister !== '' && $flowRegister !== $register) {
                continue;
            }

            if ($flowSchema !== '' && $flowSchema !== $schema) {
                continue;
            }

            $ids[] = $entry['id'];
        }//end foreach

        return $ids;

    }//end flowsForTrigger()

    /**
     * The compact (id, trigger, register, schema) index of ENABLED flows.
     *
     * Every object write asks "is any flow wired to this event?". Answering it
     * by listing and rendering every flow object cost ~144 ms cold / ~9 ms warm
     * per write, because the first touch of the flow register in a request pays
     * register/schema resolution and magic-table metadata regardless of how few
     * flows exist. The answer only changes when an admin edits a flow, so it is
     * cached: the hot path reads a small array instead of the object store.
     *
     * Staleness is bounded by the TTL (default 60 s, the same bound
     * AggregationCache uses); a newly enabled flow starts firing within that
     * window. Set `openregister/flow_trigger_index_ttl` to `0` to disable
     * caching and restore the previous read-through behaviour.
     *
     * @return array<int, array<string, string>> Index entries.
     *
     * @spec openspec/changes/object-event-sync-async-split/specs/event-driven-architecture/spec.md
     */
    private function triggerIndex(): array
    {
        if ($this->indexMemo !== null) {
            return $this->indexMemo;
        }

        $ttl   = $this->appConfig->getValueInt(self::APP_ID, self::INDEX_TTL_KEY, self::INDEX_TTL_DEFAULT);
        $cache = null;
        if ($ttl > 0) {
            $cache = $this->cacheFactory->createDistributed('openregister_flow_triggers');

            $cached = $cache->get(self::INDEX_CACHE_KEY);
            if (is_array($cached) === true) {
                $this->indexMemo = $cached;
                return $cached;
            }
        }

        $index = $this->buildTriggerIndex();

        if ($cache !== null) {
            $cache->set(self::INDEX_CACHE_KEY, $index, $ttl);
        }

        $this->indexMemo = $index;

        return $index;

    }//end triggerIndex()

    /**
     * Read the flow store and reduce it to the trigger index.
     *
     * Only enabled flows are indexed, so the hot path never re-checks the flag.
     * The query filters on `enabled` server-side; the trigger itself stays a
     * PHP-side match because one index serves every event id.
     *
     * @return array<int, array<string, string>> Index entries.
     */
    private function buildTriggerIndex(): array
    {
        try {
            $flows = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => $this->flowRegister(),
                        'schema'   => $this->flowSchema(),
                        'enabled'  => true,
                    ],
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            // No flow store yet — nothing to trigger. Logged because an
            // unreadable flow store and an empty one are indistinguishable
            // from the caller, and the difference is "no flows configured"
            // versus "every flow silently stopped firing".
            $this->logger->info(
                message: '[OpenRegisterFlowResolver] Flow store unreadable — no flows will be triggered: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            return [];
        }//end try

        $index = [];
        foreach ($flows as $flow) {
            if (($flow instanceof ObjectEntity) === false) {
                continue;
            }

            $data = $flow->getObject();
            if (($data['enabled'] ?? false) !== true) {
                continue;
            }

            $index[] = [
                'id'              => (string) $flow->getUuid(),
                'trigger'         => (string) ($data['trigger'] ?? ''),
                'triggerRegister' => (string) ($data['triggerRegister'] ?? ''),
                'triggerSchema'   => (string) ($data['triggerSchema'] ?? ''),
            ];
        }//end foreach

        return $index;

    }//end buildTriggerIndex()

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
