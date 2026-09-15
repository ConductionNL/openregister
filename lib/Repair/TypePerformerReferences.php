<?php

/**
 * Give every stored performer string a type — but only where it is certain.
 *
 * A stored `"bezwaar"` means a user OR a group, because the guard it was
 * written against tried both. This step resolves each string once and rewrites
 * it as `{type, id}` where exactly one kind of thing answers to that name.
 *
 * 🔴 THREE OUTCOMES, AND ONLY ONE OF THEM MAY BE ACTED ON.
 *
 *   resolves under exactly one type → rewrite; the string meant that
 *   resolves under more than one    → LEAVE IT, and report
 *   resolves under no type          → LEAVE IT, and report
 *
 * The ambiguous case is not hypothetical. An instance may hold a user AND a
 * group of one name, and today's guard accepts BOTH — so today's behaviour is
 * genuinely the union, and any rewrite NARROWS it. Narrowing who may answer an
 * approval is a decision for a person, not for a repair step running unattended
 * during an upgrade.
 *
 * 🔴 IT MUST NOT FAIL AN UPGRADE. On the measured instance 27 stored strings
 * resolve to nothing at all. Those flows were broken before this step existed,
 * and a repair that turns pre-existing breakage into a failed `occ upgrade`
 * makes it everybody's problem instead of their owner's. Every unresolvable
 * string is reported by name and left exactly as it is.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapper;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalReference;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites unambiguous performer strings as typed references.
 */
class TypePerformerReferences implements IRepairStep {

	/**
	 * The performer fields, and the type a bare entry in each already means.
	 *
	 * 🔑 THE FIELD NAME IS EVIDENCE. `candidateGroups` holds group names and
	 * always did, so its entries are not ambiguous at all: they need no
	 * resolution and no guess. Only the fields whose name says nothing about
	 * the kind are resolved.
	 *
	 * @var array<string, string|null>
	 */
	private const FIELDS = [
		'assignee' => null,
		'routingFallback' => null,
		'candidateUsers' => 'user',
		'candidateGroups' => 'group',
		'candidateRole' => 'group',
	];

