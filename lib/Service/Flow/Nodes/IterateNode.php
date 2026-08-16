<?php

/**
 * Repeats a chain of steps until a source stops producing.
 *
 * The engine could already loop — it is a Petri net, so an edge drawn backwards
 * is a cycle — but that is an execution capability, not an authoring one. A
 * back-edge looks identical to a forward edge, so the most important fact about
 * a graph (that a region repeats) is carried by edge DIRECTION, which is exactly
 * what someone scanning a diagram does not reliably read. Loop membership was
 * inferred by tracing rather than declared, the bound was a whole-run ceiling
 * shared between every loop, and non-convergence was only diagnosed after the
 * side effects had happened.
 *
 * Here the loop OWNS its body. Membership is data, so a builder can draw the
 * region as a container; the bound belongs to the loop that overran, so the
 * error can name it; and each body step runs with its iteration index in scope.
 *
 * Termination is deliberately one rule: the loop stops when `source` returns no
 * items. Pagination falls out of that without a second concept — a page past the
 * end is empty. `context['iteration']` carries the index so the source can ask
 * for the right page.
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
 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Container\ContainerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * A declared loop region: a source, a body, and a bound.
 */
class IterateNode implements IFlowNode, IFlowNodeConfigKeys {

	/**
	 * Default ceiling when a flow declares none.
	 *
	 * @var integer
	 */
	public const DEFAULT_MAX_ITERATIONS = 100;

	/**
	 * Overrun ends the run as failed.
	 *
	 * @var string
	 */
	public const ON_LIMIT_FAIL = 'fail';

	/**
	 * Overrun ends the loop and carries on with what was gathered.
	 *
	 * @var string
	 */
	public const ON_LIMIT_STOP = 'stop';

