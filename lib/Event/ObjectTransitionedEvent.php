<?php

/**
 * OpenRegister ObjectTransitionedEvent
 *
 * Dispatched after a successful state-machine transition on an object. Joins
 * the existing event-driven-architecture family — listeners subscribe via
 * `IEventDispatcher` like every other Object*Event in OpenRegister.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Fired after an object's lifecycle field has been updated through a
 * declared transition (per `x-openregister-lifecycle`).
 *
 * Carries the object plus the transition metadata so listeners (notifications,
 * cascades, calculation re-materialisation, audit enrichment) can react
 * without inferring the action from the generic ObjectUpdatedEvent.
 */
class ObjectTransitionedEvent extends Event
{

    /**
     * Object after the transition (lifecycle field is `to`).
     *
     * @var ObjectEntity
     */
    private ObjectEntity $object;

    /**
     * Action name from the transition table (e.g. "publish").
     *
     * @var string
     */
    private string $action;

    /**
     * Lifecycle state value before the transition.
     *
     * @var string
     */
    private string $from;

    /**
     * Lifecycle state value after the transition.
     *
     * @var string
     */
    private string $to;

    /**
     * Caller uid (null when the transition was applied by a system process).
     *
     * @var string|null
     */
    private ?string $userId;

    /**
     * Register slug.
     *
     * @var string
     */
    private string $register;

    /**
     * Schema slug.
     *
     * @var string
     */
    private string $schema;

    /**
     * Capture the post-transition state for downstream listeners.
     *
     * @param ObjectEntity $object   Object after the transition.
     * @param string       $action   Action name.
     * @param string       $from     State before.
     * @param string       $to       State after.
     * @param string|null  $userId   Caller uid (null for system-applied transitions).
     * @param string       $register Register slug.
     * @param string       $schema   Schema slug.
     */
    public function __construct(
        ObjectEntity $object,
        string $action,
        string $from,
        string $to,
        ?string $userId,
        string $register,
        string $schema
    ) {
        parent::__construct();
        $this->object   = $object;
        $this->action   = $action;
        $this->from     = $from;
        $this->to       = $to;
        $this->userId   = $userId;
        $this->register = $register;
        $this->schema   = $schema;
    }//end __construct()

    /**
     * Read the object after the transition.
     *
     * @return ObjectEntity Object whose lifecycle just changed.
     */
    public function getObject(): ObjectEntity
    {
        return $this->object;
    }//end getObject()

    /**
     * Read the action name from the transition table.
     *
     * @return string Action name (e.g. "publish").
     */
    public function getAction(): string
    {
        return $this->action;
    }//end getAction()

    /**
     * Read the lifecycle value before the transition.
     *
     * @return string Originating state value.
     */
    public function getFrom(): string
    {
        return $this->from;
    }//end getFrom()

    /**
     * Read the lifecycle value after the transition.
     *
     * @return string Destination state value.
     */
    public function getTo(): string
    {
        return $this->to;
    }//end getTo()

    /**
     * Read the caller uid that triggered the transition.
     *
     * @return string|null Caller uid, or null for system transitions.
     */
    public function getUserId(): ?string
    {
        return $this->userId;
    }//end getUserId()

    /**
     * Read the register slug the object belongs to.
     *
     * @return string Register slug.
     */
    public function getRegister(): string
    {
        return $this->register;
    }//end getRegister()

    /**
     * Read the schema slug the object belongs to.
     *
     * @return string Schema slug.
     */
    public function getSchema(): string
    {
        return $this->schema;
    }//end getSchema()
}//end class
