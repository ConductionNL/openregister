<?php

/**
 * Where a GraphQL subscriber resumes the event stream, and what it narrows the stream to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Notifications\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\GraphQL;

/**
 * A subscriber's event-stream filter.
 *
 * @psalm-immutable
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */
final class EventStreamFilter {
	/**
	 * Hold the filter.
	 *
	 * @param string|null $lastEventId The last event ID the client received, or null for the whole buffer.
	 * @param int|null    $schemaId    Optional schema ID filter.
	 * @param int|null    $registerId  Optional register ID filter.
	 */
	public function __construct(
		public readonly ?string $lastEventId = null,
		public readonly ?int $schemaId = null,
		public readonly ?int $registerId = null,
	) {
	}//end __construct()
}//end class