	/**
	 * Constructor.
	 *
	 * The dispatcher is resolved from the container at execute time rather than
	 * injected: this node is registered IN the node registry, and the dispatcher
	 * reads from that same registry, so constructor injection would close a
	 * cycle while the registry is still being populated.
	 *
	 * @param IL10N $l10n Translations.
	 * @param IURLGenerator $urls For the palette icon.
	 * @param ContainerInterface $container Resolves the step dispatcher lazily.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The step type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function getId(): string {
		return 'openregister.iterate';
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Repeat until done');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function getDescription(): string {
		return $this->l10n->t('Run a chain of steps once per batch, until the source runs out.');
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/history.svg');
	}//end getIcon()

	/**
	 * Iteration grants no privilege of its own; the steps inside are gated.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return boolean Whether it is available.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The config vocabulary of an iterate step.
	 *
	 * @return array<int, string> The accepted config keys.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function configKeys(): array {
		return ['source', 'body', 'maxIterations', 'onLimit'];
	}//end configKeys()

	/**
	 * Refuse a loop that cannot converge or has nothing to repeat.
	 *
	 * Validated at SAVE time on purpose. A loop that cannot terminate is the one
	 * authoring mistake whose cost is paid in side effects — by the time a
	 * transition ceiling notices, the body has already written whatever it
	 * writes, however many times it managed.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the loop is unusable.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function validateConfig(array $config): void {
		$this->assertSource(source: ($config['source'] ?? null));
		$this->assertBody(body: ($config['body'] ?? null));
		$this->assertBounds(config: $config);

	}//end validateConfig()

	/**
	 * What the SOURCE runs against on one pass.
	 *
	 * Two things had to be true for a paging source to work, and neither was:
	 *
	 * ONE ITEM, ALWAYS. A per-item source does its work once per item, so a
	 * pass seeded with zero items does nothing and returns nothing — which the
	 * loop reads as "the source ran out" and stops. Measured: a paging
	 * source-call fetched one page and terminated, so `iterate` could express a
	 * loop that never looped, while reporting success.
	 *
	 * THE ITERATION ON THE ITEM. This class documents `context['iteration']` as
	 * how a source asks for the right page, but a step's templates render
	 * against the ITEM, not the context — so `{{ iteration.index }}` in an
	 * endpoint resolved to nothing and every pass fetched page one. Measured
	 * against a real paging API: identical ids every pass, the source never ran
	 * out, and the loop hit its ceiling and failed with "the source never runs
	 * out" — blaming the API for what the engine had not told it.
	 *
	 * Later passes deliberately do NOT carry the previous pass's output: the
	 * source is paging, not re-filtering what it just produced.
	 *
	 * @param integer $index The pass number.
	 * @param array<int, mixed> $upstream The items that entered the loop.
	 * @param array<string, mixed> $iteration The `{index, first}` descriptor.
	 *
	 * @return array<int, mixed> The items the source runs against.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowItems::item is the item
	 * constructor every node in the engine uses. Injecting a factory here would
	 * give the engine two ways to build an item and no way to tell which one a
	 * node used.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	private function seedFor(int $index, array $upstream, array $iteration): array {
		if ($index > 0 || $upstream === []) {
			return [FlowItems::item(json: ['iteration' => $iteration])];
		}

		// First pass: whatever entered the loop, each item told which pass it is
		// on, so a source can page from the caller's own data.
		return array_map(
			static function (array $item) use ($iteration): array {
				$item['json'] = array_merge((array)($item['json'] ?? []), ['iteration' => $iteration]);

				return $item;
			},
			$upstream
		);

	}//end seedFor()

	/**
	 * A loop needs something that produces batches.
	 *
	 * @param mixed $source The declared source step.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the source is missing or typeless.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	private function assertSource($source): void {
		if (is_array($source) === false || trim((string)($source['type'] ?? '')) === '') {
			throw new UnexpectedValueException(
				$this->l10n->t('A repeat step needs a source that produces each batch.')
			);
		}

	}//end assertSource()

	/**
	 * A loop needs something to repeat, and every step in it needs a type.
	 *
	 * @param mixed $body The declared body chain.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the body is empty or malformed.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	private function assertBody($body): void {
		if (is_array($body) === false || $body === []) {
			throw new UnexpectedValueException(
				$this->l10n->t('A repeat step needs at least one step to repeat.')
			);
		}

		foreach ($body as $index => $step) {
			if (is_array($step) === false || trim((string)($step['type'] ?? '')) === '') {
				throw new UnexpectedValueException(
					$this->l10n->t('Step %s inside the repeat has no type.', [(string)$index])
				);
			}
		}

	}//end assertBody()

	/**
	 * The limit must be positive, and the overrun behaviour must be one we have.
	 *
	 * @param array $config The step configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the bound is unusable.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	private function assertBounds(array $config): void {
		$max = ($config['maxIterations'] ?? self::DEFAULT_MAX_ITERATIONS);
		if (is_numeric($max) === false || (int)$max < 1) {
			throw new UnexpectedValueException(
				$this->l10n->t('A repeat step needs a positive iteration limit.')
			);
		}

		$onLimit = (string)($config['onLimit'] ?? self::ON_LIMIT_FAIL);
		if (in_array($onLimit, [self::ON_LIMIT_FAIL, self::ON_LIMIT_STOP], true) === false) {
			throw new UnexpectedValueException(
				$this->l10n->t('A repeat step must either fail or stop when it hits its limit.')
			);
		}

	}//end assertBounds()

	/**
	 * Run the body once per batch, until the source runs dry.
	 *
	 * Items produced by each pass are ACCUMULATED and returned together, so the
	 * step downstream of a paginating loop sees every record rather than only
	 * the final page. That is what an author expects from "repeat until done",
	 * and getting it wrong would silently discard all but the last batch.
	 *
	 * @param array $items The input items, passed to the first source call.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array Every item produced across all iterations.
	 *
	 * @throws RuntimeException When the loop overruns its declared limit.
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function execute(array $items, array $config, array $context): array {
		$dispatcher = $this->container->get(FlowStepDispatcher::class);
		$source = (array)$config['source'];
		$body = (array)$config['body'];
		$max = (int)($config['maxIterations'] ?? self::DEFAULT_MAX_ITERATIONS);
		$onLimit = (string)($config['onLimit'] ?? self::ON_LIMIT_FAIL);

		$gathered = [];
		$upstream = $items;

		for ($index = 0; $index < $max; $index++) {
			$scoped = $context;
			$scoped['iteration'] = [
				'index' => $index,
				'first' => ($index === 0),
			];

			$seed = $this->seedFor(index: $index, upstream: $upstream, iteration: $scoped['iteration']);
			$batch = $dispatcher->dispatch(step: $source, items: $seed, context: $scoped);

			// The one termination rule. A source with nothing left returns
			// nothing, which is exactly what a page past the end looks like —
			// so pagination needs no second concept to express "done".
			if (empty($batch) === true) {
				return $gathered;
			}

			foreach ($body as $step) {
				$batch = $dispatcher->dispatch(step: $step, items: $batch, context: $scoped);
			}

			$gathered = array_merge($gathered, $batch);
		}//end for

		if ($onLimit === self::ON_LIMIT_STOP) {
			return $gathered;
		}

		throw new RuntimeException(
			sprintf(
				'Repeat step did not finish within %d iterations. Its source is still producing, '
				. 'so either the limit is too low or the source never runs out.',
				$max
			)
		);

	}//end execute()
}//end class
