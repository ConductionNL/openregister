<?php

/**
 * LifecycleActionContextInterface: what callers outside the component use of LifecycleActionContext.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Declares only what callers outside the component use of LifecycleActionContext; the rest
 * of the class stays its own.
 */
interface LifecycleActionContextInterface {

	/**
	 * The app a transition on this object currently runs as the system for, if any.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return string|null The innermost app id, or null for an ordinary write.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function systemAppFor(string $uuid): ?string;
}//end interface
