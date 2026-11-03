<?php

/**
 * Which kinds of principal this instance understands, and what they resolve to.
 *
 * 🔴 NO CROSS-REQUEST CACHE, AND THAT IS THE DESIGN. A resolution is a fact
 * about the roster right now: who sits on the committee, who holds the post.
 * Caching it past the request would reintroduce exactly the defect the late
 * resolution exists to avoid — a task that keeps authorising somebody who has
 * left, and stops authorising the person now responsible.
 *
 * The registry caches the RESOLVERS, which are objects, not their answers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Principal
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Principal;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Collects principal resolvers and answers with the ids they mean.
 */
class PrincipalResolverRegistry {

	/**
	 * The resolvers, by type.
	 *
	 * @var array<string, IPrincipalResolver>
	 */
	private array $resolvers = [];

	/**
	 * Whether contribution has been collected this request.
	 *
	 * @var boolean
	 */
	private bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $dispatcher Dispatches the contribution event.
	 * @param LoggerInterface  $logger     Where a failing resolver is reported.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Add a resolver.
	 *
	 * A duplicate type is REFUSED rather than allowed to overwrite. Two apps
	 * claiming one type would otherwise resolve by load order, so which app
	 * decides who may answer would depend on which listener fired first — a
	 * difference nobody would see until the two disagreed.
	 *
	 * @param IPrincipalResolver $resolver The resolver.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the type is already claimed.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function register(IPrincipalResolver $resolver): void {
		$type = trim($resolver->type());
		if ($type === '') {
			throw new UnexpectedValueException('A principal resolver must name the type it answers for.');
		}

		if (array_key_exists($type, $this->resolvers) === true) {
			throw new UnexpectedValueException(
				sprintf('The principal type "%s" is already provided by another app.', $type)
			);
		}

		$this->resolvers[$type] = $resolver;

	}//end register()

	/**
	 * Whether this instance understands a type.
	 *
	 * 🔑 THE ANSWER DEPENDS ON WHAT IS INSTALLED, and that is intended. A flow
	 * naming `position` is valid on an instance with decidiq and not on one
	 * without it. The alternative — accepting every type — would let a flow
	 * save cleanly and then fail at run time on a machine nobody was watching.
	 *
	 * @param string $type The principal type.
	 *
	 * @return bool Whether a resolver answers for it.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function has(string $type): bool {
		$this->load();

		return array_key_exists(trim($type), $this->resolvers);

	}//end has()

	/**
	 * Every type this instance understands, for an editor to offer.
	 *
	 * @return array<int, string> The types, sorted so the list is stable.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function types(): array {
		$this->load();
		$types = array_keys($this->resolvers);
		sort($types);

		return $types;

	}//end types()

	/**
	 * The user ids one reference means, right now.
	 *
	 * An UNKNOWN type resolves to nothing and says so in the log. It is not an
	 * exception: this is called while authorising an answer, and a type whose
	 * app has been disabled since the flow was authored must refuse the answer
	 * rather than break the request.
	 *
	 * @param PrincipalReference $reference The reference.
	 *
	 * @return array<int, string> The user ids, possibly empty.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function resolve(PrincipalReference $reference): array {
		$this->load();

		$resolver = ($this->resolvers[$reference->type] ?? null);
		if ($resolver === null) {
			$this->logger->warning(
				message: '[PrincipalResolverRegistry] Nothing on this instance resolves the principal type "'
					. $reference->type . '". Is the app that owns it installed and enabled?',
				context: ['file' => __FILE__, 'line' => __LINE__, 'reference' => (string)$reference]
			);

			return [];
		}

		try {
			$ids = $resolver->resolve(id: $reference->id);
		} catch (Throwable $e) {
			// A resolver that throws must not take the answer verb down with
			// it. Refusing the answer is the safe direction: the alternative
			// is a 500 where a "you may not answer this" belongs.
			$this->logger->error(
				message: '[PrincipalResolverRegistry] Resolving "' . (string)$reference . '" failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => $e]
			);

			return [];
		}

		$clean = [];
        foreach ($ids as $id) {
			$uid = trim((string)$id);
			if ($uid !== '') {
				$clean[] = $uid;
			}
		}

		return array_values(array_unique($clean));

	}//end resolve()

	/**
	 * The union of what a list of references means.
	 *
	 * @param array<int, PrincipalReference> $references The references.
	 *
	 * @return array<int, string> The user ids.
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	public function resolveAll(array $references): array {
		$ids = [];
		foreach ($references as $reference) {
			$ids = array_merge($ids, $this->resolve(reference: $reference));
		}

		return array_values(array_unique($ids));

	}//end resolveAll()

	/**
	 * Collect contributions once per request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-typed-principals/specs/flow-typed-principals/spec.md
	 */
	private function load(): void {
		if ($this->loaded === true) {
			return;
		}

		// Set BEFORE dispatching, like the node registry: a listener that
		// resolves a service which itself touches this registry would
		// otherwise re-enter, dispatch again, and trip the duplicate guard.
		$this->loaded = true;
		$this->dispatcher->dispatchTyped(new RegisterPrincipalResolversEvent(registry: $this));

	}//end load()
}//end class
