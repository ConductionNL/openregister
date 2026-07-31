<?php

/**
 * Reads register objects into a flow.
 *
 * The counterpart to {@see ObjectWriteNode}, and until now the missing half: a
 * flow could create, patch and delete objects and had no way to ask a QUESTION
 * about them. The live catalogue carried fourteen node types and exactly one
 * object-shaped step, the write (ConductionNL/openregister#2235).
 *
 * The motivating case is a reaper. hydra's sequencer takes a per-issue lock as
 * an object write with `onConflict: fail`, which is a real mutual-exclusion
 * primitive since #2215 — but a crashed run leaves its lock behind, and the
 * scheduled flow that would clear stale ones has to begin by LISTING them.
 * Without a read step that flow cannot be written at all, and the workarounds
 * are worse than the gap: calling OpenRegister's own REST API back through
 * `openconnector.source-call` leaves the process, re-authenticates and crosses
 * an HTTP boundary to read a table it is already sitting on top of.
 *
 * SAME GUARDS AS THE WRITE, FOR THE SAME REASON
 * ---------------------------------------------
 * A flow is authored data. If a flow could READ past RBAC then authoring one
 * would be a disclosure escalation, exactly as writing past it would be a
 * privilege escalation. So the read runs as `context.triggeredBy` — no system
 * user, no owner named in the step's own configuration — and a run with no
 * resolvable owner reads nothing and says why.
 *
 * A failure throws rather than yielding an empty result. "No objects matched"
 * and "the read did not happen" are different answers, and a reaper that reads
 * the second as the first quietly stops reaping.
 *
 * TWO OUTPUT SHAPES, AND THE DEFAULT IS THE NARROWER ONE
 * ------------------------------------------------------
 * By default the matches land as a LIST under `output`, which is right for
 * "how many are there" and "is there any" — questions that are one decision.
 * With `fanOut: true` the step emits ONE ITEM PER OBJECT instead, which is what
 * lets the rest of the engine act on them: every other node works per item, and
 * nothing expands a list inside an item's json back into items
 * (`openregister.loop` batches the items on the walk, not the entries of a
 * field). A reaper needs the second shape, and with the first its delete step
 * fails with "the match value for uuid could not be resolved from the item".
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\WorkflowEngine\IManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Reads objects from a register into the run.
 */
class ObjectReadNode implements IFlowNode
{

    /**
     * The step type.
     *
     * @var string
     */
    public const NODE_ID = 'openregister.object-read';

    /**
     * Where the results land when the step names no `output`.
     *
     * @var string
     */
    private const DEFAULT_OUTPUT_KEY = 'objects';

    /**
     * How many objects one read returns when the step names no `limit`.
     *
     * A default rather than "everything": a reaper over a runaway lock table
     * should process a bounded batch and come back on the next tick, not build
     * a million-item walk in memory and time the run out.
     *
     * @var int
     */
    private const DEFAULT_LIMIT = 100;

    /**
     * The ceiling on `limit`, whatever the step asks for.
     *
     * @var int
     */
    private const MAX_LIMIT = 1000;

    /**
     * Constructor.
     *
     * @param ObjectService  $objects     Object reads, RBAC-enforcing.
     * @param RegisterMapper $registers   Resolves the configured register.
     * @param SchemaMapper   $schemas     Resolves the configured schema.
     * @param IUserManager   $userManager Resolves the run owner.
     * @param IL10N          $l10n        Translations.
     * @param IURLGenerator  $urls        For the palette icon.
     */
    public function __construct(
        private readonly ObjectService $objects,
        private readonly RegisterMapper $registers,
        private readonly SchemaMapper $schemas,
        private readonly IUserManager $userManager,
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
        return self::NODE_ID;

    }//end getId()

    /**
     * Palette name.
     *
     * @return string The display name.
     */
    public function getDisplayName(): string
    {
        return $this->l10n->t('Read objects');

    }//end getDisplayName()

    /**
     * Palette description.
     *
     * @return string The description.
     */
    public function getDescription(): string
    {
        return $this->l10n->t('Find objects in a register and put them on the item.');

    }//end getDescription()

    /**
     * Palette icon.
     *
     * @return string The icon URL.
     */
    public function getIcon(): string
    {
        return $this->urls->imagePath('core', 'actions/search.svg');

    }//end getIcon()

