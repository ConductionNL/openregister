<?php

/**
 * OpenRegister Anonymisation Backend State
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  ValueObject
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use JsonSerializable;

/**
 * Fully-resolved snapshot of the anonymisation backend selection state.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\Service\Anonymisation
 */
final class BackendState implements JsonSerializable {
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

	/**
	 * Constructor.
	 *
	 * @param bool $entityRecognitionEnabled Whether recognition is enabled at all.
	 * @param string $activeMethod The operator-selected (resolved) method.
	 * @param string $effectiveMethod The method that will actually be used.
	 * @param array<string, BackendInfo> $backends Per-method availability records.
	 */
	public function __construct(
		public readonly bool $entityRecognitionEnabled,
		public readonly string $activeMethod,
		public readonly string $effectiveMethod,
		public readonly array $backends,
	) {
	}//end __construct()

	/**
	 * Serialise to a JSON-friendly array.
	 *
	 * @return array{entityRecognitionEnabled: bool, activeMethod: string, effectiveMethod: string, backends: array<string, mixed>}
	 */
	public function jsonSerialize(): array {
		$backends = [];
		foreach ($this->backends as $name => $info) {
			$backends[$name] = $info->jsonSerialize();
		}

		return [
			'entityRecognitionEnabled' => $this->entityRecognitionEnabled,
			'activeMethod' => $this->activeMethod,
			'effectiveMethod' => $this->effectiveMethod,
			'backends' => $backends,
		];
	}//end jsonSerialize()
}//end class
