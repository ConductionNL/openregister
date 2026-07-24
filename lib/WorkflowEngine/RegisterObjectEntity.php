<?php

/**
 * OpenRegister object as a Nextcloud Flow (workflowengine) entity.
 *
 * Registers OpenRegister objects as a first-class entity in the native
 * Nextcloud Flow rule builder, so an administrator can author Flow rules that
 * trigger on OpenRegister object create / update / delete events and attach any
 * Nextcloud Flow operation (email, tagging, scripts, or the OpenRegister
 * "Run flow" operation) to them. This is one half of the two-way composition
 * between the visual flow builder and native Nextcloud Flow: OpenRegister
 * object events become available *in* Nextcloud Flow.
 *
 * The event names are OpenRegister's own lifecycle event classes, which the
 * workflowengine subscribes to; when OpenRegister dispatches one, the engine
 * matches configured rules and runs their operations.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category WorkflowEngine
 * @package  OCA\OpenRegister\WorkflowEngine
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/visual-flow-builder/specs/integration-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\WorkflowEngine;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\GenericEntityEvent;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IRuleMatcher;

/**
 * Exposes OpenRegister objects to the native Nextcloud Flow rule engine.
 */
class RegisterObjectEntity implements IEntity
{
    /**
     * The object carried by the event currently being matched.
     *
     * @var ObjectEntity|null
     */
    private ?ObjectEntity $object = null;

    /**
     * Constructor.
     *
     * @param IL10N         $l10n         Translations for display strings.
     * @param IURLGenerator $urlGenerator Resolves the entity icon path.
     */
    public function __construct(
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
    ) {
    }//end __construct()

    /**
     * Display name in the Flow rule builder.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->l10n->t('OpenRegister object');
    }//end getName()

    /**
     * Entity icon.
     *
     * @return string
     */
    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('core', 'categories/files.svg');
    }//end getIcon()

    /**
     * The events an admin may build a rule on. Names are OpenRegister's own
     * dispatched lifecycle event classes, which the engine subscribes to.
     *
     * @return array<int, GenericEntityEvent>
     */
    public function getEvents(): array
    {
        return [
            new GenericEntityEvent($this->l10n->t('OpenRegister object created'), ObjectCreatedEvent::class),
            new GenericEntityEvent($this->l10n->t('OpenRegister object updated'), ObjectUpdatedEvent::class),
            new GenericEntityEvent($this->l10n->t('OpenRegister object deleted'), ObjectDeletedEvent::class),
        ];
    }//end getEvents()

    /**
     * Stash the object subject so operations and checks can resolve it.
     *
     * @param IRuleMatcher $ruleMatcher The matcher for the current event.
     * @param string       $eventName   The dispatched event class name.
     * @param Event        $event       The dispatched event.
     *
     * @return void
     */
    public function prepareRuleMatcher(IRuleMatcher $ruleMatcher, string $eventName, Event $event): void
    {
        $object = $this->objectFromEvent($event);
        if ($object === null) {
            return;
        }

        $this->object = $object;
        $ruleMatcher->setEntitySubject($this, $object);
    }//end prepareRuleMatcher()

    /**
     * Whether the given user may see/run rules for this entity instance.
     *
     * OpenRegister objects are governed by register/organisation RBAC rather
     * than per-user file ownership; rule scoping is handled at the Flow layer,
     * so this entity is legitimate for any authenticated rule owner.
     *
     * @param string $userId The rule owner's user id.
     *
     * @return bool
     */
    public function isLegitimatedForUserId(string $userId): bool
    {
        return true;
    }//end isLegitimatedForUserId()

    /**
     * The object carried by the current event, if any (used by the operation).
     *
     * @return ObjectEntity|null
     */
    public function getObject(): ?ObjectEntity
    {
        return $this->object;
    }//end getObject()

    /**
     * Resolve the OpenRegister object from a lifecycle event.
     *
     * @param Event $event The dispatched event.
     *
     * @return ObjectEntity|null
     */
    private function objectFromEvent(Event $event): ?ObjectEntity
    {
        if ($event instanceof ObjectCreatedEvent
            || $event instanceof ObjectUpdatedEvent
            || $event instanceof ObjectDeletedEvent
        ) {
            return $event->getObject();
        }

        return null;
    }//end objectFromEvent()
}//end class
