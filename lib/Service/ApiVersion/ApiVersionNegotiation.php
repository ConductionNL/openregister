<?php

/**
 * What one request asked for, and what it got.
 *
 * Separated from the negotiator because "which version did the caller name"
 * and "which version will answer" are two different facts and a deprecation
 * conversation needs both. A caller that named nothing and got version 1 is
 * not in the same position as one that asked for version 1 by name: the first
 * moves when the default moves, the second does not, and telling them apart
 * is what makes a sunset date actionable rather than alarming.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiVersion;

/**
 * The outcome of reading a request for the version it speaks.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 */
class ApiVersionNegotiation {

	/**
	 * The caller named no version and takes the current one.
	 *
	 * @var string
	 */
	public const SOURCE_DEFAULT = 'default';

	/**
	 * The caller named a version in the `API-Version` request header.
	 *
	 * @var string
	 */
	public const SOURCE_HEADER = 'header';

	/**
	 * The caller named a version as a media-type parameter on `Accept`.
	 *
	 * @var string
	 */
	public const SOURCE_ACCEPT = 'accept';

	/**
	 * The caller named a version in the path, as `/api/v2/...`.
	 *
	 * @var string
	 */
	public const SOURCE_PATH = 'path';

	/**
	 * The identifier the caller named, or null when it named none.
	 *
	 * @var string|null
	 */
	public readonly ?string $requestedId;

	/**
	 * The version that will answer, or null when nothing declares the request.
	 *
	 * @var ApiVersion|null
	 */
	public readonly ?ApiVersion $version;

	/**
	 * Where the identifier was read from; one of the SOURCE_ constants.
	 *
	 * @var string
	 */
	public readonly string $source;

	/**
	 * Constructor.
	 *
	 * @param string|null $requestedId The identifier the caller named.
	 * @param ApiVersion|null $version The version that will answer.
	 * @param string $source One of the SOURCE_ constants.
	 *
	 * @return void
	 */
	public function __construct(?string $requestedId, ?ApiVersion $version, string $source) {
		$this->requestedId = $requestedId;
		$this->version = $version;
		$this->source = $source;

	}//end __construct()

	/**
	 * Whether the caller named a version rather than taking the default.
	 *
	 * @return bool True when the request carried a version.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function isExplicit(): bool {
		return ($this->source !== self::SOURCE_DEFAULT);

	}//end isExplicit()

	/**
	 * Whether the caller named a version nothing on this instance declares.
	 *
	 * @return bool True when the named version is unknown.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function isUnknown(): bool {
		return ($this->requestedId !== null && $this->version === null);

	}//end isUnknown()
}//end class
