<?php

/**
 * A draft was saved again, or just left draft (decision 227).
 *
 * Dispatched instead of ObjectUpdatedEvent, never beside it. Nextcloud delivers
 * an event to the listeners registered on its own class, so a listener on
 * ObjectUpdatedEvent never receives this one: a flow, a webhook or a
 * notification does not see a half-typed object. It extends
 * ObjectUpdatedEvent so the few listeners that must see a draft (cache
 * invalidation, the translation sidecar, cleanup) handle it with the code
 * they already have.
 *
 * Only ObjectEventGate creates it, and only the listeners named in
 * ListenerRegistrar::registerDraftListeners() listen to it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-draft-must-have-events-of-its-own-on-a-separate-path
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

/**
 * The draft counterpart of ObjectUpdatedEvent.
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-a-draft-must-have-events-of-its-own-on-a-separate-path
 */
class DraftUpdatedEvent extends ObjectUpdatedEvent {
}//end class
