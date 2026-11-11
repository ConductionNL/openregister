<?php

/**
 * RevealFlusherInterface: RevealFlusher as the reveal audit middleware reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

/**
 * RevealFlusher, typed for callers outside the Service component.
 *
 * Its outside caller, the reveal audit middleware, calls flush(), so that is
 * the whole contract. Binding it in ContractBindings lets that caller resolve
 * RevealFlusher without naming the class.
 */
interface RevealFlusherInterface {
	/**
	 * Write everything collected, and leave the collector empty.
	 *
	 * @return int How many rows were written.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function flush(): int;
}//end interface
