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
 * The register/schema pair, the caller's overrides, the effective rules once
 * resolved, and the objects compared with their payloads keyed by uuid.
 */
final class DedupRun {
	/**
	 * Object payloads keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public readonly array $payloads;

	/**
	 * Constructor.
	 *
	 * @param mixed                            $register     Register id, uuid or slug.
	 * @param mixed                            $schema       Schema id, uuid or slug.
	 * @param array<int, mixed>|null           $matchRules   Caller-supplied rules, or null for the annotation's.
	 * @param float|null                       $threshold    Caller-supplied cut-off, or null for the annotation's.
	 * @param array<int, array<string, mixed>> $rules        Effective, well-formed match rules.
	 * @param array<int, string>               $blockingKeys Field names (plain or dotted) to block on.
	 * @param float                            $cutOff       Score a pair must reach.
	 * @param array<int, ObjectEntity>         $objects      The objects compared.
	 */
	public function __construct(
		public readonly mixed $register,
		public readonly mixed $schema,
		public readonly ?array $matchRules=null,
		public readonly ?float $threshold=null,
		public readonly array $rules=[],
		public readonly array $blockingKeys=[],
		public readonly float $cutOff=0.0,
		public readonly array $objects=[],
	) {
		$payloads = [];
		foreach ($objects as $object) {
			$payloads[(string) $object->getUuid()] = ($object->getObject() ?? []);
		}

		$this->payloads = $payloads;
	}//end __construct()

	/**
	 * The same run over other objects.
	 *
	 * @param array<int, ObjectEntity> $objects The objects compared.
	 *
	 * @return self
	 */
	public function withObjects(array $objects): self {
		return new self(
			register: $this->register,
			schema: $this->schema,
			matchRules: $this->matchRules,
			threshold: $this->threshold,
			rules: $this->rules,
			blockingKeys: $this->blockingKeys,
			cutOff: $this->cutOff,
			objects: $objects
		);
	}//end withObjects()
}//end class
