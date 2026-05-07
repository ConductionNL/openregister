<?php

/**
 * OpenRegister CustomScopeEvaluatedEvent
 *
 * Telemetry event paired with `CustomScopeEvaluatingEvent`. Dispatched
 * AFTER a listener has voted on the evaluating event so observers can
 * record the listener-driven verdict for audit, dashboards, or
 * downstream analytics WITHOUT participating in the decision.
 *
 * NOT dispatched when no listener votes on the evaluating event — in
 * that case PermissionHandler falls through to the standard rule chain
 * and the resulting verdict is captured by the existing static-rule
 * audit/log paths, not this event.
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

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Telemetry event fired after a custom-scope evaluation completes.
 */
class CustomScopeEvaluatedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param Schema      $schema       Schema that was evaluated.
     * @param string      $action       Custom action verb that was evaluated.
     * @param string|null $userId       User ID under evaluation.
     * @param bool        $verdict      Final verdict returned to the caller.
     * @param bool        $fromListener True when the verdict came from a
     *                                  listener vote on the evaluating
     *                                  event; false when the standard
     *                                  rule chain produced the verdict.
     */
    public function __construct(
        private readonly Schema $schema,
        private readonly string $action,
        private readonly ?string $userId,
        private readonly bool $verdict,
        private readonly bool $fromListener
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Schema that was evaluated.
     *
     * @return Schema The schema involved in the evaluation.
     */
    public function getSchema(): Schema
    {
        return $this->schema;
    }//end getSchema()

    /**
     * Custom action verb that was evaluated.
     *
     * @return string The custom action verb.
     */
    public function getAction(): string
    {
        return $this->action;
    }//end getAction()

    /**
     * User ID under evaluation (null for anonymous requests).
     *
     * @return string|null The user ID, or null when anonymous.
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }//end getUserId()

    /**
     * Final verdict the caller received.
     *
     * @return bool The boolean verdict that the caller received.
     *
     * @SuppressWarnings(PHPMD.BooleanGetMethodName)
     */
    public function getVerdict(): bool
    {
        return $this->verdict;
    }//end getVerdict()

    /**
     * Whether the verdict was decided by a listener (true) or by the
     * standard rule chain after no listener voted (false).
     *
     * @return bool True when a listener decided the verdict.
     */
    public function isFromListener(): bool
    {
        return $this->fromListener;
    }//end isFromListener()
}//end class
