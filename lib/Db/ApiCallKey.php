<?php

/**
 * ApiCallKey: the combination one API-call record is counted under.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * The caller, route, method and contract version of one API call.
 *
 * These four columns carry the unique index of the record, so one key is one row.
 *
 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
 */
final class ApiCallKey {

	/**
	 * The caller, or the empty string for anonymous.
	 *
	 * @var string
	 */
	public readonly string $principal;

	/**
	 * The route pattern.
	 *
	 * @var string
	 */
	public readonly string $route;

	/**
	 * The HTTP method.
	 *
	 * @var string
	 */
	public readonly string $method;

	/**
	 * The contract version that served the call.
	 *
	 * @var string
	 */
	public readonly string $apiVersion;

	/**
	 * Constructor.
	 *
	 * @param string $principal  The caller, or the empty string for anonymous.
	 * @param string $route      The route pattern.
	 * @param string $method     The HTTP method.
	 * @param string $apiVersion The contract version that served the call.
	 */
	public function __construct(string $principal, string $route, string $method, string $apiVersion) {
		$this->principal  = $principal;
		$this->route      = $route;
		$this->method     = $method;
		$this->apiVersion = $apiVersion;

	}//end __construct()
}//end class
