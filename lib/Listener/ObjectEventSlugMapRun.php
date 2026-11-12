<?php

/**
 * OpenRegister ObjectEventSlugMapRun
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCP\ICache;

/**
 * One resolution of the declared register or schema slugs to their ids.
 *
 * Built by {@see ObjectEventProxyListener} when a slug map is first needed
 * in a request; it never leaves that class (ADR-011 Rule 3, modelled on
 * CascadeRun).
 *
 * @spec openspec/specs/event-driven-architecture/spec.md
 */
final class ObjectEventSlugMapRun {

	/**
	 * True when resolving schema slugs, false for register slugs.
	 *
	 * @var boolean
	 */
	public bool $isSchema = false;

	/**
	 * hrtime at which resolution started.
	 *
	 * @var float
	 */
	public float $mapStart = 0.0;

	/**
	 * The declared slugs; sorted once the cache key is built.
	 *
	 * @var array<int, string>
	 */
	public array $tokens = [];

	/**
	 * The local cache holding resolved maps; set before the cache lookup.
	 *
	 * @var ICache|null
	 */
	public ?ICache $cache = null;

	/**
	 * The map's key in the local cache; set before the cache lookup.
	 *
	 * @var string
	 */
	public string $cacheKey = '';
}//end class
