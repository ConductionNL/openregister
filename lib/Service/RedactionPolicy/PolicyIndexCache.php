<?php

/**
 * PolicyIndexCache: the policy index, read from its source once until invalidated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\RedactionPolicy
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

namespace OCA\OpenRegister\Service\RedactionPolicy;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Holds the policy index so a run reads the term rules once, not once per
 * detected entity. A change to a termRule object invalidates it.
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */
class PolicyIndexCache {

	/**
	 * The index, once built.
	 *
	 * @var PolicyIndex|null
	 */
	private ?PolicyIndex $index = null;

	/**
	 * Constructor.
	 *
	 * @param TermRuleSourceInterface $source The termRule objects.
	 * @param ClockInterface          $clock  The moment the time bounds are read against.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function __construct(
		private readonly TermRuleSourceInterface $source,
		private readonly ClockInterface $clock,
	) {
	}//end __construct()

	/**
	 * The index, read from the source on first use.
	 *
	 * @return PolicyIndex
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PolicyIndex::fromObjects() is the index's named constructor.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function index(): PolicyIndex {
		if ($this->index === null) {
			$this->index = PolicyIndex::fromObjects(
				objects: $this->source->termRuleObjects(),
				now: DateTimeImmutable::createFromInterface($this->clock->now())
			);
		}

		return $this->index;
	}//end index()

	/**
	 * Drop the index, so the next use reads the source again.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
	 */
	public function invalidate(): void {
		$this->index = null;
	}//end invalidate()
}//end class