    /**
     * Available in both scopes — the owner's RBAC is what bounds the read.
     *
     * @param int $scope The scope constant.
     *
     * @return boolean Whether it is available.
     */
    public function isAvailableForScope(int $scope): bool
    {
        return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);

    }//end isAvailableForScope()

    /**
     * Refuse a step that cannot name what it reads.
     *
     * @param array $config The step configuration.
     *
     * @return void
     *
     * @throws UnexpectedValueException When register or schema is missing, or filters are malformed.
     */
    public function validateConfig(array $config): void
    {
        if (trim((string) ($config['register'] ?? '')) === '') {
            throw new UnexpectedValueException($this->l10n->t('An object-read step needs a register.'));
        }

        if (trim((string) ($config['schema'] ?? '')) === '') {
            throw new UnexpectedValueException($this->l10n->t('An object-read step needs a schema.'));
        }

        if (array_key_exists('filters', $config) === true && is_array($config['filters']) === false) {
            throw new UnexpectedValueException($this->l10n->t('An object-read step\'s filters must be an object.'));
        }

    }//end validateConfig()

    /**
     * Read once per item, putting the results on each.
     *
     * One read per item, not one for the whole batch, because the filters are
     * templated from the item — two items asking different questions must get
     * their own answers. It is the same contract every other item-driven node
     * follows.
     *
     * @param array $items   The input items.
     * @param array $config  The step configuration.
     * @param array $context Run-level metadata (carries the triggering user).
     *
     * @return array The items, each with the read's results added.
     *
     * @throws RuntimeException        When the run has no resolvable owner.
     * @throws UnexpectedValueException When the register or schema does not resolve.
     */
    public function execute(array $items, array $config, array $context): array
    {
        // An empty branch asks nothing. It short-circuits before the owner is
        // resolved, because there is nothing to attribute — the same contract
        // `openconnector.source-call` follows.
        if ($items === []) {
            return [];
        }

        $this->validateConfig(config: $config);

        $owner    = $this->resolveOwner(context: $context);
        $register = $this->resolveRegister(config: $config);
        $schema   = $this->resolveSchema(config: $config, register: $register);
        $outKey   = trim((string) ($config['output'] ?? ''));
        if ($outKey === '') {
            $outKey = self::DEFAULT_OUTPUT_KEY;
        }

        $limit = $this->limitFrom(config: $config);

        $out = [];
        foreach ($items as $index => $item) {
            $json    = (array) ($item[FlowItems::JSON] ?? []);
            $filters = $this->renderFilters(filters: (array) ($config['filters'] ?? []), json: $json);

            $records = $this->read(
                register: $register,
                schema: $schema,
                filters: $filters,
                limit: $limit,
                owner: $owner
            );

            $binary = (array) ($item[FlowItems::BINARY] ?? []);

            // FAN-OUT: one item per object, rather than one item holding a list.
            //
            // This exists because the list form does not COMPOSE. Every other
            // node in the engine acts per item, and nothing expands a list
            // inside an item's json back into items — `openregister.loop`
            // batches the items on the walk, not the entries of a field. So a
            // read that returns `{objects: [...]}` can be counted and branched
            // on, and cannot be acted on one row at a time.
            //
            // Which is the motivating case. A reaper reads stale locks and then
            // DELETES each one; with the list form its delete step sees one item
            // whose `uuid` is not a field, and fails with "the match value for
            // uuid could not be resolved from the item". Measured while building
            // it.
            //
            // The list stays the DEFAULT because it is the right shape for the
            // other half of the uses — "how many are there", "is there any" —
            // where fanning out would turn one decision into N.
            if (($config['fanOut'] ?? false) === true) {
                foreach ($records as $record) {
                    $out[] = FlowItems::item(
                        json: array_merge($json, $record),
                        binary: $binary,
                        fromItemIndex: (int) $index
                    );
                }

                // No matches means no items, which ends the branch — the same
                // contract `openregister.loop` has for an empty input. It is
                // not an error: "nothing to reap" is the ordinary case.
                continue;
            }

            $json[$outKey] = $records;

            $out[] = FlowItems::item(
                json: $json,
                binary: $binary,
                fromItemIndex: (int) $index
            );
        }//end foreach

        return $out;

    }//end execute()

    /**
     * Substitute `{{dotted.path}}` placeholders in the filter values.
     *
     * Deliberately its own small renderer rather than a shared one: OpenConnector
     * owns `FlowTemplate`, and reaching across an app boundary for it would make
     * OpenRegister's own node depend on an app that may not be installed.
     * `ObjectWriteNode` resolves its `fields` with a private renderer for the
     * same reason.
     *
     * A value that is EXACTLY one placeholder keeps the resolved value's type,
     * so a numeric id stays a number and a list stays a list — a filter is
     * compared, and `"7"` and `7` are not the same comparison. Anything else is
     * substituted inline and stringified. A non-string passes through untouched,
     * so a literal filter can be authored directly.
     *
     * @param array $filters The configured filters.
     * @param array $json    The item's record.
     *
     * @return array The rendered filters.
     */
    private function renderFilters(array $filters, array $json): array
    {
        $rendered = [];
        foreach ($filters as $key => $value) {
            if (is_array($value) === true) {
                $rendered[(string) $key] = $this->renderFilters(filters: $value, json: $json);
                continue;
            }

            if (is_string($value) === false) {
                $rendered[(string) $key] = $value;
                continue;
            }

            $whole = [];
            if (preg_match('/^\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}$/', $value, $whole) === 1) {
                $rendered[(string) $key] = $this->valueAt(path: $whole[1], json: $json);
                continue;
            }

            $rendered[(string) $key] = (string) preg_replace_callback(
                '/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/',
                function (array $matches) use ($json): string {
                    $resolved = $this->valueAt(path: $matches[1], json: $json);
                    if (is_array($resolved) === true) {
                        return (string) json_encode($resolved);
                    }

                    return (string) $resolved;
                },
                $value
            );
        }//end foreach

        return $rendered;

    }//end renderFilters()

    /**
     * The value at a dotted path in the item's record, or null.
     *
     * @param string $path The dotted path.
     * @param array  $json The item's record.
     *
     * @return mixed The value, or null when the path is absent.
     */
    private function valueAt(string $path, array $json): mixed
    {
        $cursor = $json;
        foreach (explode('.', $path) as $segment) {
            if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;

    }//end valueAt()

    /**
     * Perform one read as the run owner.
     *
     * A failure THROWS rather than returning an empty list. "No objects
     * matched" and "the read did not happen" are different answers, and a
     * caller that cannot tell them apart — a reaper, a guard, a count — will
     * read the second as the first and quietly do nothing.
     *
     * @param Register $register The resolved register.
     * @param Schema   $schema   The resolved schema.
     * @param array    $filters  The rendered filters.
     * @param int      $limit    The bounded result cap.
     * @param IUser    $owner    The run owner, whose RBAC applies.
     *
     * @return array<int, array> The matched objects, as plain records.
     *
     * @throws RuntimeException When the read itself fails.
     */
    private function read(Register $register, Schema $schema, array $filters, int $limit, IUser $owner): array
    {
        try {
            $found = $this->objects
                ->setRegister($register)
                ->setSchema($schema)
                ->findAll(config: ['filters' => $filters, 'limit' => $limit]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                $this->l10n->t('The object-read step could not read from the register: %s', [$e->getMessage()]),
                0,
                $e
            );
        }

        $records = [];
        foreach ($found as $object) {
            if (($object instanceof ObjectEntity) === false) {
                continue;
            }

            $record = (array) $object->getObject();
            // The uuid is what a follow-up write or delete needs to name this
            // row, and it does not live in the record's own fields.
            $record['uuid'] = $object->getUuid();

            $records[] = $record;
        }

        return $records;

    }//end read()

    /**
     * The bounded result cap for one read.
     *
     * @param array $config The step configuration.
     *
     * @return int The limit.
     */
    private function limitFrom(array $config): int
    {
        $limit = (int) ($config['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);

    }//end limitFrom()

    /**
     * The run's owner, whose RBAC bounds the read.
     *
     * @param array $context Run-level metadata.
     *
     * @return IUser The run owner.
     *
     * @throws RuntimeException When the run carries no resolvable owner.
     */
    private function resolveOwner(array $context): IUser
    {
        $uid = ($context['triggeredBy'] ?? null);
        if (is_string($uid) === false || trim($uid) === '') {
            throw new RuntimeException(
                $this->l10n->t('This flow run has no owner (triggeredBy); an object read must be attributable.')
            );
        }

        $user = $this->userManager->get(trim($uid));
        if ($user === null) {
            throw new RuntimeException(
                $this->l10n->t(
                    'This flow run\'s owner "%s" (triggeredBy) is not a user account; an object read must be attributable.',
                    [trim($uid)]
                )
            );
        }

        return $user;

    }//end resolveOwner()

    /**
     * Resolve the configured register from a slug, uuid or id.
     *
     * @param array $config The step configuration.
     *
     * @return Register The resolved register.
     *
     * @throws UnexpectedValueException When it does not resolve.
     */
    private function resolveRegister(array $config): Register
    {
        $identifier = trim((string) ($config['register'] ?? ''));

        try {
            return $this->registers->find(id: $identifier, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            throw new UnexpectedValueException(
                $this->l10n->t('The register "%s" could not be resolved.', [$identifier]),
                0,
                $e
            );
        }

    }//end resolveRegister()

    /**
     * Resolve the configured schema, preferring the register's own schemas.
     *
     * A bare slug is only unique WITHIN a register. Resolving it globally lets
     * a generic slug (`order`, `task`, `lock`) land on another app's schema
     * that happens to share it — the same reasoning as `ObjectWriteNode`.
     *
     * @param array    $config   The step configuration.
     * @param Register $register The already-resolved register.
     *
     * @return Schema The resolved schema.
     *
     * @throws UnexpectedValueException When it does not resolve.
     */
    private function resolveSchema(array $config, Register $register): Schema
    {
        $identifier = trim((string) ($config['schema'] ?? ''));

        if (is_numeric($identifier) === false) {
            $scoped = $this->schemas->findBySlugInIds(
                slug: $identifier,
                schemaIds: (array) ($register->getSchemas() ?? [])
            );
            if ($scoped !== null) {
                return $scoped;
            }
        }

        try {
            return $this->schemas->find(id: $identifier, _rbac: false, _multitenancy: false);
        } catch (Throwable $e) {
            throw new UnexpectedValueException(
                $this->l10n->t('The schema "%s" could not be resolved.', [$identifier]),
                0,
                $e
            );
        }

    }//end resolveSchema()
}//end class
