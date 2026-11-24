<?php

/**
 * ObjectDateEventsSourceInterface: the object date events the platform calendar shows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

use OCA\OpenRegister\Service\Calendar\ObjectDateEvents;
use OCP\IUser;

/**
 * Generates the dated events of a schema or saved view for one principal.
 *
 * The platform calendar provider (Integrations) asks; the object calendar
 * feed generator (Processes) answers and is bound to this contract, so the
 * calendar and the subscribable feed show the same events on the same dates.
 */
interface ObjectDateEventsSourceInterface {

	/**
	 * The scope type of a schema calendar.
	 *
	 * @var string
	 */
	public const SCOPE_SCHEMA = 'schema';

	/**
	 * The scope type of a saved-view calendar.
	 *
	 * @var string
	 */
	public const SCOPE_VIEW = 'view';

	/**
	 * Whether a schema's calendar configuration declares date kinds.
	 *
	 * @param array<string, mixed> $calendarConfig The schema's calendar provider configuration.
	 *
	 * @return boolean True when at least one date kind is declared.
	 *
	 * @throws \Throwable When the declaration cannot be read.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md#requirement-a-date-property-declares-what-kind-of-date-it-is-req-odf-002
	 */
	public function declaresDates(array $calendarConfig): bool;

	/**
	 * The events of one scope, generated as the given user, or null when the scope is gone or the read failed.
	 *
	 * @param IUser  $user      The principal the events are generated for.
	 * @param string $scopeType SCOPE_SCHEMA or SCOPE_VIEW.
	 * @param string $scopeId   The schema or view id.
	 *
	 * @return ObjectDateEvents|null The events, or null.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md#requirement-objects-with-declared-dates-publish-a-subscribable-feed-req-odf-001
	 */
	public function eventsFor(IUser $user, string $scopeType, string $scopeId): ?ObjectDateEvents;
}//end interface
