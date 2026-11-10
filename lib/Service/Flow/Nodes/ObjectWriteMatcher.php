<?php

/**
 * Resolves the configured match of an object-write step to one object.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\IL10N;
use RuntimeException;

/**
 * Finds the object a write, or a delete, lands on.
 */
final class ObjectWriteMatcher {

	/**
	 * How many candidates a match lookup reads before it stops counting.
	 *
	 * A match is required to resolve to exactly one object, so anything past a
	 * handful is already a failure; this only bounds how much a badly-authored
	 * match can pull into memory before the node says so.
	 *
	 * @var integer
	 */
	private const MATCH_SCAN_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects  Runs the lookup as the owner.
	 * @param ObjectWriteTemplate       $template Resolves each match value.
	 * @param IL10N                     $l10n     Translations.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly ObjectWriteTemplate $template,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Resolve the composite match to exactly one object, or to none.
	 *
	 * Every pair must hold — the pairs are ANDed, never ORed. An OR would widen
	 * the resolved set, and every consumer of a match treats a wider set as an
	 * error, so an OR would only ever produce configurations that cannot succeed.
	 *
	 * More than one match fails, naming the count. Picking one would be
	 * non-deterministic across instances, which is the defect class that only
	 * ever appears on someone else's system.
	 *
	 * The lookup runs AS the owner, so the object a match resolves to is one the
	 * owner can actually see. Without that the node checked one subject and
	 * chose another: the writes were attributed (`currentUser: $owner`) while
	 * the scan that decided WHICH row to write ran under the ambient session.
	 * Under CLI cron that session is empty, and both the RBAC and organisation
	 * filters skip themselves for a sessionless CLI context — so the match
	 * could resolve a row outside the owner's visibility, and `upsert` could
	 * find one the owner could not see and therefore insert a duplicate.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan: match pairs, register, schema, and the run owner whose RBAC applies to the lookup.
	 * @param array           $json The current item's record.
	 *
	 * @return ObjectEntity|null The single match, or null when nothing matched.
	 *
	 * @throws RuntimeException When a match value cannot be templated, or the match is ambiguous.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function find(ObjectWritePlan $plan, array $json): ?ObjectEntity {
		$config = ['filters' => $this->filters(plan: $plan, json: $json), 'limit' => self::MATCH_SCAN_LIMIT];
		$rows = $this->objects->runAs($plan->owner, fn (): array => $this->objects->findAll(config: $config));

		$entities = array_values(
			array_filter($rows, static fn (mixed $row): bool => ($row instanceof ObjectEntity) === true)
		);
		$this->refuseAmbiguous(count: count($entities));

		return ($entities[0] ?? null);
	}//end find()

	/**
	 * Build the filter set of one item's match.
	 *
	 * `onMissing` never applies here. Omitting a field narrows what is written;
	 * omitting a match pair WIDENS what is matched, and a guard that gets weaker
	 * when its input is missing is not a guard.
	 *
	 * @param ObjectWritePlan $plan The step's resolved plan.
	 * @param array           $json The current item's record.
	 *
	 * @return array The filters.
	 *
	 * @throws RuntimeException When a match value cannot be resolved, or names nothing matchable.
	 */
	private function filters(ObjectWritePlan $plan, array $json): array {
		$filters = new ObjectWriteMatchFilters(plan: $plan, l10n: $this->l10n);
		foreach ($plan->pairs as $pair) {
			$resolved = $this->template->resolve(value: $pair['value'], json: $json);
			if ($resolved['resolved'] === false) {
				throw new RuntimeException(
					$this->l10n->t('The match value for "%s" could not be resolved from the item; a match key is never omitted.', [(string)$pair['property']])
				);
			}

			$filters->add(property: (string)$pair['property'], value: $resolved['value']);
		}

		return $filters->all;
	}//end filters()

	/**
	 * Refuse a match that resolved to more than one object.
	 *
	 * @param int $count The objects found.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When there is more than one.
	 */
	private function refuseAmbiguous(int $count): void {
		if ($count <= 1) {
			return;
		}

		$label = (string)$count;
		if ($count >= self::MATCH_SCAN_LIMIT) {
			$label = $this->l10n->t('%s or more', [(string)$count]);
		}

		throw new RuntimeException(
			$this->l10n->t('The match resolved to %s objects; it must resolve to exactly one.', [$label])
		);
	}//end refuseAmbiguous()
}//end class
