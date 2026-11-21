<?php

/**
 * OpenRegister IconCacheEntry
 *
 * One rendered notification icon's place in the appdata cache.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\WebPush
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\WebPush;

use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * A cache key in the icon cache folder; the folder is null when appdata is
 * unavailable, and then nothing is ever cached. Built by HexIconService.
 *
 * @spec openspec/specs/web-push-delivery/spec.md
 */
final class IconCacheEntry {
	/**
	 * Constructor.
	 *
	 * @param ISimpleFolder|null $folder The cache folder, or null when unavailable.
	 * @param string             $key    The cached file name.
	 */
	public function __construct(
		private readonly ?ISimpleFolder $folder,
		private readonly string $key,
	) {
	}//end __construct()

	/**
	 * Whether a rendered icon is cached under this key.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	public function exists(): bool {
		return $this->folder !== null && $this->folder->fileExists($this->key) === true;
	}//end exists()

	/**
	 * The cached PNG bytes. Call only when exists() is true.
	 *
	 * @return string
	 *
	 * @throws \Throwable When the file cannot be read.
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	public function read(): string {
		return $this->folder->getFile($this->key)->getContent();
	}//end read()

	/**
	 * Cache rendered PNG bytes; does nothing without a cache folder.
	 *
	 * @param string $png The rendered PNG bytes.
	 *
	 * @return void
	 *
	 * @throws \Throwable When the file cannot be written.
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	public function write(string $png): void {
		if ($this->folder !== null) {
			$this->folder->newFile($this->key, $png);
		}
	}//end write()
}//end class
