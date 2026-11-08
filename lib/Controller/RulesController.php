<?php

/**
 * OpenRegister RulesController
 *
 * The operator's half of the rules engine: what will run on a schema, why it
 * did or did not fire, what one rule would do, and the switch that turns it
 * off.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\RulesControllerServices;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskDates;
use OCA\OpenRegister\Db\RuleRunMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rules\RuleEnablementServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleCeilingException;
use OCA\OpenRegister\Service\Rules\RuleInventoryServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleReplayServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleTrialServiceInterface;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCA\OpenRegister\Settings\OpenRegisterAdmin;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Four reads and one switch over the rules a schema declares.
 *
 * WHY THESE ARE ADMIN METHODS. The inventory exposes every rule that acts on a
 * schema's objects, including the conditions that decide who may move a case on
 * and the operand values that decided a refusal. That is the configuration of
 * the instance's own gates, read in one place, and it belongs to whoever
 * administers the instance. The vocabulary alone is a static table with nothing
 * per-instance in it and is open to any signed-in caller, the way the operator
 * catalogue beside it already is.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The controller composes the inventory,
 *   the run log, the trial, the replay and the switch, which are the surfaces this change
 *   exists to add.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class RulesController extends Controller {

	/**
	 * Resolves the schema a rule is declared on.
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemaMapper;

	/**
	 * Derives the rule list.
	 *
	 * @var RuleInventoryServiceInterface
	 */
	private readonly RuleInventoryServiceInterface $inventory;

	/**
	 * Reads the run log.
	 *
	 * @var RuleRunMapperInterface
	 */
	private readonly RuleRunMapperInterface $runs;

	/**
	 * Evaluates a rule without committing.
	 *
	 * @var RuleTrialServiceInterface
	 */
	private readonly RuleTrialServiceInterface $trials;

	/**
	 * Switches a rule off and on.
	 *
	 * @var RuleEnablementServiceInterface
	 */
	private readonly RuleEnablementServiceInterface $enablement;

	/**
	 * The published kinds, verdicts and actions.
	 *
	 * @var RuleVocabulary
	 */
	private readonly RuleVocabulary $vocabulary;

	/**
	 * Previews a replay over existing objects.
	 *
	 * @var RuleReplayServiceInterface
	 */
	private readonly RuleReplayServiceInterface $replays;

	/**
	 * Names the actor a replay runs as.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param RulesControllerServices $services The rules controller services.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		RulesControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->schemaMapper = $services->mappers->schemaMapper;
		$this->inventory = $services->domain->inventory;
		$this->runs = $services->mappers->runs;
		$this->trials = $services->domain->trials;
		$this->enablement = $services->domain->enablement;
		$this->vocabulary = $services->domain->vocabulary;
		$this->replays = $services->replay->replays;
		$this->userSession = $services->platform->userSession;

	}//end __construct()

	/**
	 * The vocabulary the rules engine uses about itself.
	 *
	 * A static table: the kinds of rule, the verdicts an evaluation can reach
	 * and the actions a rule can take. No schema, no object and no instance
	 * configuration is read, so any signed-in caller may generate a rule
	 * surface from it.
	 *
	 * @return JSONResponse The three closed sets.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @contract tests/e2e/ci/rules-engine-operability.spec.ts
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function vocabulary(): JSONResponse {
		return new JSONResponse($this->vocabulary->all());

	}//end vocabulary()

	/**
	 * Every rule that can act on a schema's objects, in evaluation order.
	 *
	 * @param string $schema Schema id, uuid or slug.
	 *
	 * @return JSONResponse The inventory rows, narrowed by `scope[<property>]=<value>` when given.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function index(string $schema): JSONResponse {
		$entity = $this->resolveSchema(reference: $schema);
		if ($entity === null) {
			return $this->notFound(reference: $schema);
		}

		$window = (int)($this->request->getParam('idleDays') ?? RuleInventoryServiceInterface::DEFAULT_IDLE_DAYS);
		$scope = $this->paramOf(name: 'scope', accepts: 'is_array', fallback: []);

		return new JSONResponse(
			[
				'schema' => (string)($entity->getSlug() ?? ''),
				'rules' => $this->inventory->inventory(schema: $entity, idleDays: $window, scope: $scope),
			]
		);

	}//end index()

	/**
	 * Switch one rule of a schema off or on.
	 *
	 * @param string $schema Schema id, uuid or slug.
	 * @param string $ruleId The derived rule id.
	 *
	 * @return JSONResponse The rule as it now stands, with the audit entry.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function setEnabled(string $schema, string $ruleId): JSONResponse {
		$entity = $this->resolveSchema(reference: $schema);
		if ($entity === null) {
			return $this->notFound(reference: $schema);
		}

		$enabled = $this->request->getParam('enabled');
		if (is_bool($enabled) === false) {
			return $this->refusal(code: 'rule-switch-no-state', message: 'A boolean "enabled" is required.', status: 422);
		}

		return $this->answer(result: $this->enablement->setEnabled(schema: $entity, ruleId: $ruleId, enabled: $enabled));

	}//end setEnabled()

	/**
	 * Evaluate one rule without committing, and report what it would do.
	 *
	 * Two payload shapes, the same pair the calculation dry run already uses.
	 * With `object` the rule runs against that sample payload and no stored
	 * data is touched. With `register` and `objectId` it runs against the named
	 * object, looked up through the RBAC- and tenancy-scoped mapper: a caller
	 * who may not read that object gets a not-found refusal rather than its
	 * values, which is this method's per-object authorisation guard.
	 *
	 * Nothing is written on either path, and a `rule` in the body lets an
	 * author try a declaration that has not been saved at all.
	 *
	 * @param string $schema Schema id, uuid or slug.
	 * @param string $ruleId The derived rule id.
	 *
	 * @return JSONResponse The verdict, the deciding operand and the writes it would make.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function evaluate(string $schema, string $ruleId): JSONResponse {
		$entity = $this->resolveSchema(reference: $schema);
		if ($entity === null) {
			return $this->notFound(reference: $schema);
		}

		$override = $this->paramOf(name: 'rule', accepts: 'is_array', fallback: null);

		return $this->answer(result: $this->trial(schema: $entity, ruleId: $ruleId, override: $override));

	}//end evaluate()

	/**
	 * Run the trial against the named stored object, or else against the sample payload.
	 *
	 * @param Schema $schema The schema the rule is declared on.
	 * @param string $ruleId The derived rule id.
	 * @param array<string, mixed>|null $override An unsaved declaration to try instead.
	 *
	 * @return array<string, mixed> The trial result.
	 */
	private function trial(Schema $schema, string $ruleId, ?array $override): array {
		$register = $this->request->getParam('register');
		$objectId = $this->request->getParam('objectId');
		if ($this->isFilled(value: $register) === true && $this->isFilled(value: $objectId) === true) {
			return $this->trials->tryObject(
				schema: $schema,
				ruleId: $ruleId,
				register: $register,
				objectId: $objectId,
				override: $override
			);
		}

		$sample = $this->paramOf(name: 'object', accepts: 'is_array', fallback: []);

		return $this->trials->trySample(schema: $schema, ruleId: $ruleId, sample: $sample, override: $override);
	}//end trial()

	/**
	 * Preview a replay of one rule over the objects that already exist.
	 *
	 * Writes nothing. It counts the selection, refuses the whole run when the
	 * count is above the ceiling the rule declares, and otherwise creates a
	 * previewed `bulk-action-jobs` job with a per-object outcome. The operator
	 * commits that job, or does not, through the bulk job surface: the replay
	 * is an act with an actor, not a side effect of asking about it.
	 *
	 * @param string $schema Schema id, uuid or slug.
	 * @param string $ruleId The derived rule id.
	 *
	 * @return JSONResponse The previewed job, or the refusal with the count behind it.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function replay(string $schema, string $ruleId): JSONResponse {
		$entity = $this->resolveSchema(reference: $schema);
		if ($entity === null) {
			return $this->notFound(reference: $schema);
		}

		$actor = $this->userSession->getUser();
		if ($actor === null) {
			return $this->refusal(
				code: 'rule-replay-no-actor',
				message: 'A replay is an act with an actor, and this request has none.',
				status: 422
			);
		}

		return $this->previewReplay(schema: $entity, ruleId: $ruleId, actorUid: $actor->getUID());

	}//end replay()

	/**
	 * Ask the replay service for a previewed job, mapping its refusals.
	 *
	 * @param Schema $schema The schema the rule is declared on.
	 * @param string $ruleId The derived rule id.
	 * @param string $actorUid The actor the replay runs as.
	 *
	 * @return JSONResponse The previewed job, or the refusal.
	 */
	private function previewReplay(Schema $schema, string $ruleId, string $actorUid): JSONResponse {
		$request = $this->replayRequest();

		try {
			$result = $this->askPreview(schema: $schema, ruleId: $ruleId, actorUid: $actorUid, request: $request);
		} catch (RuleCeilingException $refusal) {
			// 409, not 422. The request is well formed and the rule is sound;
			// the instance's own data is what makes the run refuse, and a
			// caller who reads 422 rewrites their request rather than their
			// filter or their ceiling.
			return new JSONResponse($refusal->toArray(), 409);
		} catch (BulkJobRefusedException $refusal) {
			return $this->bulkRefusal(refusal: $refusal);
		} catch (Throwable $failure) {
			return $this->refusal(code: 'rule-replay-failed', message: $failure->getMessage(), status: 422);
		}//end try

		return $this->answer(result: $result);
	}//end previewReplay()

	/**
	 * Ask the replay service for the preview of one replay request.
	 *
	 * @param Schema $schema The schema the rule is declared on.
	 * @param string $ruleId The derived rule id.
	 * @param string $actorUid The actor the replay runs as.
	 * @param array{selection: array<string, mixed>, justification: string|null, registerId: int|null} $request The replay request.
	 *
	 * @return array<string, mixed> The previewed job.
	 */
	private function askPreview(Schema $schema, string $ruleId, string $actorUid, array $request): array {
		return $this->replays->preview(
			schema: $schema,
			ruleId: $ruleId,
			selection: $request['selection'],
			actorUid: $actorUid,
			justification: $request['justification'],
			registerId: $request['registerId']
		);
	}//end askPreview()

	/**
	 * The selection, justification and register a replay request carries.
	 *
	 * @return array{selection: array<string, mixed>, justification: string|null, registerId: int|null} The replay request.
	 */
	private function replayRequest(): array {
		$justification = $this->paramOf(name: 'justification', accepts: 'is_string', fallback: null);

		$register = $this->request->getParam('registerId');
		$registerId = null;
		if (is_numeric($register) === true) {
			$registerId = (int)$register;
		}

		return [
			'selection' => $this->paramOf(name: 'selection', accepts: 'is_array', fallback: []),
			'justification' => $justification,
			'registerId' => $registerId,
		];
	}//end replayRequest()

	/**
	 * The 409 for a replay the bulk job surface refused.
	 *
	 * @param BulkJobRefusedException $refusal The refusal.
	 *
	 * @return JSONResponse The 409 with the refusal's reason and details.
	 */
	private function bulkRefusal(BulkJobRefusedException $refusal): JSONResponse {
		return new JSONResponse(
			[
				'ok' => false,
				'error' => array_merge(['code' => $refusal->getReason(), 'message' => $refusal->getMessage()], $refusal->getDetails()),
			],
			409
		);
	}//end bulkRefusal()

	/**
	 * One rule's run log, newest first, filtered by verdict and period.
	 *
	 * @param string $ruleId The derived rule id.
	 *
	 * @return JSONResponse The runs and how many matched.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	#[AuthorizedAdminSetting(settings: OpenRegisterAdmin::class)]
	public function runs(string $ruleId): JSONResponse {
		$verdict = $this->verdictFilter();
		if ($verdict instanceof JSONResponse) {
			return $verdict;
		}

		$since = ExampleSetTaskDates::moment(value: $this->request->getParam('since'));
		$until = ExampleSetTaskDates::moment(value: $this->request->getParam('until'));
		$limit = (int)($this->request->getParam('limit') ?? RuleRunMapperInterface::DEFAULT_LIMIT);
		$offset = (int)($this->request->getParam('offset') ?? 0);

		$runs = $this->runs->findByRule(ruleId: $ruleId, verdict: $verdict, since: $since, until: $until, limit: $limit, offset: $offset);
		$total = $this->runs->countByRule(ruleId: $ruleId, verdict: $verdict, since: $since, until: $until);

		return new JSONResponse(['ruleId' => $ruleId, 'total' => $total, 'runs' => array_map(static fn ($run): array => $run->jsonSerialize(), $runs)]);

	}//end runs()

	/**
	 * The verdict the run log is narrowed to, null for none, or the refusal.
	 *
	 * An unknown verdict narrows to nothing rather than being ignored:
	 * silently returning every row for a filter the caller believes is
	 * applied is how a reader concludes a rule never errored.
	 *
	 * @return string|JSONResponse|null The verdict, null, or the 422.
	 */
	private function verdictFilter(): string|JSONResponse|null {
		$verdict = $this->request->getParam('verdict');
		if (is_string($verdict) === true && $this->vocabulary->hasVerdict(verdict: $verdict) === true) {
			return $verdict;
		}

		if (is_string($verdict) === true && $verdict !== '') {
			return $this->refusal(
				code: 'rule-runs-unknown-verdict',
				message: sprintf('"%s" is not a verdict this engine reaches.', $verdict),
				status: 422
			);
		}

		return null;
	}//end verdictFilter()

	/**
	 * A request parameter of the accepted type, or the fallback when it is absent or of another type.
	 *
	 * @param string $name The parameter name.
	 * @param callable(mixed): bool $accepts The type check, such as `is_array`.
	 * @param mixed $fallback The value for an absent or mistyped parameter.
	 *
	 * @return mixed The parameter, or the fallback.
	 */
	private function paramOf(string $name, callable $accepts, mixed $fallback): mixed {
		$value = $this->request->getParam($name, $fallback);
		if ($accepts($value) === true) {
			return $value;
		}

		return $fallback;
	}//end paramOf()

	/**
	 * Whether a request value is a non-empty string.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return bool True for a non-empty string.
	 */
	private function isFilled(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isFilled()

	/**
	 * Resolve a schema reference, or null when it names nothing readable.
	 *
	 * @param string $reference Schema id, uuid or slug.
	 *
	 * @return Schema|null The schema, or null.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function resolveSchema(string $reference): ?Schema {
		try {
			return $this->schemaMapper->find($reference);
		} catch (Throwable $e) {
			return null;
		}

	}//end resolveSchema()

	/**
	 * The refusal for a schema reference that names nothing.
	 *
	 * @param string $reference The reference as the caller wrote it.
	 *
	 * @return JSONResponse The 404.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function notFound(string $reference): JSONResponse {
		return $this->refusal(code: 'rule-schema-not-found', message: sprintf('No schema "%s".', $reference), status: 404);

	}//end notFound()

	/**
	 * A refusal in the shape every rules endpoint returns.
	 *
	 * @param string $code The machine-readable code.
	 * @param string $message The sentence the operator reads.
	 * @param int $status The HTTP status.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function refusal(string $code, string $message, int $status): JSONResponse {
		return new JSONResponse(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
	}//end refusal()

	/**
	 * A service result as a response, with the status it maps onto.
	 *
	 * @param array<string, mixed> $result The result.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(array $result): JSONResponse {
		return new JSONResponse($result, $this->statusFor(result: $result));
	}//end answer()

	/**
	 * Map a service result onto an HTTP status.
	 *
	 * @param array<string, mixed> $result The result.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function statusFor(array $result): int {
		if (($result['ok'] ?? false) === true) {
			return 200;
		}

		$code = (string)($result['error']['code'] ?? '');
		if ($code === RuleTrialServiceInterface::CODE_OBJECT_NOT_FOUND || $code === RuleEnablementServiceInterface::CODE_UNKNOWN_RULE) {
			return 404;
		}

		return 422;

	}//end statusFor()
}//end class
