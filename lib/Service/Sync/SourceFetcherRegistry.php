<?php

/**
 * OpenRegister Source Fetcher Registry
 *
 * Resolves the appropriate SourceFetcherInterface for a given source type.
 * Fetchers are registered explicitly; the registry returns the first that
 * reports support for the requested type. Pure selection logic, so it is
 * unit-testable with in-memory fetcher doubles.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Sync;

use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Registry mapping source types to fetchers.
 *
 * Filled by dispatching {@see RegisterSourceFetchersEvent} once, on first
 * use; OpenRegister's own fetchers arrive through the same event. A fetcher
 * is looked up by its getType(); one whose type is already taken is refused
 * and logged, never swapped in, so one app cannot replace another app's
 * protocol.
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
 */
class SourceFetcherRegistry implements SourceFetcherLookupInterface {

	/**
	 * Registered fetchers, in registration order.
	 *
	 * @var list<SourceFetcherInterface>
	 */
	private array $fetchers = [];

	/**
	 * Fetchers by declared type.
	 *
	 * @var array<string, SourceFetcherInterface>
	 */
	private array $byType = [];

	/**
	 * Owning app per declared type.
	 *
	 * @var array<string, string>
	 */
	private array $appByType = [];

	/**
	 * Whether the registration event was dispatched.
	 *
	 * @var bool
	 */
	private bool $loaded = false;

	/**
	 * Constructor.
	 *
	 * @param iterable<SourceFetcherInterface> $fetchers   Initial fetchers
	 * @param IEventDispatcher|null            $dispatcher Dispatches the registration event (none: no event)
	 * @param LoggerInterface|null             $logger     Logs a refused duplicate
	 */
	public function __construct(
		iterable $fetchers = [],
		private readonly ?IEventDispatcher $dispatcher = null,
		private readonly ?LoggerInterface $logger = null,
	) {
		foreach ($fetchers as $fetcher) {
			$this->register(fetcher: $fetcher);
		}
	}//end __construct()

	/**
	 * Register a fetcher; a second fetcher for a declared type already taken is refused and logged.
	 *
	 * @param SourceFetcherInterface $fetcher The fetcher
	 * @param string|null            $appId   The owning app (default: the type's namespace, else openregister)
	 *
	 * @return bool True when registered, false when refused
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function register(SourceFetcherInterface $fetcher, ?string $appId = null): bool {
		$type = $fetcher->getType();
		if ($type !== '' && isset($this->byType[$type]) === true) {
			$this->logger?->error(
				message: sprintf(
					'[SourceFetcherRegistry] Refused %s for source type "%s": %s already serves it',
					get_class($fetcher),
					$type,
					get_class($this->byType[$type])
				),
				context: ['type' => $type, 'existing' => get_class($this->byType[$type]), 'rejected' => get_class($fetcher)]
			);
			return false;
		}

		$this->fetchers[] = $fetcher;
		if ($type !== '') {
			$this->byType[$type]    = $fetcher;
			$this->appByType[$type] = ($appId ?? self::appOfType(type: $type));
		}

		return true;
	}//end register()

	/**
	 * Get a fetcher for the given source type.
	 *
	 * The fetcher declaring the type wins; otherwise the first whose supports()
	 * accepts it (a fetcher may serve aliases, `rest-api` also serves `openregister`).
	 *
	 * @param string $type The source type
	 *
	 * @return SourceFetcherInterface|null The fetcher, or null
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function get(string $type): ?SourceFetcherInterface {
		$this->load();
		if (isset($this->byType[$type]) === true) {
			return $this->byType[$type];
		}

		foreach ($this->fetchers as $fetcher) {
			if ($fetcher->supports($type) === true) {
				return $fetcher;
			}
		}

		return null;
	}//end get()

	/**
	 * Every declared type, for a source form's type list.
	 *
	 * @return list<array{type: string, displayName: string, application: string, configSchema: array<string, mixed>}>
	 *
	 * @spec openspec/changes/app-harvest-fetchers-and-flow-node/specs/data-sync-harvesting/spec.md
	 */
	public function types(): array {
		$this->load();
		$types = [];
		foreach ($this->byType as $type => $fetcher) {
			$types[] = [
				'type' => $type,
				'displayName' => $fetcher->getDisplayName(),
				'application' => $this->appByType[$type],
				'configSchema' => $fetcher->getConfigSchema(),
			];
		}

		return $types;
	}//end types()

	/**
	 * The app a type belongs to: the part before the first dot, or openregister for a bare name.
	 *
	 * @param string $type The type.
	 *
	 * @return string The app id.
	 */
	private static function appOfType(string $type): string {
		$dot = strpos($type, '.');
		if ($dot === false || $dot === 0) {
			return 'openregister';
		}

		return substr($type, 0, $dot);
	}//end appOfType()

	/**
	 * Dispatch the registration event, once.
	 *
	 * @return void
	 */
	private function load(): void {
		if ($this->loaded === true) {
			return;
		}

		// Set before dispatching, so a listener that touches the registry does not re-enter.
		$this->loaded = true;
		$this->dispatcher?->dispatchTyped(new RegisterSourceFetchersEvent(registry: $this));
	}//end load()
}//end class
