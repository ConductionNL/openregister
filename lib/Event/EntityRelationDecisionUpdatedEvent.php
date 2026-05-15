<?php

/**
 * OpenRegister EntityRelationDecisionUpdatedEvent
 *
 * Dispatched after `EntityRelationMapper::updateDecisionMetadata` persists a
 * change to an EntityRelation row's decision-metadata fields (`bases` and/or
 * `skipAnonymization`). The event fires AFTER the row + audit-trail entry
 * are committed, so listeners see the persisted state.
 *
 * Designed for downstream apps (DocuDesk specifically — `publication-clearance-via-anonymise`)
 * that need to react to operator decisions on individual entity relations.
 * For example: when `skipAnonymization` flips from `false` to `true`,
 * DocuDesk creates a publication-consent record so the Woo 28-day clock
 * starts ticking at decision time rather than at anonymise time.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\EntityRelation;
use OCP\EventDispatcher\Event;
use OCP\IUser;

/**
 * Event dispatched after an EntityRelation's decision metadata is updated.
 *
 * Post-commit, informational. Not vetoable. Listeners that need to
 * mutate state in response (e.g. reverse the PATCH on prohibition match)
 * MUST do so via a separate write — the original write has already landed.
 */
class EntityRelationDecisionUpdatedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param EntityRelation                                    $relation      The relation row in its post-update state.
     * @param array<string, array{previous: mixed, new: mixed}> $changedFields Per-field diff
     *                                                                         (only keys that actually changed).
     * @param IUser|null                                        $actingUser    The user who performed the change, or null
     *                                                                         when the change was driven by a
     *                                                                         non-session actor (background job,
     *                                                                         system).
     */
    public function __construct(
        private readonly EntityRelation $relation,
        private readonly array $changedFields,
        private readonly ?IUser $actingUser
    ) {
        parent::__construct();

    }//end __construct()

    /**
     * Get the post-update relation row.
     *
     * @return EntityRelation
     */
    public function getRelation(): EntityRelation
    {
        return $this->relation;

    }//end getRelation()

    /**
     * Get the per-field diff for the change.
     *
     * Shape: `{ fieldName: { previous: <oldValue>, new: <newValue> } }`. Keys
     * are limited to `bases` and `skipAnonymization` per the
     * `updateDecisionMetadata` whitelist. Only fields whose value actually
     * changed appear — listeners checking for a specific transition
     * (e.g. `skipAnonymization: false → true`) can rely on the key's
     * presence + `previous` / `new` values without re-deriving.
     *
     * @return array<string, array{previous: mixed, new: mixed}>
     */
    public function getChangedFields(): array
    {
        return $this->changedFields;

    }//end getChangedFields()

    /**
     * Get the acting user, or null when the change had no session user.
     *
     * Listeners that need to attribute downstream effects (audit, consent
     * record acting-user) should call this. When null, downstream callers
     * record the actor as 'system'.
     *
     * @return IUser|null
     */
    public function getActingUser(): ?IUser
    {
        return $this->actingUser;

    }//end getActingUser()

    /**
     * Convenience: true when `skipAnonymization` was flipped from false to true
     * by this change. Encapsulates the most common listener trigger so each
     * consumer doesn't reimplement the same check.
     *
     * @return bool
     */
    public function isSkipAnonymizationActivated(): bool
    {
        if (array_key_exists('skipAnonymization', $this->changedFields) === false) {
            return false;
        }

        $diff = $this->changedFields['skipAnonymization'];
        return $diff['previous'] === false && $diff['new'] === true;

    }//end isSkipAnonymizationActivated()
}//end class
