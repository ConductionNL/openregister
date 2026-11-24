<?php

/**
 * OpenRegister access links managed per subject
 *
 * Whoever may publish a subject sees and revokes every link on it, whoever
 * minted it.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/access-links-managed-per-subject/specs/public-access-links/spec.md#requirement-whoever-may-publish-a-subject-sees-and-revokes-every-link-on-it-req-abl-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use DateTime;
use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\AccessLinkMapperInterface;

/**
 * Lists and revokes every link on one subject, for a caller who may publish it.
 *
 * The mint guard is the whole access decision, here as at mint: a caller who
 * may publish a subject may also see and end every link on it. Somebody who may
 * not gets the same answer a mint gives, nothing. A link somebody else minted
 * is listed without its anchor: the caller may see and end it, not hand it on.
 *
 * @spec openspec/changes/access-links-managed-per-subject/specs/public-access-links/spec.md#requirement-whoever-may-publish-a-subject-sees-and-revokes-every-link-on-it-req-abl-005
 */
class AccessLinkSubjectLinks {
	/**
	 * Constructor.
	 *
	 * @param AccessLinkMapperInterface    $mapper    The link store.
	 * @param AccessLinkMintGuardInterface $mintGuard Decides whether the caller may publish the subject.
	 * @param AccessLinkServiceInterface   $links     Builds the URLs of the caller's own links.
	 */
	public function __construct(
		private readonly AccessLinkMapperInterface $mapper,
		private readonly AccessLinkMintGuardInterface $mintGuard,
		private readonly AccessLinkServiceInterface $links,
	) {
	}//end __construct()

	/**
	 * Every link on the subject, or null when the caller may not publish it.
	 *
	 * @param string $subjectType The subject kind.
	 * @param string $subjectId   The subject identifier.
	 * @param string $userId      The principal asking.
	 *
	 * @return array<int, array<string, mixed>>|null The serialised links, newest first, or null.
	 *
	 * @spec openspec/changes/access-links-managed-per-subject/specs/public-access-links/spec.md#requirement-whoever-may-publish-a-subject-sees-and-revokes-every-link-on-it-req-abl-005
	 */
	public function listOn(string $subjectType, string $subjectId, string $userId): ?array {
		if ($this->mintGuard->mayMint(subjectType: $subjectType, subjectId: $subjectId) === false) {
			return null;
		}

		$userId = trim($userId);
		$rows = [];
		foreach ($this->mapper->findBySubject(subjectType: $subjectType, subjectId: $subjectId) as $link) {
			$rows[] = $this->describe(link: $link, userId: $userId);
		}

		return $rows;
	}//end listOn()

	/**
	 * Revoke any link on the subject, when the caller may publish the subject.
	 *
	 * A link on another subject is, to this caller, a link that does not exist.
	 *
	 * @param int    $id          The link row id.
	 * @param string $subjectType The subject the caller names.
	 * @param string $subjectId   The subject identifier.
	 *
	 * @return bool True when the link is revoked, false when there was none on that subject to revoke.
	 *
	 * @spec openspec/changes/access-links-managed-per-subject/specs/public-access-links/spec.md#requirement-whoever-may-publish-a-subject-sees-and-revokes-every-link-on-it-req-abl-005
	 */
	public function revokeOn(int $id, string $subjectType, string $subjectId): bool {
		if ($subjectType === ''
			|| $this->mintGuard->mayMint(subjectType: $subjectType, subjectId: $subjectId) === false
		) {
			return false;
		}

		$link = $this->mapper->findById(id: $id);
		if ($link === null
			|| $link->getSubjectType() !== $subjectType
			|| $link->getSubjectId() !== $subjectId
		) {
			return false;
		}

		if ($link->getRevokedAt() === null) {
			$link->setRevokedAt(new DateTime());
			$this->mapper->update($link);
		}

		return true;
	}//end revokeOn()

	/**
	 * One link for this caller: their own with its anchor and URLs, anybody else's without.
	 *
	 * @param AccessLink $link   The link.
	 * @param string     $userId The trimmed principal asking.
	 *
	 * @return array<string, mixed> The serialised link.
	 */
	private function describe(AccessLink $link, string $userId): array {
		$data = $link->jsonSerialize();
		if ($userId === '' || $link->getCreatedBy() !== $userId) {
			unset($data['anchor']);
			return $data;
		}

		$data['url'] = $this->links->linkUrl(anchor: (string)$link->getAnchor());
		$data['pageUrl'] = $this->links->pageUrl(anchor: (string)$link->getAnchor());

		return $data;
	}//end describe()
}//end class
