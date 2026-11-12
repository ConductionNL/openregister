<?php

/**
 * The notification a preference is resolved for, and the scopes that may pin it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The schema slug and notification key, with the candidate scopes narrowest first.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
 */
final class PreferenceTopic {

	/**
	 * Constructor.
	 *
	 * @param string             $schemaSlug      The owning schema's slug.
	 * @param string             $notificationKey The notification annotation key.
	 * @param array<int, string> $scopes          Candidate scopes, narrowest first (e.g. `['schema:zaak', 'register:7']`).
	 */
	public function __construct(
		public readonly string $schemaSlug,
		public readonly string $notificationKey,
		public readonly array $scopes = [],
	) {
	}//end __construct()

	/**
	 * The lookup for this topic, limited to the channels a layer may choose among.
	 *
	 * @param array<int, string>|null $allowedChannels The channels a layer may choose; null leaves them unrestricted.
	 *
	 * @return PreferenceLookup The lookup.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function lookup(?array $allowedChannels): PreferenceLookup {
		return new PreferenceLookup(
			schemaSlug: $this->schemaSlug,
			notificationKey: $this->notificationKey,
			scopes: $this->scopes,
			allowedChannels: $allowedChannels
		);
	}//end lookup()
}//end class
