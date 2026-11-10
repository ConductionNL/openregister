<?php

/**
 * ApiVersionNegotiationInterface: what one request asked for, as the API version middleware reads it.
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
 * ApiVersionNegotiation, typed for callers outside the Service component.
 *
 * The API version middleware reads which identifier the caller named, which
 * version answers, and whether the named one is unknown; that is the whole contract.
 */
interface ApiVersionNegotiationInterface {
	/**
	 * The identifier the caller named, or null when it named none.
	 *
	 * @return string|null The requested identifier.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function requestedIdentifier(): ?string;

	/**
	 * The version that will answer, or null when nothing declares the request.
	 *
	 * @return ApiVersionInterface|null The answering version.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function answeringVersion(): ?ApiVersionInterface;

	/**
	 * Whether the caller named a version nothing on this instance declares.
	 *
	 * @return bool True when the named version is unknown.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function isUnknown(): bool;
}//end interface
