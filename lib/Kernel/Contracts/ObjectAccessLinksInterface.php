<?php

/**
 * Access links over one object, as another component mints, admits and audits them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;

/**
 * The part of access-by-link-not-by-account a component other than Core builds on.
 *
 * A survey invitation is a link over its invitation object; it reuses the link
 * rather than inventing a second token scheme, and reaches it through this
 * contract rather than through the sharing service itself.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */
interface ObjectAccessLinksInterface {

	/**
	 * The brute-force action every public link door counts its misses under.
	 */
	public const THROTTLE_ACTION = 'openregister_access_link';

	/**
	 * The audit action of a read through a link.
	 */
	public const ACT_READ = 'accesslink.read';

	/**
	 * Mint a read link over one object.
	 *
	 * @param string $minter     The user the link is minted for.
	 * @param string $objectUuid The object's uuid.
	 * @param string $expiresAt  The link's expiry, as a date this instance can read.
	 * @param string $label      What the link is for, as its owner reads it.
	 *
	 * @return array{uuid: string, anchor: string} The link's uuid and the anchor to hand out.
	 *
	 * @throws InvalidArgumentException When the link cannot be minted.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function mintObjectLink(string $minter, string $objectUuid, string $expiresAt, string $label): array;

	/**
	 * The live link over one object behind an anchor, or null.
	 *
	 * @param string $anchor The anchor.
	 *
	 * @return AccessLinkInterface|null The link, or null when it is unknown, revoked, disabled, expired or not over an object.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function liveObjectLink(string $anchor): ?AccessLinkInterface;

	/**
	 * Record one use of a link on the object's audit trail, and count it on the link.
	 *
	 * @param AccessLinkInterface   $link      The link.
	 * @param ObjectEntityInterface $object    The object it was used on.
	 * @param string                $act       The audit action.
	 * @param string|null           $ipAddress The address it came from.
	 * @param array<string, mixed>  $context   What else the audit entry carries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function recordObjectUse(
		AccessLinkInterface $link,
		ObjectEntityInterface $object,
		string $act,
		?string $ipAddress,
		array $context = []
	): void;
}//end interface
