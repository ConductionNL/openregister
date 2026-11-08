<?php

/**
 * The ETag the OAS endpoint answers with and compares against If-None-Match.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-validation/spec.md "Performance Impact of Validation / ETag caching"
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

/**
 * The ETag the OAS endpoint answers with and compares against If-None-Match.
 *
 * Bound to OasETagComputer in ContractBindings. Every method is described on OasETagComputer.
 */
interface OasETagComputerInterface {

	/**
	 * Compute the strong ETag for an OAS document.
	 *
	 * @param array $oas The generated OAS payload.
	 *
	 * @return string Strong ETag, quoted per RFC 7232 (e.g. `"abc123"`).
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-7
	 */
	public function computeETag(array $oas): string;

	/**
	 * Test whether an `If-None-Match` header value matches the current ETag.
	 *
	 * Implements the RFC 7232 weak-comparison rule for `If-None-Match`:
	 * a `*` matches any current ETag; a list of ETags matches when any
	 * one is equal to the current. Whitespace is stripped.
	 *
	 * @param string $ifNoneMatch The raw `If-None-Match` header value.
	 * @param string $currentETag The currently computed ETag (quoted).
	 *
	 * @return bool True when a 304 response can be returned.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid2/tasks.md#task-7
	 */
	public function matches(string $ifNoneMatch, string $currentETag): bool;
}//end interface
