<?php

/**
 * ContentProviderInterface: what callers outside the ContextChat component use of ContentProvider.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ContextChat
 * @package  OCA\OpenRegister\ContextChat
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\ContextChat;

/**
 * The ids the Context Chat provider is registered under, and its reindex walk.
 *
 * Deliberately not an extension of `OCP\ContextChat\IContentProvider`: that
 * interface is missing before Nextcloud 32, and reading the constants here
 * must not load it.
 */
interface ContentProviderInterface {

	/**
	 * The app id this provider is registered under.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * The provider id this provider is registered under.
	 *
	 * @var string
	 */
	public const PROVIDER_ID = 'openregister_objects';

	/**
	 * Batched (re)submission walk, optionally scoped to a single register and/or schema.
	 *
	 * @param int|null $registerId Optional register id to scope the walk to.
	 * @param int|null $schemaId   Optional schema id to scope the walk to.
	 *
	 * @return int Number of objects actually submitted (published, opted-in objects).
	 */
	public function reindex(?int $registerId, ?int $schemaId): int;
}//end interface
