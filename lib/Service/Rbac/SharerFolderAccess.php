<?php

/**
 * The sharer's view of an object folder.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\Exceptions\AlreadySharedException;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gives a user who may share an object a view of its folder, and ends that view.
 *
 * An object's folder belongs to the openregister account, and core lets a user
 * share a node only as far as that user can reach it. So before a user creates
 * a grant or a link in their own name, the openregister account shares the
 * folder with them: a user share marked as a sharer view, carrying exactly the
 * file rights OpenRegister gives them on the object and never the re-share bit,
 * so re-sharing goes through OpenRegister's own checks. The view grants nothing
 * ({@see ShareGrantAttributes::objectUuidOf()}) and is ended once its holder may
 * no longer share the object, or is removed. The grants and links the holder
 * made on the folder end with it: a share is made in its sharer's name and is
 * revoked with them.
 *
 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It spans core's share surface (manager, share, attributes,
 * the already-shared refusal), the mounts that make a new view count in the request, and the two answers it
 * acts on: what the user may do with the files and whether they may still share. Splitting it would put the
 * open and the end of one view in different places.
 */
class SharerFolderAccess {

	/**
	 * The share types a sharer can create on an object's folder.
	 */
	private const GRANT_TYPES = [
		IShare::TYPE_USER,
		IShare::TYPE_GROUP,
		IShare::TYPE_REMOTE,
		IShare::TYPE_REMOTE_GROUP,
		IShare::TYPE_LINK,
		IShare::TYPE_EMAIL,
	];

	/**
	 * Constructor.
	 *
	 * @param IManager         $shareManager Core's share manager.
	 * @param ObjectFileAccess $fileAccess   What the signed-in user may do with an object's files.
	 * @param GrantManageGuard $grantManage  Who may manage an object's grants.
	 * @param IUserManager     $userManager  Resolves a view's holder.
	 * @param IGroupManager    $groupManager The holder's groups.
	 * @param LoggerInterface  $logger       Diagnostics.
	 * @param MountRefresh     $mounts       The mount providers and the mount cache, to mount a new view at once.
	 */
	public function __construct(
		private readonly IManager $shareManager,
		private readonly ObjectFileAccess $fileAccess,
		private readonly GrantManageGuard $grantManage,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly MountRefresh $mounts,
	) {
	}//end __construct()

