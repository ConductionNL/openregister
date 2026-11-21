<?php

/**
 * The terms a link is minted on: what it lets its holder do, until when, behind what.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

/**
 * The declared capabilities, the expiry, the password and the label of a link, as asked.
 *
 * Nothing is checked here: AccessLinkService::mint() refuses unusable terms
 * before anything is drawn, hashed or written.
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
 */
final class AccessLinkTerms {

	/**
	 * The declared capabilities; empty means read only.
	 *
	 * @var array<int, string>
	 */
	public readonly array $capabilities;

	/**
	 * The end date, in any format DateTime parses. Required by mint().
	 *
	 * @var string|null
	 */
	public readonly ?string $expiresAt;

	/**
	 * Optional password, checked at use.
	 *
	 * @var string|null
	 */
	public readonly ?string $password;

	/**
	 * Optional human label.
	 *
	 * @var string|null
	 */
	public readonly ?string $label;

	/**
	 * The terms as asked.
	 *
	 * @param array<int, string> $capabilities The declared capabilities; empty means read only.
	 * @param string|null        $expiresAt    The end date, in any format DateTime parses.
	 * @param string|null        $password     Optional password, checked at use.
	 * @param string|null        $label        Optional human label.
	 */
	public function __construct(array $capabilities = [], ?string $expiresAt = null, ?string $password = null, ?string $label = null) {
		$this->capabilities = $capabilities;
		$this->expiresAt    = $expiresAt;
		$this->password     = $password;
		$this->label        = $label;
	}//end __construct()
}//end class
