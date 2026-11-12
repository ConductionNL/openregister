<?php

/**
 * BackendMethodsInterface: the entity-recognition method values callers outside the component compare against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
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

namespace OCA\OpenRegister\Service\Anonymisation;

/**
 * The entity-recognition method values.
 *
 * Declared on an interface so the anonymisation settings endpoints and the
 * entity recognition handler can name the values without naming BackendState.
 * BackendState implements it, so BackendState::METHOD_* still resolves.
 */
interface BackendMethodsInterface {

	/**
	 * Regular-expression based recognition (always available fallback).
	 */
	public const METHOD_REGEX = 'regex';

	/**
	 * Microsoft Presidio HTTP backend.
	 */
	public const METHOD_PRESIDIO = 'presidio';

	/**
	 * OpenAnonymiser ExApp backend.
	 */
	public const METHOD_OPENANONYMISER = 'openanonymiser';

	/**
	 * LLM-based recognition backend.
	 */
	public const METHOD_LLM = 'llm';

	/**
	 * Hybrid backend composing regex + presidio + openanonymiser.
	 */
	public const METHOD_HYBRID = 'hybrid';

	/**
	 * Sentinel stored value meaning "no explicit operator choice yet".
	 *
	 * Resolved to a concrete method at state-query time (auto-select on first run).
	 */
	public const METHOD_AUTO = 'auto';

	/**
	 * The valid concrete method enum values.
	 *
	 * @var string[]
	 */
	public const METHODS = [
		self::METHOD_REGEX,
		self::METHOD_PRESIDIO,
		self::METHOD_OPENANONYMISER,
		self::METHOD_LLM,
		self::METHOD_HYBRID,
	];
}//end interface