	/**
	 * Constructor.
	 *
	 * Resolved lazily from the container, like the other flow repair steps:
	 * this runs during install and upgrade, when the flow tables may not exist
	 * yet, and a constructor-injected mapper would make that a fatal rather
	 * than a skip.
	 *
	 * @param ContainerInterface $container The app container.
	 * @param LoggerInterface    $logger    Diagnostics.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function getName(): string {
		return 'Give stored flow performers an explicit type';
	}//end getName()

	/**
	 * Rewrite what is certain; report what is not.
	 *
	 * @param IOutput $output Migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	public function run(IOutput $output): void {
		try {
			$flows = $this->container->get(FlowMapper::class);
			$principals = $this->container->get(PrincipalResolverRegistry::class);
		} catch (Throwable $e) {
			// The tables may not exist yet on a fresh install. Nothing to
			// rewrite is not a failure.
			$output->info('Flow performer typing skipped: ' . $e->getMessage());
			return;
		}

		$rewritten = 0;
		$ambiguous = [];
		$unresolvable = [];

		foreach ($this->everyFlow(flows: $flows) as $flow) {
			try {
				$rewritten += $this->typeOneFlow(
					flow: $flow,
					flows: $flows,
					principals: $principals,
					ambiguous: $ambiguous,
					unresolvable: $unresolvable
				);
			} catch (Throwable $e) {
				// One unreadable flow must not stop the rest, and must not
				// fail the upgrade.
				$this->logger->warning(
					message: '[TypePerformerReferences] Could not type flow "'
						. (string)$flow->getUuid() . '": ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}
		}

		$output->info(sprintf('Typed %d stored performer(s).', $rewritten));
		$this->report(output: $output, ambiguous: $ambiguous, unresolvable: $unresolvable);

	}//end run()

	/**
	 * Say what was left alone, and why.
	 *
	 * 🔑 EACH ONE BY NAME, WITH ITS REASON. "3 could not be migrated" tells
	 * nobody which flow to open, and a report nobody can act on is the same as
	 * no report.
	 *
	 * @param IOutput            $output       Migration output.
	 * @param array<int, string> $ambiguous    Strings naming more than one kind.
	 * @param array<int, string> $unresolvable Strings naming nothing at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function report(IOutput $output, array $ambiguous, array $unresolvable): void {
		foreach ($ambiguous as $line) {
			$output->warning(
				'AMBIGUOUS, left as it is: ' . $line
					. '. Today both may answer, so choosing one would silently narrow who can. Set the type by hand.'
			);
		}

		foreach ($unresolvable as $line) {
			$output->warning(
				'UNRESOLVABLE, left as it is: ' . $line
					. '. Nothing on this instance answers to that name; the flow was already broken before this step ran.'
			);
		}

	}//end report()

	/**
	 * Every flow, paged.
	 *
	 * 🔴 `findAllFlows()` DEFAULTS TO 100. A single call would silently type
	 * the first hundred flows and leave the rest, reporting success.
	 *
	 * @param FlowMapper $flows The mapper.
	 *
	 * @return array<int, Flow> Every flow.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function everyFlow(FlowMapper $flows): array {
		$page = 500;
		$offset = 0;
		$all = [];

		while (true) {
			$batch = $flows->findAllFlows(limit: $page, offset: $offset);
			$all = array_merge($all, $batch);

			if (count($batch) < $page) {
				return $all;
			}

			$offset += $page;
		}

	}//end everyFlow()

	/**
	 * Type one flow's performer strings, in place.
	 *
	 * @param Flow                      $flow         The flow.
	 * @param FlowMapper                $flows        The mapper, to store the result.
	 * @param PrincipalResolverRegistry $principals   Resolves a candidate type.
	 * @param array<int, string>        $ambiguous    Collects what named more than one kind.
	 * @param array<int, string>        $unresolvable Collects what named nothing.
	 *
	 * @return int How many strings were rewritten.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function typeOneFlow(
		Flow $flow,
		FlowMapper $flows,
		PrincipalResolverRegistry $principals,
		array &$ambiguous,
		array &$unresolvable
	): int {
		$nodes = ($flow->getNodes() ?? []);
		if (is_array($nodes) === false || $nodes === []) {
			return 0;
		}

		$rewritten = 0;
		$changed = false;

		foreach ($nodes as $index => $node) {
			if (is_array($node) === false || is_array(($node['config'] ?? null)) === false) {
				continue;
			}

			foreach (self::FIELDS as $field => $impliedType) {
				$value = ($node['config'][$field] ?? null);
				$typed = $this->typeValue(
					value: $value,
					impliedType: $impliedType,
					principals: $principals,
					where: (string)$flow->getUuid() . ' step "' . (string)($node['id'] ?? '?') . '" field "' . $field . '"',
					ambiguous: $ambiguous,
					unresolvable: $unresolvable,
					rewritten: $rewritten
				);

				if ($typed !== null) {
					$nodes[$index]['config'][$field] = $typed;
					$changed = true;
				}
			}
		}

		if ($changed === true) {
			$flow->setNodes($nodes);
			$flows->update($flow);
		}

		return $rewritten;

	}//end typeOneFlow()

	/**
	 * The typed replacement for one stored value, or null to leave it alone.
	 *
	 * @param mixed                     $value        What is stored.
	 * @param string|null               $impliedType  The type the field name already implies.
	 * @param PrincipalResolverRegistry $principals   Resolves a candidate type.
	 * @param string                    $where        Where it was found, for the report.
	 * @param array<int, string>        $ambiguous    Collects what named more than one kind.
	 * @param array<int, string>        $unresolvable Collects what named nothing.
	 * @param int                       $rewritten    Counts the rewrites.
	 *
	 * @return mixed The replacement, or null when nothing should change.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `PrincipalReference::listFrom()` is
	 * a named constructor on a value object, which this rule cannot tell apart
	 * from a static call into a service.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function typeValue(
		mixed $value,
		?string $impliedType,
		PrincipalResolverRegistry $principals,
		string $where,
		array &$ambiguous,
		array &$unresolvable,
		int &$rewritten
	): mixed {
		$entries = $this->entriesOf(value: $value);
		if ($entries === null) {
			return null;
		}

		$isList = $this->isList(value: $value);
		$out = [];
		$touched = false;

		foreach ($entries as $entry) {
			$typed = $this->typeOneEntry(
				entry: $entry,
				impliedType: $impliedType,
				principals: $principals,
				where: $where,
				ambiguous: $ambiguous,
				unresolvable: $unresolvable
			);

			if ($typed === null) {
				// An empty string is not a performer and is not kept.
				continue;
			}

			if ($typed !== $entry) {
				$touched = true;
				$rewritten++;
			}

			$out[] = $typed;
		}

		if ($touched === false) {
			return null;
		}

		if ($isList === true) {
			return $out;
		}

		return ($out[0] ?? null);

	}//end typeValue()

	/**
	 * The entries a stored value holds, or null when it holds none.
	 *
	 * @param mixed $value What is stored.
	 *
	 * @return array<int, mixed>|null The entries, or null when there is nothing to type.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function entriesOf(mixed $value): ?array {
		if ($value === null || $value === '' || $value === []) {
			return null;
		}

		if ($this->isList(value: $value) === true) {
			return $value;
		}

		return [$value];

	}//end entriesOf()

	/**
	 * Whether a stored value is a LIST of performers rather than one of them.
	 *
	 * 🔑 A `{type, id}` MAP IS ITSELF AN ARRAY, so a list is only a list when
	 * its keys are sequential. Reading the map as a list would type its two
	 * VALUES as two separate performers.
	 *
	 * @param mixed $value What is stored.
	 *
	 * @return bool Whether it is a list.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function isList(mixed $value): bool {
		return (is_array($value) === true && array_is_list($value) === true);

	}//end isList()

	/**
	 * The typed form of ONE stored entry, or the entry unchanged.
	 *
	 * 🔴 RETURNING THE ENTRY UNCHANGED IS THE "LEAVE IT ALONE" ANSWER, and it
	 * is deliberately not null: null means "this was never a performer", and
	 * the two must not be confused. An unresolvable string is a performer
	 * somebody wrote down; it stays exactly as it is, and the guard keeps
	 * reading it under its old, wider meaning, so nothing regresses.
	 *
	 * @param mixed              $entry        One stored entry.
	 * @param string|null        $impliedType  The type the field name implies.
	 * @param PrincipalResolverRegistry $principals Resolves a candidate type.
	 * @param string             $where        Where it was found, for the report.
	 * @param array<int, string> $ambiguous    Collects what named more than one kind.
	 * @param array<int, string> $unresolvable Collects what named nothing.
	 *
	 * @return mixed The typed entry, the entry unchanged, or null when it is not one.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function typeOneEntry(
		mixed $entry,
		?string $impliedType,
		PrincipalResolverRegistry $principals,
		string $where,
		array &$ambiguous,
		array &$unresolvable
	): mixed {
		// Already typed: nothing to decide.
		if (is_array($entry) === true) {
			return $entry;
		}

		$name = trim((string)$entry);
		if ($name === '') {
			return null;
		}

		// 🔴 A TEMPLATE IS NOT A NAME. `{{ case.assignee }}` is resolved per
		// item when the step runs, so there is nothing here to look up and
		// nothing broken about it. Found on the dev instance, where the first
		// draft reported it as "already broken" — a false alarm that would
		// have sent somebody looking for a flow that is working correctly.
		if (str_contains($name, '{{') === true) {
			return $entry;
		}

		// The field name already says what kind it is, so there is nothing
		// ambiguous to resolve.
		if ($impliedType !== null) {
			return ['type' => $impliedType, 'id' => $name];
		}

		$kinds = $this->kindsAnsweringTo(name: $name, principals: $principals);
		if (count($kinds) === 1) {
			return ['type' => $kinds[0], 'id' => $name];
		}

		if ($kinds === []) {
			$unresolvable[] = '"' . $name . '" at ' . $where;

			return $entry;
		}

		$ambiguous[] = '"' . $name . '" at ' . $where . ' names a ' . implode(' and a ', $kinds);

		return $entry;

	}//end typeOneEntry()

	/**
	 * Which registered kinds answer to this name, right now.
	 *
	 * @param string                    $name       The stored string.
	 * @param PrincipalResolverRegistry $principals The registry.
	 *
	 * @return array<int, string> The types that resolve it, in a stable order.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) See typeValue().
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md#requirement-stored-assignee-strings-are-migrated-once-and-what-cannot-be-migrated-is-reported
	 */
	private function kindsAnsweringTo(string $name, PrincipalResolverRegistry $principals): array {
		$kinds = [];
		foreach ($principals->types() as $type) {
			$reference = PrincipalReference::from(value: ['type' => $type, 'id' => $name]);
			if ($reference === null) {
				continue;
			}

			if ($principals->resolve(reference: $reference) !== []) {
				$kinds[] = $type;
			}
		}

		return $kinds;

	}//end kindsAnsweringTo()
}//end class
