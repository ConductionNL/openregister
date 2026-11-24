<?php

/**
 * OpenRegister AppHost — Store Listing
 *
 * Immutable value object naming WHERE an app's store reads from and HOW a
 * remote object becomes a card: the app whose IAppConfig holds the registry
 * connection, the remote schema and register, and the card field map.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Service;

/**
 * The read side of a store descriptor (ADR-080 Decision 2, ADR-011 Rule 2).
 *
 * @spec openspec/specs/apphost-store-plane/spec.md
 */
final class StoreListing {
	/**
	 * The card field map used when an app names none.
	 */
	public const DEFAULT_CARD_FIELDS = [
		'slug' => 'slug',
		'title' => 'title',
		'description' => 'description',
		'category' => 'category',
		'version' => 'version',
	];

	/**
	 * Constructor.
	 *
	 * @param string                $appId           App whose IAppConfig holds the registry connection
	 *                                               (`registry_url`, `registry_token`, `registry_register`).
	 * @param string                $schema          Remote schema slug exposed by the registry
	 *                                               (e.g. "application-template", "catalog_item").
	 * @param string                $defaultRegister Remote register segment used when
	 *                                               `registry_register` is unset/empty.
	 * @param array<string, string> $cardFields      Card field name => remote object property. Drives
	 *                                               normalisation; a missing property yields an empty
	 *                                               string rather than a missing key, so the frontend
	 *                                               never has to null-check a card.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $appId,
		public readonly string $schema,
		public readonly string $defaultRegister,
		public readonly array $cardFields = self::DEFAULT_CARD_FIELDS,
	) {
	}//end __construct()
}//end class
