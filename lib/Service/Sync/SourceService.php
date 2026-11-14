<?php

/**
 * OpenRegister Source Service
 *
 * Creates, updates and deletes sources with their validation, and keeps each scheduled
 * source's flow in step with it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use Cron\CronExpression;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\Dmn\DecisionTableValidator;
use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The one writer of sources: apps and the REST surface both go through it.
 *
 * A write is refused, naming the field, when its `config` fails the fetcher's
 * schema, when it has a `schedule` without a resolvable enabled `runAs`, when
 * its `conflictRules` is a table the evaluator cannot run, when it asks for
 * `hard-delete` on an app's source, or when `maxItemsPerRun` is out of range.
 * A source with a schedule and an acting identity gets one flow, keyed by its
 * uuid; deleting the source deletes the flow and keeps its sync records.
 *
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
class SourceService {

	/**
	 * The fields a write is validated on.
	 */
	private const VALIDATED = ['type', 'config', 'schedule', 'runAs', 'conflictRules', 'deleteStrategy', 'application', 'maxItemsPerRun'];

	/**
	 * Constructor.
	 *
	 * @param SourceMapperInterface        $sources   The source table.
	 * @param SourceFetcherLookupInterface $fetchers  The fetcher per type, with its config schema.
	 * @param IUserManager                 $users     Resolves `runAs`.
	 * @param IUserSession                 $session   The user saving, who owns the source's flow.
	 * @param SourceHarvestFlow            $flows     Writes and removes the source's flow.
	 * @param DecisionTableValidator       $tables    Checks `conflictRules`.
	 * @param OasRequestValidator          $schemas   Checks `config` against the fetcher's JSON Schema.
	 */
	public function __construct(
		private readonly SourceMapperInterface $sources,
		private readonly SourceFetcherLookupInterface $fetchers,
		private readonly IUserManager $users,
		private readonly IUserSession $session,
		private readonly SourceHarvestFlow $flows,
		private readonly DecisionTableValidator $tables=new DecisionTableValidator(),
		private readonly OasRequestValidator $schemas=new OasRequestValidator(),
	) {
	}//end __construct()

	/**
	 * Create a source, and its flow when it is scheduled.
	 *
	 * A new source without a delete strategy gets `flag`.
	 *
	 * @param array<string, mixed> $data The source's fields.
	 *
	 * @return Source The stored source.
	 *
	 * @throws SourceRefused When a field is refused.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function create(array $data): Source {
		if (trim((string)($data['deleteStrategy'] ?? '')) === '') {
			$data['deleteStrategy'] = HarvestTombstonePass::STRATEGY_FLAG;
		}

		$this->validate(fields: $data);
		unset($data['flowId']);

		return $this->withFlow(source: $this->sources->createFromArray(object: $data));
	}//end create()

	/**
	 * Update a source, and write, update, disable or remove its flow to match.
	 *
	 * @param int                  $id   The source id.
	 * @param array<string, mixed> $data The changed fields.
	 *
	 * @return Source The stored source.
	 *
	 * @throws SourceRefused When a field is refused.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the source is not the caller's to see.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function update(int $id, array $data): Source {
		$current = $this->sources->find(id: $id);
		$this->validate(fields: array_replace(self::validatedFieldsOf(source: $current), $data));
		unset($data['flowId']);

		return $this->withFlow(source: $this->sources->updateFromArray(id: $id, object: $data));
	}//end update()

	/**
	 * Delete a source and its flow; its sync records stay as history.
	 *
	 * @param int $id The source id.
	 *
	 * @return Source The deleted source.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When the source is not the caller's to see.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function delete(int $id): Source {
		$source = $this->sources->find(id: $id);
		$this->flows->remove(source: $source);

		return $this->sources->delete($source);
	}//end delete()

	/**
	 * Refuse a source whose fields break a rule, naming the first field that does.
	 *
	 * @param array<string, mixed> $fields The source's fields, as they would be stored.
	 *
	 * @return void
	 *
	 * @throws SourceRefused When a field is refused.
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function validate(array $fields): void {
		$this->validateConfig(type: (string)($fields['type'] ?? ''), config: ($fields['config'] ?? null));
		$this->validateSchedule(schedule: ($fields['schedule'] ?? null), runAs: ($fields['runAs'] ?? null));
		$this->validateConflictRules(rules: ($fields['conflictRules'] ?? null));
		$this->validateDeleteStrategy(strategy: ($fields['deleteStrategy'] ?? null), application: ($fields['application'] ?? null));
		$this->validateMaxItems(max: ($fields['maxItemsPerRun'] ?? null));
	}//end validate()

	/**
	 * The validated fields of a stored source.
	 *
	 * @param Source $source The source.
	 *
	 * @return array<string, mixed>
	 */
	private static function validatedFieldsOf(Source $source): array {
		$fields = [];
		foreach (self::VALIDATED as $field) {
			$fields[$field] = $source->{'get'.ucfirst($field)}();
		}

		return $fields;
	}//end validatedFieldsOf()

	/**
	 * Write or remove the source's flow and store its id on the source.
	 *
	 * @param Source $source The stored source.
	 *
	 * @return Source The source, with its flow id.
	 *
	 * @throws SourceRefused When the schedule trigger rejects the cron or runAs.
	 */
	private function withFlow(Source $source): Source {
		$owner = ($this->session->getUser()?->getUID() ?? (string)$source->getRunAs());
		try {
			$flowId = $this->flows->sync(source: $source, owner: $owner);
		} catch (InvalidArgumentException $e) {
			throw new SourceRefused(field: 'schedule', message: $e->getMessage());
		}

		if ($flowId === $source->getFlowId()) {
			return $source;
		}

		$source->setFlowId($flowId);

		return $this->sources->update($source);
	}//end withFlow()

	/**
	 * Refuse a config that fails the fetcher's JSON Schema.
	 *
	 * A type with no registered fetcher is not checked: its app may be
	 * disabled, and the source must stay editable.
	 *
	 * @param string $type   The source type.
	 * @param mixed  $config The config.
	 *
	 * @return void
	 *
	 * @throws SourceRefused When the config is not an object or fails the schema.
	 */
	private function validateConfig(string $type, mixed $config): void {
		if ($config === null) {
			return;
		}

		if (is_array($config) === false) {
			throw new SourceRefused(field: 'config', message: 'config must be an object');
		}

		$schema = $this->fetchers->get($type)?->getConfigSchema();
		if ($schema === null || $schema === []) {
			return;
		}

		$errors = $this->schemas->validate(body: (object)$config, schema: $schema);
		if ($errors === []) {
			return;
		}

		// The deepest error names the field; the ones above it only say a child failed.
		$first = $errors[0];
		foreach ($errors as $error) {
			if (strlen((string)($error['path'] ?? '')) > strlen((string)($first['path'] ?? ''))) {
				$first = $error;
			}
		}

		$path = trim((string)($first['path'] ?? ''), '/');
		$field = 'config';
		if ($path !== '') {
			$field = 'config.'.str_replace('/', '.', $path);
		}

		throw new SourceRefused(field: $field, message: (string)($first['message'] ?? 'config does not match the type\'s schema'));
	}//end validateConfig()

	/**
	 * Refuse a schedule that is not a cron expression or has no resolvable enabled runAs.
	 *
	 * @param mixed $schedule The cron expression.
	 * @param mixed $runAs    The user id the harvest runs as.
	 *
	 * @return void
	 *
	 * @throws SourceRefused When either is refused.
	 */
	private function validateSchedule(mixed $schedule, mixed $runAs): void {
		$schedule = trim((string)$schedule);
		$runAs    = trim((string)$runAs);
		if ($schedule !== '' && CronExpression::isValidExpression($schedule) === false) {
			throw new SourceRefused(field: 'schedule', message: 'schedule must be a cron expression');
		}

		if ($schedule !== '' && $runAs === '') {
			throw new SourceRefused(field: 'runAs', message: 'A scheduled source needs runAs: the account the harvest runs as');
		}

		if ($runAs === '') {
			return;
		}

		$user = $this->users->get($runAs);
		if ($user === null || $user->isEnabled() === false) {
			throw new SourceRefused(field: 'runAs', message: 'runAs must name an existing, enabled account');
		}
	}//end validateSchedule()

	/**
	 * Refuse conflict rules the decision-table evaluator cannot run.
	 *
	 * @param mixed $rules The decision table.
	 *
	 * @return void
	 *
	 * @throws SourceRefused When the table is refused.
	 */
	private function validateConflictRules(mixed $rules): void {
		if ($rules === null || $rules === []) {
			return;
		}

		if (is_array($rules) === false) {
			throw new SourceRefused(field: 'conflictRules', message: 'conflictRules must be a decision table');
		}

		$problems = $this->tables->validate(table: $rules);
		if ($problems !== []) {
			throw new SourceRefused(field: 'conflictRules', message: implode('; ', $problems));
		}
	}//end validateConflictRules()

	/**
	 * Refuse an unknown delete strategy, and hard-delete on an app's source.
	 *
	 * @param mixed $strategy    The delete strategy.
	 * @param mixed $application The owning app, if any.
	 *
	 * @return void
	 *
	 * @throws SourceRefused When the strategy is refused.
	 */
	private function validateDeleteStrategy(mixed $strategy, mixed $application): void {
		$strategy = trim((string)$strategy);
		if ($strategy === '') {
			return;
		}

		if (in_array($strategy, HarvestTombstonePass::STRATEGIES, true) === false) {
			throw new SourceRefused(
				field: 'deleteStrategy',
				message: 'deleteStrategy must be one of '.implode(', ', HarvestTombstonePass::STRATEGIES)
			);
		}

		if ($strategy === HarvestTombstonePass::STRATEGY_HARD_DELETE && trim((string)$application) !== '') {
			throw new SourceRefused(field: 'deleteStrategy', message: 'hard-delete is not offered for an app\'s source');
		}
	}//end validateDeleteStrategy()

	/**
	 * Refuse a maxItemsPerRun outside 1 and the ceiling.
	 *
	 * @param mixed $max The cap.
	 *
	 * @return void
	 *
	 * @throws SourceRefused When the cap is refused.
	 */
	private function validateMaxItems(mixed $max): void {
		if ($max === null || $max === '') {
			return;
		}

		$ceiling = HarvestPipelineService::MAX_ITEMS_PER_RUN;
		$value   = filter_var($max, FILTER_VALIDATE_INT);
		if ($value === false || $value < 1 || $value > $ceiling) {
			throw new SourceRefused(field: 'maxItemsPerRun', message: 'maxItemsPerRun must be a whole number from 1 to '.$ceiling);
		}
	}//end validateMaxItems()
}//end class
