<?php

/**
 * One duplicate detection run: what was asked, how it compares and what it compares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Search\Service\Quality
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Quality;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The register/schema pair and the caller's overrides, the effective rules
 * once resolved, and the objects compared with their payloads keyed by uuid.
 */
final class DedupRun {
	/**
	 * The effective rules; empty until resolved.
	 *
	 * @var DedupConfig
	 */
	private DedupConfig $config;

	/**
	 * The objects compared.
	 *
	 * @var array<int, ObjectEntity>
	 */
	private array $objects = [];

	/**
	 * Object payloads keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $payloads = [];

	/**
	 * Constructor.
	 *
	 * @param mixed                  $register   Register id, uuid or slug.
	 * @param mixed                  $schema     Schema id, uuid or slug.
	 * @param array<int, mixed>|null $matchRules Caller-supplied rules, or null for the annotation's.
	 * @param float|null             $threshold  Caller-supplied cut-off, or null for the annotation's.
	 */
	public function __construct(
		public readonly mixed $register,
		public readonly mixed $schema,
		public readonly ?array $matchRules=null,
		public readonly ?float $threshold=null,
	) {
		$this->config = new DedupConfig(rules: [], blockingKeys: [], cutOff: 0.0);
	}//end __construct()

	/**
	 * The same run with its effective rules.
	 *
	 * @param DedupConfig $config The effective rules, blocking keys and cut-off.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md
	 */
	public function withConfig(DedupConfig $config): self {
		$copy = clone $this;
		$copy->config = $config;

		return $copy;
	}//end withConfig()

	/**
	 * The same run over other objects, with their payloads keyed by uuid.
	 *
	 * @param array<int, ObjectEntity> $objects The objects compared.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/duplicate-detection/spec.md
	 */
	public function withObjects(array $objects): self {
		$copy = clone $this;
		$copy->objects = $objects;
		$copy->payloads = [];
		foreach ($objects as $object) {
			$copy->payloads[(string) $object->getUuid()] = ($object->getObject() ?? []);
		}

		return $copy;
	}//end withObjects()

	/**
	 * The effective, well-formed match rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getRules(): array {
		return $this->config->rules;
	}//end getRules()

	/**
	 * The field names (plain or dotted) to block on.
	 *
	 * @return array<int, string>
	 */
	public function getBlockingKeys(): array {
		return $this->config->blockingKeys;
	}//end getBlockingKeys()

	/**
	 * The score a pair must reach.
	 *
	 * @return float
	 */
	public function getCutOff(): float {
		return $this->config->cutOff;
	}//end getCutOff()

	/**
	 * The objects compared.
	 *
	 * @return array<int, ObjectEntity>
	 */
	public function getObjects(): array {
		return $this->objects;
	}//end getObjects()

	/**
	 * The object payloads keyed by uuid.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function getPayloads(): array {
		return $this->payloads;
	}//end getPayloads()
}//end class
