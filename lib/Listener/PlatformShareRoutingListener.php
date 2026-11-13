<?php

/**
 * A share made from the platform's own sharing surface on an object's folder passes the platform share gate.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Sharing\PlatformShareGate;
use OCA\OpenRegister\Service\Sharing\PlatformSharePermissions;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Folder;
use OCP\IRequest;
use OCP\Share\Events\BeforeShareCreatedEvent;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Routes a share created through the platform sharing API on an object folder through the gate.
 *
 * An object grant already is a core folder share on the object's folder, so
 * the object's own share list and the platform's lists show the same row:
 * one model, no second store (design D-1). What the platform surface lacked
 * was the gate. A share created there on an object's folder now has to pass
 * the schema's opt-in (design D-3) and the explicit permission mapping
 * (design D-2); a refusal is set as the event's error, which core answers
 * by not creating the share, naming the reason.
 *
 * Only the platform sharing API is gated. OpenRegister's own grant, link and
 * sharer-view writes create core shares on the same folders through their
 * own routes and keep their own checks.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
 */
final class PlatformShareRoutingListener implements IEventListener {

	/**
	 * The request path of the platform sharing API.
	 *
	 * @var string
	 */
	public const PLATFORM_SHARE_API = '/apps/files_sharing/api/';

	/**
	 * The shape of an object folder's name: the object's uuid.
	 *
	 * @var string
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * The permission mapping.
	 *
	 * @var PlatformSharePermissions
	 */
	private readonly PlatformSharePermissions $permissions;

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request     The request the share is created in.
	 * @param MagicMapperInterface          $objects     Finds the object a folder belongs to.
	 * @param SchemaMapperInterface         $schemas     Loads the object's schema.
	 * @param PlatformShareGate             $gate        The schema's opt-in.
	 * @param LoggerInterface               $logger      Where an unreadable folder is reported.
	 * @param PlatformSharePermissions|null $permissions The permission mapping; the default one when null.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly MagicMapperInterface $objects,
		private readonly SchemaMapperInterface $schemas,
		private readonly PlatformShareGate $gate,
		private readonly LoggerInterface $logger,
		?PlatformSharePermissions $permissions = null,
	) {
		$this->permissions = ($permissions ?? new PlatformSharePermissions());
	}//end __construct()

	/**
	 * Refuse a platform share of an object folder the gate does not admit.
	 *
	 * @param Event $event The share about to be created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md#requirement-an-object-share-is-a-nextcloud-share-req-psh-001
	 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md#requirement-permission-bits-map-explicitly-and-an-unmapped-bit-is-refused-req-psh-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof BeforeShareCreatedEvent) === false || $this->fromPlatformSurface() === false) {
			return;
		}

		$share  = $event->getShare();
		$object = $this->objectOf(share: $share);
		if ($object === null) {
			return;
		}

		$refusal = $this->refusal(object: $object, permissions: (int)$share->getPermissions());
		if ($refusal !== null) {
			$event->setError($refusal);
		}
	}//end handle()

	/**
	 * Why the gate refuses this share, or null when it admits it.
	 *
	 * The schema's opt-in first, then every permission bit, the order the gate itself checks in.
	 *
	 * @param ObjectEntity $object      The object whose folder is shared.
	 * @param int          $permissions The share's permission bitmask.
	 *
	 * @return string|null The refusal, or null.
	 *
	 * @spec openspec/changes/platform-share-provider/specs/platform-share-provider/spec.md
	 */
	public function refusal(ObjectEntity $object, int $permissions): ?string {
		$schema = $this->schemaOf(object: $object);
		if ($schema === null || $this->gate->isOffered(schema: $schema) === false) {
			return 'This record is not offered for sharing here';
		}

		try {
			$this->permissions->verbsFor(permissions: $permissions);
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
		}

		return null;
	}//end refusal()

	/**
	 * Whether the request is the platform sharing API.
	 *
	 * @return bool
	 */
	private function fromPlatformSurface(): bool {
		try {
			return str_contains((string)$this->request->getPathInfo(), self::PLATFORM_SHARE_API) === true;
		} catch (Throwable $e) {
			return false;
		}
	}//end fromPlatformSurface()

	/**
	 * The object whose own folder the share is on, or null for any other node.
	 *
	 * The folder is named by the object's uuid; it is the object's folder only
	 * when that object records this very folder, so a user's own folder that
	 * happens to carry a uuid as its name is never taken for one.
	 *
	 * @param IShare $share The share.
	 *
	 * @return ObjectEntity|null
	 */
	private function objectOf(IShare $share): ?ObjectEntity {
		try {
			$node = $share->getNode();
			if (($node instanceof Folder) === false || preg_match(self::UUID, (string)$node->getName()) !== 1) {
				return null;
			}

			foreach ($this->objects->findStoredAcrossAllMagicTables(uuids: [(string)$node->getName()]) as $object) {
				if ((string)$object->getFolder() === (string)$node->getId()) {
					return $object;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[PlatformShareRoutingListener] Could not tell whether a shared folder belongs to an object',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return null;
	}//end objectOf()

	/**
	 * The object's schema, or null when it cannot be loaded.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Schema|null
	 */
	private function schemaOf(ObjectEntity $object): ?Schema {
		try {
			return $this->schemas->find(id: (string)$object->getSchema());
		} catch (Throwable $e) {
			return null;
		}
	}//end schemaOf()
}//end class