	/**
	 * Make sure the signed-in user, who may share the object, can reach its folder to share it.
	 *
	 * Refuses a share asking for rights the user does not hold on the object's
	 * files, so a grant or a link never carries more than its sharer may do.
	 *
	 * @param ObjectEntity $object      The object, already checked: the caller may share it.
	 * @param Folder       $folder      The object's folder.
	 * @param string       $uid         The signed-in user.
	 * @param int          $permissions The core permissions the share will carry.
	 *
	 * @throws NotAuthorizedException When the share asks for more than the user holds.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function ensureFor(ObjectEntity $object, Folder $folder, string $uid, int $permissions): void {
		$owner = $folder->getOwner()?->getUID();
		if ($owner === null || $owner === $uid) {
			return;
		}

		$held = $this->heldByCaller(object: $object);
		if (($held & Constants::PERMISSION_READ) === 0 || ($permissions & ~$held) !== 0) {
			throw new NotAuthorizedException(
				message: 'A share cannot carry more than you may do with this object'
			);
		}

		$views = $this->viewsOn(folder: $folder, owner: $owner);
		$this->endStaleViews(object: $object, views: $views);

		foreach ($views as $view) {
			if ($view->getSharedWith() === $uid) {
				$this->keepRights(view: $view, held: $held);
				return;
			}
		}

		$this->openView(folder: $folder, owner: $owner, uid: $uid, held: $held);
	}//end ensureFor()

	/**
	 * End one user's view of an object's folder when that user may no longer share the object.
	 *
	 * @param ObjectEntity $object The object, as it stands now.
	 * @param string       $uid    The user who may have lost it.
	 *
	 * @return int How many views were ended.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function endViewOf(ObjectEntity $object, string $uid): int {
		try {
			$received = $this->shareManager->getSharedWith($uid, IShare::TYPE_USER, null, -1);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SharerFolderAccess] Could not read the shares of a former sharer; the sweep ends their view',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return 0;
		}

		$views = array_values(array_filter($received, fn (IShare $share): bool => $this->isViewOn(share: $share, object: $object)));

		return $this->endStaleViews(object: $object, views: $views);
	}//end endViewOf()

	/**
	 * Every sharer view the openregister account has given.
	 *
	 * @param string $owner The openregister account's uid.
	 *
	 * @return IShare[] The views.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function allViews(string $owner): array {
		return array_values(
			array_filter(
				$this->shareManager->getSharesBy($owner, IShare::TYPE_USER, null, false, -1),
				fn (IShare $share): bool => $this->attributes()->isSharerAccess(share: $share)
			)
		);
	}//end allViews()

	/**
	 * End the views whose holder may no longer share the object; with no object, end them all.
	 *
	 * @param ObjectEntity|null $object The object, or null when it is gone.
	 * @param IShare[]          $views  The views on its folder.
	 *
	 * @return int How many views were ended.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function endStaleViews(?ObjectEntity $object, array $views): int {
		$ended = 0;
		foreach ($views as $view) {
			$holder = (string)$view->getSharedWith();
			if ($object !== null && $this->mayShare(uid: $holder, object: $object) === true) {
				continue;
			}

			try {
				$this->revokeGrantsOf(view: $view);
				$this->shareManager->deleteShare($view);
				$ended++;
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[SharerFolderAccess] Could not end a sharer view',
					context: ['file' => __FILE__, 'line' => __LINE__, 'share' => $view->getId(), 'error' => $e->getMessage()]
				);
			}
		}

		return $ended;
	}//end endStaleViews()

	/**
	 * End every sharer view a user holds, with the grants and links they made through it.
	 *
	 * Asked before the user is removed: core then deletes the views without
	 * looking at the grants their holder made on the folders.
	 *
	 * @param string $uid The user.
	 *
	 * @return int How many views were ended.
	 *
	 * @spec openspec/changes/object-level-sharing-and-private-scope/specs/object-level-sharing/spec.md
	 */
	public function endAllViewsOf(string $uid): int {
		$views = array_values(
			array_filter(
				$this->shareManager->getSharedWith($uid, IShare::TYPE_USER, null, -1),
				fn (IShare $share): bool => $this->attributes()->isSharerAccess(share: $share)
			)
		);

		return $this->endStaleViews(object: null, views: $views);
	}//end endAllViewsOf()

	/**
	 * Revoke the grants and links a view's holder made on its folder.
	 *
	 * A share is made in its sharer's name and ends with their right to share.
	 * Revoked before the view itself ends, so core has nothing left to pass on
	 * to the folder's account.
	 *
	 * @param IShare $view The view about to end.
	 *
	 * @return void
	 */
	private function revokeGrantsOf(IShare $view): void {
		$holder = (string)$view->getSharedWith();
		try {
			$folder = $view->getNode();
			foreach (self::GRANT_TYPES as $type) {
				if ($this->shareManager->shareProviderExists($type) === false) {
					continue;
				}

				foreach ($this->shareManager->getSharesBy($holder, $type, $folder, false, -1) as $grant) {
					$this->shareManager->deleteShare($grant);
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SharerFolderAccess] Could not revoke every grant of a former sharer; the view still ends',
				context: ['file' => __FILE__, 'line' => __LINE__, 'share' => $view->getId(), 'error' => $e->getMessage()]
			);
		}
	}//end revokeGrantsOf()

	/**
	 * The core permissions the signed-in user holds on an object's files, without the re-share bit.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return int The permission mask.
	 */
	private function heldByCaller(ObjectEntity $object): int {
		// The caller passed the manage-grants check, which already places them
		// in the object's organisation or admits them unconditionally.
		if ($this->fileAccess->mayRead(object: $object, multitenancy: false) === false) {
			return 0;
		}

		$held = Constants::PERMISSION_READ;
		if ($this->fileAccess->mayChangeFiles(object: $object, verb: ObjectFileAccess::ADD_FILES) === true) {
			$held |= (Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE);
		}

		if ($this->fileAccess->mayChangeFiles(object: $object, verb: ObjectFileAccess::DELETE_FILES) === true) {
			$held |= Constants::PERMISSION_DELETE;
		}

		return $held;
	}//end heldByCaller()

