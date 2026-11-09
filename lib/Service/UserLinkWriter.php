<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ContactLink;
use OCA\OpenRegister\Db\ContactLinkMapperInterface;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Writes the link of a Nextcloud user on an object: the account side of
 * people-on-objects, as ContactService is the CardDAV side.
 *
 * A user link stores `user:<uid>` as its contact uid, takes display name,
 * email and avatar from the account, and needs no Contacts app.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
 */
class UserLinkWriter {

	/**
	 * @param ContactLinkMapperInterface $links The link rows.
	 * @param IUserManager $users The accounts.
	 * @param IUserSession $session The signed-in user, recorded as the linker.
	 * @param IURLGenerator $urls The avatar route.
	 */
	public function __construct(
		private readonly ContactLinkMapperInterface $links,
		private readonly IUserManager $users,
		private readonly IUserSession $session,
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * Write a user link, or refresh the one the user already holds in that role.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param int $registerId The register id.
	 * @param int|null $schemaId The schema id.
	 * @param string $userId The account.
	 * @param string|null $role The role.
	 *
	 * @return ContactLink The link as stored.
	 *
	 * @throws Exception 404 when no account has the id, 401 when nobody is signed in.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
	 */
	public function write(string $objectUuid, int $registerId, ?int $schemaId, string $userId, ?string $role): ContactLink {
		$user = $this->users->get($userId);
		if ($user === null) {
			throw new Exception('User not found', 404);
		}

		$linker = $this->session->getUser();
		if ($linker === null) {
			throw new Exception('No user logged in', 401);
		}

		$contactUid = ContactLink::USER_UID_PREFIX . $user->getUID();
		$link = $this->links->findByObjectContactAndRole(objectUuid: $objectUuid, contactUid: $contactUid, role: $role);
		$isNew = ($link === null);
		$link = ($link ?? $this->newLink(objectUuid: $objectUuid, contactUid: $contactUid));

		$link->setRegisterId($registerId);
		$link->setSchemaId($schemaId);
		$link->setUserId($user->getUID());
		$link->setDisplayName($user->getDisplayName());
		$link->setEmail($user->getEMailAddress());
		$link->setAvatarUrl($this->urls->linkToRoute('core.avatar.getAvatar', ['userId' => $user->getUID(), 'size' => 64]));
		$link->setRole($role);
		$link->setLinkedBy($linker->getUID());
		$link->setLinkedAt(new DateTime());

		return $this->persist(link: $link, isNew: $isNew);
	}//end write()

	/**
	 * A new, unsaved link of a contact on an object.
	 *
	 * @param string $objectUuid The object uuid.
	 * @param string $contactUid The contact uid, `user:<uid>`.
	 *
	 * @return ContactLink The link.
	 */
	private function newLink(string $objectUuid, string $contactUid): ContactLink {
		$link = new ContactLink();
		$link->setObjectUuid($objectUuid);
		$link->setContactUid($contactUid);

		return $link;
	}//end newLink()

	/**
	 * Insert a new link, update an existing one.
	 *
	 * @param ContactLink $link  The link.
	 * @param bool        $isNew Whether no row existed for it.
	 *
	 * @return ContactLink The stored link.
	 */
	private function persist(ContactLink $link, bool $isNew): ContactLink {
		if ($isNew === true) {
			return $this->links->insert($link);
		}

		return $this->links->update($link);
	}//end persist()
}//end class
