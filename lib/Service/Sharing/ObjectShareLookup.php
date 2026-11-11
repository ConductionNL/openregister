<?php

/**
 * Lazy lookups the share listing needs around one OpenRegister object.
 *
 * Shared by ShareLinkService and the shares integration provider: both
 * resolve the share manager, the object's Nextcloud folder, the object
 * entity and the current user through the server container at call time,
 * and both degrade to null when a service is absent or throws.
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
 * @link https://conduction.nl
 *
 * @spec openspec/specs/integration-shares/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sharing;

use OCP\Files\Folder;
use OCP\Server;
use OCP\Share\IShare;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Container-backed lookups for listing the shares of an object.
 *
 * @spec openspec/specs/integration-shares/spec.md
 *
 * @SuppressWarnings(PHPMD.StaticAccess) \OCP\Server::get() is the Nextcloud-prescribed static
 * service-locator for optional late-bound dependencies; constructor injection would fail when
 * optional services are absent.
 */
final class ObjectShareLookup {

	/**
	 * Share types listed for an object, in the order the share manager is asked.
	 *
	 * @var int[]
	 */
	public const SHARE_TYPES = [
		IShare::TYPE_USER,
		IShare::TYPE_GROUP,
		IShare::TYPE_LINK,
		IShare::TYPE_EMAIL,
		IShare::TYPE_REMOTE,
		IShare::TYPE_REMOTE_GROUP,
	];

	/**
	 * Maximum nodes inside the object's folder that are walked.
	 *
	 * @var int
	 */
	public const MAX_NODES = 50;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface|null $container Server-container override (tests only); null uses
	 *                                           Nextcloud's global server container.
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 */
	public function __construct(
		private readonly ?ContainerInterface $container = null,
	) {
	}//end __construct()

	/**
	 * Resolve a service from the container.
	 *
	 * @param string $serviceName Fully qualified class name to resolve.
	 *
	 * @return object|null The service, or null when absent or failing.
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 */
	public function lookup(string $serviceName): ?object {
		try {
			$service = $this->container()->get($serviceName);
			if (is_object($service) === true) {
				return $service;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end lookup()

	/**
	 * The object's Nextcloud folder.
	 *
	 * The ObjectEntity is resolved first so FileService::getObjectFolder()
	 * runs its entity-input branch; the string-input branch has no register
	 * and throws "no objectEntity or registerId given".
	 *
	 * @param string $objectUuid Owning object uuid.
	 *
	 * @return Folder|null The folder, or null when it cannot be resolved.
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 */
	public function objectFolder(string $objectUuid): ?Folder {
		try {
			$handler = $this->lookup(
				serviceName: 'OCA\\OpenRegister\\Service\\FileService'
			);
			if ($handler === null) {
				return null;
			}

			$entity = $this->objectEntity(objectUuid: $objectUuid);
			$folder = $handler->getObjectFolder($entity ?? $objectUuid);
			if (($folder instanceof Folder) === true) {
				return $folder;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end objectFolder()

	/**
	 * The ObjectEntity with this uuid.
	 *
	 * @param string $objectUuid Object uuid.
	 *
	 * @return object|null The entity, or null on any failure.
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 */
	public function objectEntity(string $objectUuid): ?object {
		try {
			$mapper = $this->lookup(
				serviceName: 'OCA\\OpenRegister\\Db\\MagicMapper'
			);
			if ($mapper === null) {
				return null;
			}

			$entity = $mapper->find($objectUuid);
			if (is_object($entity) === true) {
				return $entity;
			}

			return null;
		} catch (Throwable $e) {
			return null;
		}
	}//end objectEntity()

	/**
	 * The current user's UID.
	 *
	 * @return string|null The UID, or null without a session user.
	 *
	 * @spec openspec/specs/integration-shares/spec.md
	 */
	public function currentUserId(): ?string {
		try {
			$session = $this->lookup(serviceName: 'OCP\\IUserSession');
			if ($session === null) {
				return null;
			}

			$user = $session->getUser();
			if ($user === null) {
				return null;
			}

			return $user->getUID();
		} catch (Throwable $e) {
			return null;
		}
	}//end currentUserId()

	/**
	 * The active container: the test override if given, otherwise the server container.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		if ($this->container !== null) {
			return $this->container;
		}

		return new class implements ContainerInterface {
			/**
			 * Resolve a service from the NC server container.
			 *
			 * @param string $id Service id.
			 *
			 * @return object
			 *
			 * @spec exclude Plumbing: PSR-11 adapter method around Server::get() on an inline anonymous container; no standalone behavioral contract.
			 */
			public function get(string $id): object {
				// phpcs:ignore CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- PSR-11 adapter shim: this IS the container being handed to a collaborator, so there is no container to inject into it.
				return Server::get($id);
			}//end get()

			/**
			 * Whether the NC server container can resolve a service.
			 *
			 * @param string $id Service id.
			 *
			 * @return bool
			 *
			 * @spec exclude Plumbing: PSR-11 adapter method around Server::get() on an inline anonymous container; no standalone behavioral contract.
			 */
			public function has(string $id): bool {
				try {
					// phpcs:ignore CustomSniffs.Nextcloud.NoServiceLocator.GlobalContainerLookup -- PSR-11 adapter shim: this IS the container being handed to a collaborator, so there is no container to inject into it.
					Server::get($id);
					return true;
				} catch (Throwable $e) {
					return false;
				}
			}//end has()
		};
	}//end container()
}//end class
