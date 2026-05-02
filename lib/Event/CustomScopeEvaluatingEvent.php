<?php

/**
 * OpenRegister CustomScopeEvaluatingEvent
 *
 * Dispatched by `PermissionHandler::evaluatePermission()` when the
 * action being checked is NOT one of the canonical five
 * (`read`, `create`, `update`, `delete`, `list`). Consuming apps that
 * declare custom action verbs on a register (per the rbac-scopes
 * change, decision 2026-05-02 option A) listen for this event and
 * contribute a verdict via `allow()` / `deny()`.
 *
 * Verdict semantics:
 *  - The first listener to call `allow()` wins; the verdict is final
 *    for the dispatch and PermissionHandler returns true.
 *  - The first listener to call `deny()` wins (same precedence as
 *    `allow()` — first non-null verdict short-circuits the chain).
 *  - When no listener votes, PermissionHandler falls through to the
 *    standard rule chain (which will likely deny because the static
 *    rules don't recognise the verb).
 *
 * Listeners MUST NOT swallow exceptions silently — uncaught
 * exceptions are propagated by NC's event dispatcher and surface
 * as a permission failure.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rbac-scopes/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a custom (non-canonical) action verb is being
 * evaluated against the RBAC rule chain. Listeners MAY contribute a
 * verdict via `allow()` / `deny()`.
 */
class CustomScopeEvaluatingEvent extends Event
{

    /**
     * Resolved verdict, or null when no listener has voted yet.
     *
     * @var boolean|null
     */
    private ?bool $verdict = null;

    /**
     * Constructor.
     *
     * @param Schema            $schema     Schema being checked.
     * @param string            $action     Custom (non-canonical) action verb.
     * @param string|null       $userId     User ID, or null for anonymous.
     * @param string[]          $userGroups User's group memberships at evaluation time.
     * @param ObjectEntity|null $object     Object the action is targeting (optional).
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly string $action,
        private readonly ?string $userId,
        private readonly array $userGroups,
        private readonly ?ObjectEntity $object=null
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Schema being checked.
     */
    public function getSchema(): Schema
    {
        return $this->schema;
    }//end getSchema()

    /**
     * The custom action verb being evaluated.
     */
    public function getAction(): string
    {
        return $this->action;
    }//end getAction()

    /**
     * User ID under evaluation (null for anonymous requests).
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }//end getUserId()

    /**
     * Group memberships of the user at evaluation time.
     *
     * @return string[]
     */
    public function getUserGroups(): array
    {
        return $this->userGroups;
    }//end getUserGroups()

    /**
     * Object the action is targeting, when one is supplied.
     */
    public function getObject(): ?ObjectEntity
    {
        return $this->object;
    }//end getObject()

    /**
     * Vote allow. The first listener to call this OR `deny()` wins;
     * subsequent calls are ignored so the verdict order remains
     * deterministic regardless of listener registration order.
     */
    public function allow(): void
    {
        if ($this->verdict === null) {
            $this->verdict = true;
        }
    }//end allow()

    /**
     * Vote deny. See `allow()` for verdict-order semantics.
     */
    public function deny(): void
    {
        if ($this->verdict === null) {
            $this->verdict = false;
        }
    }//end deny()

    /**
     * Resolved verdict, or null when no listener voted.
     */
    public function getVerdict(): ?bool
    {
        return $this->verdict;
    }//end getVerdict()

    /**
     * Whether any listener has cast a verdict.
     */
    public function hasVerdict(): bool
    {
        return $this->verdict !== null;
    }//end hasVerdict()
}//end class
