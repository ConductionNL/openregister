<?php

/**
 * Access links over one object, through the sharing service, for components outside Core.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
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

namespace OCA\OpenRegister\Service\Sharing;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessLinksInterface;

/**
 * The sharing service's mint, resolve and record-use, narrowed to read links over one object.
 *
 * Every rule of a link (an expiry required and in the future, 404 once revoked,
 * disabled or expired, every use audited) is the sharing service's own; this
 * class adds none and removes none.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */
class ObjectAccessLinks implements ObjectAccessLinksInterface {

	/**
	 * Constructor.
	 *
	 * @param AccessLinkServiceInterface $links The sharing service.
	 */
	public function __construct(
		private readonly AccessLinkServiceInterface $links,
	) {
	}//end __construct()

	/**
	 * Mint a read link over one object.
	 *
	 * @param string $minter     The user the link is minted for.
	 * @param string $objectUuid The object's uuid.
	 * @param string $expiresAt  The link's expiry.
	 * @param string $label      What the link is for.
	 *
	 * @return array{uuid: string, anchor: string} The link's uuid and anchor.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function mintObjectLink(string $minter, string $objectUuid, string $expiresAt, string $label): array {
		$minted = $this->links->mint(
			request: new LinkMintRequest(
				minter: $minter,
				subjectType: AccessLink::SUBJECT_OBJECT,
				subjectId: $objectUuid,
				terms: new AccessLinkTerms(expiresAt: $expiresAt, label: $label)
			)
		);

		return ['uuid' => (string)($minted['uuid'] ?? ''), 'anchor' => (string)($minted['anchor'] ?? '')];
	}//end mintObjectLink()

	/**
	 * The live link over one object behind an anchor, or null.
	 *
	 * @param string $anchor The anchor.
	 *
	 * @return AccessLinkInterface|null The link, or null.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
	 */
	public function liveObjectLink(string $anchor): ?AccessLinkInterface {
		$link = $this->links->resolve(anchor: $anchor);
		if ($link === null || $link->getSubjectType() !== AccessLink::SUBJECT_OBJECT) {
			return null;
		}

		return $link;
	}//end liveObjectLink()

	/**
	 * Record one use of a link on the object's audit trail.
	 *
	 * A link or an object this service did not hand out is not recorded: there is
	 * no audit entry to write for a link the sharing service does not know.
	 *
	 * @param AccessLinkInterface   $link      The link.
	 * @param ObjectEntityInterface $object    The object.
	 * @param string                $act       The audit action.
	 * @param string|null           $ipAddress The address.
	 * @param array<string, mixed>  $context   The audit context.
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
	): void {
		if ($link instanceof AccessLink === false || $object instanceof ObjectEntity === false) {
			return;
		}

		$this->links->recordUse(
			link: $link,
			use: new AccessLinkUse(act: $act, object: $object, ipAddress: $ipAddress, context: $context)
		);
	}//end recordObjectUse()
}//end class