	/**
	 * Whether a user may share the object: the owner, an administrator, or a holder of the right to manage grants.
	 *
	 * @param string       $uid    The user.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function mayShare(string $uid, ObjectEntity $object): bool {
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return false;
		}

		return $this->grantManage->admittedAs(
			caller: new GrantCaller(userId: $uid, userGroups: $this->groupManager->getUserGroupIds($user)),
			object: $object
		) !== null;
	}//end mayShare()

	/**
	 * The sharer views on one folder.
	 *
	 * @param Folder $folder The object's folder.
	 * @param string $owner  The openregister account's uid.
	 *
	 * @return IShare[] The views.
	 */
	private function viewsOn(Folder $folder, string $owner): array {
		return array_values(
			array_filter(
				$this->shareManager->getSharesBy($owner, IShare::TYPE_USER, $folder, false, -1),
				fn (IShare $share): bool => $this->attributes()->isSharerAccess(share: $share)
			)
		);
	}//end viewsOn()

	/**
	 * Whether a share is a sharer view of this object's folder.
	 *
	 * @param IShare       $share  The share.
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool
	 */
	private function isViewOn(IShare $share, ObjectEntity $object): bool {
		if ($this->attributes()->isSharerAccess(share: $share) === false) {
			return false;
		}

		try {
			return $share->getNode()->getName() === $object->getUuid();
		} catch (Throwable $e) {
			return false;
		}
	}//end isViewOn()

	/**
	 * Bring an existing view to the rights its holder holds now.
	 *
	 * @param IShare $view The view.
	 * @param int    $held The rights held now.
	 *
	 * @return void
	 */
	private function keepRights(IShare $view, int $held): void {
		if ($view->getPermissions() === $held) {
			return;
		}

		$view->setPermissions($held);
		$this->shareManager->updateShare($view);
	}//end keepRights()

	/**
	 * Share the folder from the openregister account with the user, as a marked sharer view.
	 *
	 * @param Folder $folder The object's folder.
	 * @param string $owner  The openregister account's uid.
	 * @param string $uid    The user.
	 * @param int    $held   The rights the user holds on the object's files.
	 *
	 * @return void
	 */
	private function openView(Folder $folder, string $owner, string $uid, int $held): void {
		$view = $this->shareManager->newShare();
		$view->setNode($folder);
		$view->setShareType(IShare::TYPE_USER);
		$view->setSharedBy($owner);
		$view->setSharedWith($uid);
		$view->setPermissions($held);

		$attributes = $view->newAttributes();
		$attributes->setAttribute(ShareGrantAttributes::VERB_ATTRIBUTE_SCOPE, ShareGrantAttributes::SHARER_ACCESS_KEY, true);
		$view->setAttributes($attributes);

		try {
			$created = $this->shareManager->createShare($view);
		} catch (AlreadySharedException $e) {
			// The user already reaches the folder through a grant of their own,
			// which core weighs when the share is created.
			return;
		}

		// A view is not an offer for the user to accept: without it they cannot share.
		$this->shareManager->acceptShare($created, $uid);

		$user = $this->userManager->get($uid);
		if ($user !== null) {
			$this->mountView(user: $user, folder: $folder);
		}
	}//end openView()

	/**
	 * Mount a new view for its holder now, so it counts within this request.
	 *
	 * Core weighs what the sharer reaches through the mount cache and the mounts
	 * set up in the request. Files sharing records a new share there only within
	 * a time budget per request, and a user whose mounts are already set up
	 * does not see it until the next request.
	 *
	 * @param IUser  $user   The view's holder.
	 * @param Folder $folder The object's folder.
	 *
	 * @return void
	 */
	private function mountView(IUser $user, Folder $folder): void {
		$mounts = $this->mounts->providers->getMountsForUser($user);
		$this->mounts->cache->registerMounts($user, $mounts);

		foreach ($mounts as $mount) {
			if ($mount->getStorageRootId() === $folder->getId()
				&& $this->mounts->manager->find($mount->getMountPoint())?->getMountPoint() !== $mount->getMountPoint()
			) {
				$this->mounts->manager->addMount($mount);
			}
		}
	}//end mountView()

	/**
	 * The share attribute reader.
	 *
	 * @return ShareGrantAttributes
	 */
	private function attributes(): ShareGrantAttributes {
		return new ShareGrantAttributes();
	}//end attributes()
}//end class
