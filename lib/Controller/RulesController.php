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
 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use OCA\OpenRegister\Db\RuleRunMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Rules\RuleEnablementService;
use OCA\OpenRegister\Service\Rules\RuleInventoryService;
use OCA\OpenRegister\Service\Rules\RuleTrialService;
use OCA\OpenRegister\Service\Rules\RuleVocabulary;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
 *   the run log, the trial and the switch, which are the four halves of the one surface
 *   this change exists to add.
 */
class RulesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param SchemaMapper $schemaMapper Resolves the schema a rule is declared on.
	 * @param RuleInventoryService $inventory Derives the rule list.
	 * @param RuleRunMapper $runs Reads the run log.
	 * @param RuleTrialService $trials Evaluates a rule without committing.
	 * @param RuleEnablementService $enablement Switches a rule off and on.
	 * @param RuleVocabulary $vocabulary The published kinds, verdicts and actions.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SchemaMapper $schemaMapper,
		private readonly RuleInventoryService $inventory,
		private readonly RuleRunMapper $runs,
		private readonly RuleTrialService $trials,
		private readonly RuleEnablementService $enablement,
		private readonly RuleVocabulary $vocabulary,
	) {
		parent::__construct(appName: $appName, request: $request);

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
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function vocabulary(): JSONResponse {
		return new JSONResponse($this->vocabulary->all());

	}//end vocabulary()

	/**
	 * Every rule that can act on a schema's objects, in evaluation order.
	 *
	 * @param string $schema Schema id, uuid or slug.
	 *
	 * @return JSONResponse The inventory rows.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function index(string $schema): JSONResponse {
		$entity = $this->resolveSchema(reference: $schema);
		if ($entity === null) {
			return $this->notFound(reference: $schema);
		}

		$window = (int)($this->request->getParam('idleDays') ?? RuleInventoryService::DEFAULT_IDLE_DAYS);

		return new JSONResponse(
			[
				'schema' => (string)($entity->getSlug() ?? ''),
				'rules' => $this->inventory->inventory(schema: $entity, idleDays: $window),
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
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function setEnabled(string $schema, string $ruleId): JSONResponse {
		$entity = $this->resolveSchema(reference: $schema);
		if ($entity === null) {
			return $this->notFound(reference: $schema);
		}

		$enabled = $this->request->getParam('enabled');
		if (is_bool($enabled) === false) {
			return new JSONResponse(
				[
					'ok' => false,
					'error' => [
						'code' => 'rule-switch-no-state',
						'message' => 'A boolean "enabled" is required.',
					],
				],
				422
			);
		}

		$result = $this->enablement->setEnabled(schema: $entity, ruleId: $ruleId, enabled: $enabled);

		return new JSONResponse($result, $this->statusFor(result: $result));

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
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function evaluate(string $schema, string $ruleId): JSONResponse {
		$entity = $this->resolveSchema(reference: $schema);
		if ($entity === null) {
			return $this->notFound(reference: $schema);
		}

		$override = $this->request->getParam('rule');
		if (is_array($override) === false) {
			$override = null;
		}

		$register = $this->request->getParam('register');
		$objectId = $this->request->getParam('objectId');
		if (is_string($register) === true && $register !== '' && is_string($objectId) === true && $objectId !== '') {
			$result = $this->trials->tryObject(
				schema: $entity,
				ruleId: $ruleId,
				register: $register,
				objectId: $objectId,
				override: $override
			);

			return new JSONResponse($result, $this->statusFor(result: $result));
		}

		$sample = $this->request->getParam('object', []);
		if (is_array($sample) === false) {
			$sample = [];
		}

		$result = $this->trials->trySample(
			schema: $entity,
			ruleId: $ruleId,
			sample: $sample,
			override: $override
		);

		return new JSONResponse($result, $this->statusFor(result: $result));

	}//end evaluate()

	/**
	 * One rule's run log, newest first, filtered by verdict and period.
	 *
	 * @param string $ruleId The derived rule id.
	 *
	 * @return JSONResponse The runs and how many matched.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	public function runs(string $ruleId): JSONResponse {
		$verdict = $this->request->getParam('verdict');
		if (is_string($verdict) === false || $this->vocabulary->hasVerdict(verdict: $verdict) === false) {
			// An unknown verdict narrows to nothing rather than being ignored:
			// silently returning every row for a filter the caller believes is
			// applied is how a reader concludes a rule never errored.
			if (is_string($verdict) === true && $verdict !== '') {
				return new JSONResponse(
					[
						'ok' => false,
						'error' => [
							'code' => 'rule-runs-unknown-verdict',
							'message' => sprintf('"%s" is not a verdict this engine reaches.', $verdict),
						],
					],
					422
				);
			}

			$verdict = null;
		}

		$since = $this->moment(value: $this->request->getParam('since'));
		$until = $this->moment(value: $this->request->getParam('until'));
		$limit = (int)($this->request->getParam('limit') ?? RuleRunMapper::DEFAULT_LIMIT);
		$offset = (int)($this->request->getParam('offset') ?? 0);

		$runs = $this->runs->findByRule(
			ruleId: $ruleId,
			verdict: $verdict,
			since: $since,
			until: $until,
			limit: $limit,
			offset: $offset
		);

		return new JSONResponse(
			[
				'ruleId' => $ruleId,
				'total' => $this->runs->countByRule(ruleId: $ruleId, verdict: $verdict, since: $since, until: $until),
				'runs' => array_map(static fn ($run): array => $run->jsonSerialize(), $runs),
			]
		);

	}//end runs()

	/**
	 * Resolve a schema reference, or null when it names nothing readable.
	 *
	 * @param string $reference Schema id, uuid or slug.
	 *
	 * @return Schema|null The schema, or null.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function resolveSchema(string $reference): ?Schema {
		try {
			return $this->schemaMapper->find($reference);
		} catch (Throwable $e) {
			return null;
		}

	}//end resolveSchema()

	/**
	 * Parse a moment from the query string, or null when it is not one.
	 *
	 * @param mixed $value The raw parameter.
	 *
	 * @return DateTime|null The moment, or null.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function moment(mixed $value): ?DateTime {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Throwable $e) {
			return null;
		}

	}//end moment()

	/**
	 * The refusal for a schema reference that names nothing.
	 *
	 * @param string $reference The reference as the caller wrote it.
	 *
	 * @return JSONResponse The 404.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function notFound(string $reference): JSONResponse {
		return new JSONResponse(
			[
				'ok' => false,
				'error' => [
					'code' => 'rule-schema-not-found',
					'message' => sprintf('No schema "%s".', $reference),
				],
			],
			404
		);

	}//end notFound()

	/**
	 * Map a service result onto an HTTP status.
	 *
	 * @param array<string, mixed> $result The result.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/flow-engine/spec.md
	 */
	private function statusFor(array $result): int {
		if (($result['ok'] ?? false) === true) {
			return 200;
		}

		$code = (string)($result['error']['code'] ?? '');
		if ($code === RuleTrialService::CODE_OBJECT_NOT_FOUND || $code === RuleEnablementService::CODE_UNKNOWN_RULE) {
			return 404;
		}

		return 422;

	}//end statusFor()
}//end class
