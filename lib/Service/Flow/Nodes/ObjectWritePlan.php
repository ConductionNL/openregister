<?php

/**
 * What one object-write step resolved from its configuration, and what it wrote.
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

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Flow\FlowStepReport;
use OCP\IUser;

/**
 * The operation, the acting owner, the register and schema, the match pairs,
 * fields and policies an object-write step resolved once before its first
 * write, and the counts of what it wrote.
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */
final class ObjectWritePlan
{

	/**
	 * The resolved operation.
	 *
	 * @var string
	 */
	public string $operation = '';

	/**
	 * The match pairs.
	 *
	 * @var array
	 */
	public array $pairs = [];

	/**
	 * The configured fields.
	 *
	 * @var array
	 */
	public array $fields = [];

	/**
	 * The missing-field policy.
	 *
	 * @var string
	 */
	public string $onMissing = '';

	/**
	 * The conflict policy.
	 *
	 * @var string
	 */
	public string $onConflict = '';

	/**
	 * Whether an update replaces rather than patches.
	 *
	 * @var boolean
	 */
	public bool $replace = false;

	/**
	 * The write cap.
	 *
	 * @var integer
	 */
	public int $cap = 0;

	/**
	 * The path whose truthy value skips an item, or empty.
	 *
	 * @var string
	 */
	public string $skipWhen = '';

	/**
	 * The path whose value is the whole payload, or empty.
	 *
	 * @var string
	 */
	public string $payloadFrom = '';

	/**
	 * Writes performed so far, against the cap.
	 *
	 * @var integer
	 */
	public int $writes = 0;

	/**
	 * Objects created, updated and deleted.
	 *
	 * @var array{created: int, updated: int, deleted: int}
	 */
	public array $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

	/**
	 * The run context.
	 *
	 * @var array
	 */
	public array $context = [];

	/**
	 * Constructor.
	 *
	 * @param array    $config   The step configuration.
	 * @param IUser    $owner    The run owner, as the acting user.
	 * @param Register $register The resolved register.
	 * @param Schema   $schema   The resolved schema.
	 */
	public function __construct(
		public readonly array $config,
		public readonly IUser $owner,
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()

	/**
	 * Read the fields and policies a step applies to every item.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function readPolicies(): void {
		$this->fields = (array)($this->config['fields'] ?? []);
		$options = new ObjectWriteOptions();
		$this->onMissing = $options->option(config: $this->config, key: 'onMissing');
		$this->onConflict = $options->option(config: $this->config, key: 'onConflict');
		$this->replace = (($this->config['replace'] ?? false) === true);
		$this->skipWhen = trim((string)($this->config['skipWhen'] ?? ''));
		$this->payloadFrom = trim((string)($this->config['payloadFrom'] ?? ''));
	}//end readPolicies()

	/**
	 * The json a written item carries onward.
	 *
	 * With `output` set, the incoming record is PRESERVED and the written object
	 * is added under that key — the convention every other node already follows
	 * (`hermiq.agent-step`, `openconnector.source-call`,
	 * `openregister.flow-state` all do this). Without it, the written object
	 * REPLACES the record, which is the historical behaviour and stays the
	 * default because changing it silently would rewrite what existing flows
	 * see.
	 *
	 * Replacing is fine for a write that ends a branch and wrong for one in the
	 * middle of a chain, because it discards everything the run was carrying. A
	 * per-issue lock is exactly the second shape: hydra's sequencer claims a
	 * lock and then still needs the repo, the issue and its slot number to do
	 * the work the lock protects. Measured while building it — after the lock
	 * write, `{{repo}}` rendered empty and the next call went to `/repos//issues`.
	 *
	 * @param array $incoming The item's json as it arrived.
	 * @param array $written  The written object's json.
	 *
	 * @return array The outgoing json.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function shaped(array $incoming, array $written): array {
		$key = trim((string)($this->config['output'] ?? ''));
		if ($key === '') {
			return $written;
		}

		$incoming[$key] = $written;

		return $incoming;
	}//end shaped()

	/**
	 * The register's most legible identifier, for an output item.
	 *
	 * @return string The slug, or the id when it has none.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function registerLabel(): string {
		$slug = (string)($this->register->getSlug() ?? '');
		if ($slug !== '') {
			return $slug;
		}

		return (string)$this->register->getId();
	}//end registerLabel()

	/**
	 * The schema's most legible identifier, for an output item.
	 *
	 * @return string The slug, or the id when it has none.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function schemaLabel(): string {
		$slug = (string)($this->schema->getSlug() ?? '');
		if ($slug !== '') {
			return $slug;
		}

		return (string)$this->schema->getId();
	}//end schemaLabel()

	/**
	 * The output record of a delete that was attempted.
	 *
	 * @param string $uuid    The object's uuid.
	 * @param bool   $deleted Whether the service confirmed the delete.
	 *
	 * @return array{uuid: string, register: string, schema: string, deleted: bool} The record.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function deleteRecord(string $uuid, bool $deleted): array {
		return ['uuid' => $uuid, 'register' => $this->registerLabel(), 'schema' => $this->schemaLabel(), 'deleted' => $deleted];
	}//end deleteRecord()

	/**
	 * Count one performed save.
	 *
	 * @param bool $matched Whether it landed on an existing object.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function countSave(bool $matched): void {
		$this->writes++;
		$this->counts['created'] += (int)($matched === false);
		$this->counts['updated'] += (int)($matched === true);
	}//end countSave()

	/**
	 * The created / updated / deleted tally of the per-item path.
	 *
	 * The deleted count is not the write count: every delete attempt counts towards
	 * the cap, but only a delete the service confirms counts as deleted.
	 *
	 * @return array<string, array{count: int}> The report.
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function itemCounts(): array {
		return [
			'created' => ['count' => $this->counts['created']],
			'updated' => ['count' => $this->counts['updated']],
			'deleted' => ['count' => $this->counts['deleted']],
		];
	}//end itemCounts()

	/**
	 * Write this hop's write tally onto the run log, via the context's report handle.
	 *
	 * Silently a no-op when the handle is absent: a run from before this handle
	 * existed, or a caller that never wired one.
	 *
	 * SEMANTICS: this reports a COMPLETED node execution, not "objects persisted so
	 * far". Both write paths call this only after every item this hop will touch has
	 * been both written AND turned into an output item, never between the two. An
	 * exception anywhere in that work propagates out of the node instead of reaching
	 * this call, so a step that fails never gets a report, even though the bulk
	 * path's database writes before the exception would already be durable. Keep any
	 * future write path to this same placement, or the report starts answering a
	 * different question depending on which path produced it.
	 *
	 * @param array $report The summary, keyed per {@see FlowStepReport::put()}.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
	 */
	public function report(array $report): void {
		$handle = ($this->context[FlowStepReport::CONTEXT_KEY] ?? null);
		if (($handle instanceof FlowStepReport) === true) {
			$handle->put(key: 'write', value: $report);
		}
	}//end report()
}//end class
