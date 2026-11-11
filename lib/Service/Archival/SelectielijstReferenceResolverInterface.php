<?php

/**
 * SelectielijstReferenceResolverInterface: the part of SelectielijstReferenceResolver that the selectielijst reference controller uses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

/**
 * The methods of SelectielijstReferenceResolver that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface SelectielijstReferenceResolverInterface {

	/**
	 * Whether a URL is one this resolver will fetch: https, an allowed host, a result or process type.
	 *
	 * @param string $url The stored URL.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function isResolvable(string $url): bool;

	/**
	 * The code and name behind a selectielijst URL, read once and then from the cache.
	 *
	 * @param string $url The stored URL.
	 *
	 * @return array{url: string, code: string, name: string}|null Null when the URL is not resolvable or the row cannot be read.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function resolve(string $url): ?array;
}//end interface
