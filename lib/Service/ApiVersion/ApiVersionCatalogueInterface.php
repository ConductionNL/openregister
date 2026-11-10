<?php

/**
 * ApiVersionCatalogueInterface: what callers outside the component use of ApiVersionCatalogue.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * The API versions the API surface and caller endpoints list and inspect.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ApiVersionCatalogueInterface {

	/**
	 * Configuration key holding the administered declaration, a JSON list.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'api_versions';

	/**
	 * Every declared version, in identifier order.
	 *
	 * @return array<string, ApiVersion> Versions keyed by identifier.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function all(): array;

	/**
	 * The version a caller gets when it names none.
	 *
	 * The highest-numbered supported version, so promoting a new contract is
	 * one declaration rather than a declaration and a separate default.
	 *
	 * @return ApiVersion The current version.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function current(): ApiVersion;

	/**
	 * One version by identifier, or null when nothing declares it.
	 *
	 * A withdrawn version is returned here rather than hidden: the caller
	 * needs it in order to answer 410 and name the successor, and hiding it
	 * would turn a deliberate withdrawal back into the 404 that design D-3
	 * exists to avoid.
	 *
	 * @param string $identifier The version identifier a consumer named.
	 *
	 * @return ApiVersion|null The declared version, or null when unknown.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function get(string $identifier): ?ApiVersion;

	/**
	 * Why a candidate declaration could not be served, without storing it.
	 *
	 * The administration surface needs this before it writes: the read path
	 * deliberately falls back to the built-in contract when a stored
	 * declaration is unusable, which is right at read time and wrong at write
	 * time. An administrator who saved a broken declaration would get a 200,
	 * see the built-in contract come back, and have no idea their edit did
	 * nothing.
	 *
	 * Touches no memo and no field, so asking does not change what this
	 * instance serves.
	 *
	 * @param array<int, mixed> $declarations The candidate declarations.
	 *
	 * @return array<string, string> The refusals, keyed by the version they concern.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function inspect(array $declarations): array;

	/**
	 * Declarations that were refused, keyed by the identifier they claimed.
	 *
	 * @return array<string, string> Identifier to reason.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function rejections(): array;

	/**
	 * The identifiers that still answer, as strings, lowest first.
	 *
	 * @return array<int, string> The served identifiers.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function servedIdentifiers(): array;
}//end interface
