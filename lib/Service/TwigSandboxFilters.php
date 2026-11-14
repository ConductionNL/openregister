<?php

/**
 * OpenRegister TwigSandboxFilters
 *
 * The Twig filters every user-authored template sandbox allows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * The value-shaping Twig filters that both the JWT payload sandbox
 * (AuthenticationService) and the computed-field sandbox (ComputedFieldHandler)
 * allow. Each sandbox appends its own filters after these.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class TwigSandboxFilters {

	/**
	 * The filters common to every template sandbox, in allowlist order.
	 *
	 * @var list<string>
	 */
	public const COMMON = [
		'date',
		'date_modify',
		'upper',
		'lower',
		'trim',
		'length',
		'default',
		'number_format',
		'round',
		'abs',
		'split',
		'join',
		'slice',
		'first',
		'last',
		'replace',
		'format',
	];
}//end class
