<?php

/**
 * PresencePushInterface: what callers outside the Listener component use of NotifyPushListener.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Pushes who has an object open to the clients watching it.
 *
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
 */
interface PresencePushInterface {

	/**
	 * Push the current set of people who have the object open.
	 *
	 * @param ObjectEntity                     $object  The object whose readers changed.
	 * @param array<int, array<string, mixed>> $present Who is present now.
	 *
	 * @return bool Whether a push was sent.
	 *
	 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md
	 */
	public function pushPresence(ObjectEntity $object, array $present): bool;
}//end interface
