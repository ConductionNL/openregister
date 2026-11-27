<?php

/**
 * AddressAllowlistsInterface: whether an address may reach a surface, as the address allowlist middleware asks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Answers whether a request address may reach the administration surface or the API.
 *
 * The middleware (Platform) asks before a controller runs; the hardening
 * policy (Compliance) answers from the administered allowlists and binds
 * itself to this contract in its own wiring.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */
interface AddressAllowlistsInterface {

	/**
	 * The administration surface: every route that requires an administrator.
	 *
	 * @var string
	 */
	public const ADMIN = 'admin';

	/**
	 * The API surface: every route under the app's `/api/` path.
	 *
	 * @var string
	 */
	public const API = 'api';

	/**
	 * Whether an address may reach a surface.
	 *
	 * True when the surface is not bound (its allowlist is empty) or an entry
	 * on its allowlist holds the address. An address that cannot be read is
	 * held by no entry. An unknown surface is not bound.
	 *
	 * @param string $surface `admin` or `api`.
	 * @param string $address The request's remote address.
	 *
	 * @return bool True when the request may go on.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function allowsAddress(string $surface, string $address): bool;
}//end interface
