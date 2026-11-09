<?php

/**
 * OpenRegister RuleReplayService
 *
 * Applies one declared rule to objects that already exist, as a previewed
 * background job, under the ceiling the rule declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rules
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rules;

use OCA\OpenRegister\BulkAction\ApplyRuleActionInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\BulkJob\BulkJobService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Throwable;

/**
 * The replay, which is the dry run with a selection and a transport.
 *
 * WHY THE COUNT COMES FIRST AND FROM A COUNT QUERY. D-4 requires the run to be
 * refused as a whole, naming the number it would have touched. The bulk
 * engine's own selection resolver stops at the instance ceiling plus one,
 * because that is all it needs to know to refuse; it therefore cannot say
 * "4,000". A refusal reading "more than 1,001" tells an administrator nothing
 * about whether the filter is wrong by a little or by three orders of
 * magnitude, which is the whole reason the number is in the requirement.
 *
 * WHY IT DOES NOT RUN THE RULE ITSELF. The per-object work is
 * {@see ApplyRuleAction}, registered with the bulk engine, so the replay
 * inherits the preview, the per-object outcome, the audit and the batching
 * that `bulk-action-jobs` already owns. Writing a second executor here is how
 * the preview and the run drift apart.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
final class RuleReplayService implements RuleReplayServiceInterface {

	/**
	 * The refusal code for a rule the schema does not declare.
	 *
	 * @var string
	 */
	public const CODE_UNKNOWN_RULE = 'rule-replay-unknown-rule';

	/**
	 * The refusal code for a kind that cannot be replayed at all.
	 *
	 * @var string
	 */
	public const CODE_NOT_REPLAYABLE = 'rule-replay-not-replayable';

	/**
	 * The refusal code for a selection that names neither ids nor a query.
	 *
	 * @var string
	 */
	public const CODE_NO_SELECTION = 'rule-replay-no-selection';

	/**
	 * The query keys a count drops: paging, and the shaping a count does not read.
	 *
	 * @var array<int, string>
	 */
	private const PAGING_KEYS = ['_limit', '_offset', '_page', '_count', '_extend', '_fields', '_unset'];

	/**
	 * Constructor.
	 *
	 * @param RuleInventoryService $inventory Finds the rule as declared.
	 * @param RuleCeilingService $ceiling Refuses a run above the rule's ceiling.
	 * @param BulkJobService $jobs Creates and previews the background job.
	 * @param ObjectOperationsInterface $objects Counts the selection before anything is written.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RuleInventoryService $inventory,
		private readonly RuleCeilingService $ceiling,
		private readonly BulkJobService $jobs,
		private readonly ObjectOperationsInterface $objects,
	) {
	}//end __construct()

	/**
	 * Preview a replay of one rule over a selection of existing objects.
	 *
	 * Nothing is written by this call. It creates the job in the previewed
	 * state with a per-object outcome, which the operator then commits through
	 * the bulk job surface, or does not.
	 *
	 * @param Schema $schema The schema the rule is declared on.
	 * @param string $ruleId The derived rule id.
	 * @param array<string, mixed> $selection Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param string $actorUid The actor's uid.
	 * @param string|null $justification The reason the operator typed.
	 * @param int|null $registerId The register the selection lives in.
	 *
	 * @return array<string, mixed> The previewed job, or the refusal.
	 *
	 * @throws RuleCeilingException When the selection is larger than the rule's ceiling.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function preview(Schema $schema, string $ruleId, array $selection, string $actorUid,
		?string $justification = null, ?int $registerId = null): array {
		$rule = $this->inventory->find(schema: $schema, ruleId: $ruleId);
		if ($rule === null || $rule->getKind() !== RuleVocabulary::KIND_CALCULATION) {
			return $this->ruleRefusal(rule: $rule, schema: $schema, ruleId: $ruleId);
		}

		return $this->previewJob(
			rule: $rule,
			schema: $schema,
			selection: $selection,
			by: ['actorUid' => $actorUid, 'justification' => $justification, 'registerId' => $registerId]
		);
	}//end preview()

	/**
	 * The refusal for a rule that is not declared, or that derives nothing to replay.
	 *
	 * @param RuleDescriptor|null $rule   The rule as declared, or null when the schema declares none.
	 * @param Schema              $schema The schema the rule was looked up on.
	 * @param string              $ruleId The derived rule id asked for.
	 *
	 * @return array<string, mixed> The refusal.
	 */
	private function ruleRefusal(?RuleDescriptor $rule, Schema $schema, string $ruleId): array {
		if ($rule === null) {
			return $this->refusal(
				code: self::CODE_UNKNOWN_RULE,
				message: sprintf('Schema "%s" declares no rule "%s".', (string)($schema->getSlug() ?? ''), $ruleId)
			);
		}

		return $this->refusal(
			code: self::CODE_NOT_REPLAYABLE,
			message: sprintf('A rule of kind "%s" derives no value for a stored object, so there is nothing to replay.', $rule->getKind())
		);
	}//end ruleRefusal()

	/**
	 * Count the selection, hold it to the ceiling, and create the previewed job.
	 *
	 * @param RuleDescriptor $rule The calculation to replay.
	 * @param Schema $schema The schema the rule is declared on.
	 * @param array<string, mixed> $selection Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param array{actorUid: string, justification: string|null, registerId: int|null} $by Who asks, why, and where.
	 *
	 * @return array<string, mixed> The previewed job, or the refusal.
	 *
	 * @throws RuleCeilingException When the selection is larger than the rule's ceiling.
	 */
	private function previewJob(RuleDescriptor $rule, Schema $schema, array $selection, array $by): array {
		$count = $this->countOf(selection: $selection, scope: ['register' => $by['registerId'], 'schema' => $schema->getId()]);
		if ($count === null) {
			return $this->refusal(code: self::CODE_NO_SELECTION, message: 'A replay needs a selection: either "ids" or a "query".');
		}

		// 🔴 BEFORE THE FIRST WRITE, AND BEFORE THE JOB EXISTS. D-4: a ceiling
		// that stops half way leaves a partial mutation. Nothing below this line
		// runs when the ceiling refuses, including the preview rows.
		$this->ceiling->assert(rule: $rule, count: $count);
		$job = $this->createJob(rule: $rule, schema: $schema, selection: $selection, by: $by);

		return ['ok' => true, 'committed' => false, 'ruleId' => $rule->getId(), 'maxObjects' => $rule->getMaxObjects()]
			+ ['count' => $count, 'job' => $job->jsonSerialize()];
	}//end previewJob()

	/**
	 * Create the background job in its previewed state.
	 *
	 * @param RuleDescriptor $rule The calculation to replay.
	 * @param Schema $schema The schema the rule is declared on.
	 * @param array<string, mixed> $selection The selection.
	 * @param array{actorUid: string, justification: string|null, registerId: int|null} $by Who asks, why, and where.
	 *
	 * @return BulkJob The job.
	 */
	private function createJob(RuleDescriptor $rule, Schema $schema, array $selection, array $by): BulkJob {
		return $this->jobs->create(
			actionId: ApplyRuleActionInterface::ID,
			parameters: ['schema' => (string)($schema->getSlug() ?? (string)$schema->getId()), 'ruleId' => $rule->getId()],
			selection: $selection,
			justification: $by['justification'],
			actorUid: $by['actorUid'],
			registerId: $by['registerId'],
			schemaId: $schema->getId()
		);
	}//end createJob()

	/**
	 * How many objects the selection stands for, counted not sampled.
	 *
	 * @param array<string, mixed> $selection The selection.
	 * @param array{register: int|null, schema: int|null} $scope The register and schema the selection lives in.
	 *
	 * @return int|null The count, or null when the selection names nothing.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function countOf(array $selection, array $scope): ?int {
		$ids = ($selection['ids'] ?? null);
		if (is_array($ids) === true) {
			return count(array_unique(array_map('strval', $ids)));
		}

		$query = ($selection['query'] ?? null);
		if (is_array($query) === false) {
			return null;
		}

		return $this->countQuery(query: $this->scoped(query: $query, scope: $scope));
	}//end countOf()

	/**
	 * Count a scoped query.
	 *
	 * @param array<string, mixed> $query The scoped query.
	 *
	 * @return int|null The count, or null when it could not be taken.
	 */
	private function countQuery(array $query): ?int {
		try {
			return $this->objects->countSearchObjects(query: $query);
		} catch (Throwable $failure) {
			// A count that cannot be taken is not a count of zero. Answering
			// null refuses the replay for want of a selection rather than
			// letting it run under a ceiling nothing was compared to.
			return null;
		}
	}//end countQuery()

	/**
	 * The caller's query, scoped to the register and the schema.
	 *
	 * The paging keys are dropped: a ceiling compared against a page is a
	 * ceiling that passes whatever the page size happens to be.
	 *
	 * @param array<string, mixed> $query The caller's query.
	 * @param array{register: int|null, schema: int|null} $scope The register and schema the selection lives in.
	 *
	 * @return array<string, mixed> The scoped query.
	 */
	private function scoped(array $query, array $scope): array {
		$self = $this->selfOf(query: $query, scope: $scope);
		$scopedQuery = array_diff_key($query, array_flip(self::PAGING_KEYS));
		if ($self !== []) {
			$scopedQuery['@self'] = $self;
		}

		return $scopedQuery;
	}//end scoped()

	/**
	 * The query's `@self` block with the register and schema written onto it.
	 *
	 * @param array<string, mixed> $query The caller's query.
	 * @param array{register: int|null, schema: int|null} $scope The register and schema the selection lives in.
	 *
	 * @return array<string, mixed> The block; empty when the query carries none and no scope is known.
	 */
	private function selfOf(array $query, array $scope): array {
		$self = ($query['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		return array_replace($self, array_filter($scope, static fn (?int $id): bool => $id !== null));
	}//end selfOf()

	/**
	 * Shape one refusal.
	 *
	 * @param string $code The refusal's code.
	 * @param string $message The sentence naming what was refused.
	 *
	 * @return array<string, mixed> The refusal.
	 */
	private function refusal(string $code, string $message): array {
		return ['ok' => false, 'committed' => false, 'error' => ['code' => $code, 'message' => $message]];
	}//end refusal()
}//end class
