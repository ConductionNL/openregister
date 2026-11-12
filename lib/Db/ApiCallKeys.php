<?php

/**
 * OpenRegister ApiCallKeys
 *
 * Builds the key of one API call record, so callers outside lib/Db do not name the key class.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The factory of ApiCallKey, the key ApiCallRecordMapperInterface::count() takes.
 *
 * @spec openspec/specs/api-surface-governance/spec.md
 */
final class ApiCallKeys {
	/**
	 * The key of one call.
	 *
	 * @param string $principal  The caller, or the empty string for anonymous.
	 * @param string $route      The route pattern.
	 * @param string $method     The HTTP method.
	 * @param string $apiVersion The contract version that served the call.
	 *
	 * @return ApiCallKey The key.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public static function of(string $principal, string $route, string $method, string $apiVersion): ApiCallKey {
		return new ApiCallKey(principal: $principal, route: $route, method: $method, apiVersion: $apiVersion);
	}//end of()
}//end class
