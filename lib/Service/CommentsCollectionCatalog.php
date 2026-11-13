<?php

/**
 * CommentsCollectionCatalog
 *
 * The comments entity collections OpenRegister registers: the `openregister`
 * collection every existing comment is filed under, and one per app that
 * claims a (register, schema) pair through the deep-link registry, so a
 * comment on an app's object is attributed to that app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/platform-comments-entity/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\App\IAppManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Names the comments entity collections, says which objects each accepts,
 * and gives each a display name and icon.
 *
 * A collection is a name, not a permission boundary: whether a comment can
 * be read is decided by whether its object can be read (D-3).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @spec openspec/changes/platform-comments-entity/specs/object-interactions/spec.md
 */
class CommentsCollectionCatalog {

	/**
	 * The collection every comment was filed under before apps had their own.
	 *
	 * @var string
	 */
	public const LEGACY_COLLECTION = 'openregister';

	/**
	 * Display name of the legacy collection.
	 *
	 * @var string
	 */
	private const LEGACY_DISPLAY_NAME = 'OpenRegister';

	/**
	 * Constructor.
	 *
	 * @param DeepLinkRegistryServiceInterface $deepLinks  The apps' claimed register and schema pairs.
	 * @param IAppManager                      $appManager Reads an app's name.
	 * @param IURLGenerator                    $urls       Builds an app's icon URL.
	 * @param LoggerInterface                  $logger     Logs an app without a readable name or icon.
	 */
	public function __construct(
		private readonly DeepLinkRegistryServiceInterface $deepLinks,
		private readonly IAppManager $appManager,
		private readonly IURLGenerator $urls,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The collection names: `openregister` first, then one per claiming app.
	 *
	 * @return array<int,string> The names.
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.1
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.3
	 */
	public function names(): array {
		$names = [self::LEGACY_COLLECTION => true];
		foreach ($this->deepLinks->registrations() as $registration) {
			$appId = trim($registration->appId);
			if ($appId !== '') {
				$names[$appId] = true;
			}
		}

		return array_map('strval', array_keys($names));

	}//end names()

	/**
	 * Whether a collection accepts an object of a register and schema.
	 *
	 * The legacy collection accepts every object; an app's collection accepts
	 * only the pairs that app claimed.
	 *
	 * @param string $collection The collection name.
	 * @param int    $registerId The object's register id.
	 * @param int    $schemaId   The object's schema id.
	 *
	 * @return bool True when the collection accepts the object.
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.2
	 */
	public function accepts(string $collection, int $registerId, int $schemaId): bool {
		if ($collection === self::LEGACY_COLLECTION) {
			return true;
		}

		return $this->deepLinks->resolveAppId(registerId: $registerId, schemaId: $schemaId) === $collection;

	}//end accepts()

	/**
	 * Every collection with the name and icon a platform surface shows for it.
	 *
	 * @return array<string,array{displayName:string,icon:string}> Per collection name.
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.4
	 */
	public function collections(): array {
		$collections = [];
		foreach ($this->names() as $name) {
			$collections[$name] = [
				'displayName' => $this->displayName(appId: $name),
				'icon' => $this->icon(appId: $name),
			];
		}

		return $collections;

	}//end collections()

	/**
	 * An app's name as its appinfo gives it, else its id.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.4
	 */
	private function displayName(string $appId): string {
		if ($appId === self::LEGACY_COLLECTION) {
			return self::LEGACY_DISPLAY_NAME;
		}

		try {
			$name = trim((string)($this->appManager->getAppInfo($appId)['name'] ?? ''));
		} catch (Throwable $e) {
			$this->logger->debug(
				'CommentsCollectionCatalog: no app info for comments collection',
				['app' => $appId, 'exception' => $e->getMessage()]
			);
			$name = '';
		}

		if ($name === '') {
			return $appId;
		}

		return $name;

	}//end displayName()

	/**
	 * The URL of an app's icon, or an empty string when the app ships none.
	 *
	 * @param string $appId The app id.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/platform-comments-entity/tasks.md#task-1.4
	 */
	private function icon(string $appId): string {
		try {
			return $this->urls->imagePath($appId, 'app.svg');
		} catch (Throwable $e) {
			$this->logger->debug(
				'CommentsCollectionCatalog: no icon for comments collection',
				['app' => $appId, 'exception' => $e->getMessage()]
			);
			return '';
		}

	}//end icon()
}//end class
