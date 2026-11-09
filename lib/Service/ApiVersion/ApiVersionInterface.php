<?php

/**
 * ApiVersionInterface: one declared API version as the API version middleware reads it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
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

namespace OCA\OpenRegister\Service\ApiVersion;

/**
 * ApiVersion, typed for callers outside the Service component.
 *
 * The API version middleware and its refusal stamp and refuse on a version's
 * identifier, whether it answers, and the deprecation headers it carries; that
 * is the whole contract.
 */
interface ApiVersionInterface {
	/**
	 * The version identifier a consumer names.
	 *
	 * @return string The identifier, digits only.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function identifier(): string;

	/**
	 * The identifier of the version a caller should move to, or null.
	 *
	 * @return string|null The successor's identifier.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function successorIdentifier(): ?string;

	/**
	 * Whether this version answers at all.
	 *
	 * @return bool True for supported and deprecated, false for withdrawn.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function isServed(): bool;

	/**
	 * Whether this version answers and carries an end date while doing so.
	 *
	 * @return bool True when the status is deprecated.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function isDeprecated(): bool;

	/**
	 * The deprecation date as an HTTP-date, for the RFC 8594 Deprecation header.
	 *
	 * @return string|null The HTTP-date, or null when no date is recorded.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function deprecationHttpDate(): ?string;

	/**
	 * The sunset date as an HTTP-date, for the RFC 8594 Sunset header.
	 *
	 * @return string|null The HTTP-date, or null when no date is recorded.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function sunsetHttpDate(): ?string;
}//end interface
