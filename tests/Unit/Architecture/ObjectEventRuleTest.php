<?php

/**
 * The rule for creation side effects cannot be bypassed without this test failing (decision 227).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/draft-creation-side-effects/specs/object-events/spec.md#requirement-the-rule-must-be-kept-by-a-test-not-by-attention
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Event\DraftCreatedEvent;
use OCA\OpenRegister\Event\DraftDeletedEvent;
use OCA\OpenRegister\Event\DraftUpdatedEvent;
use OCA\OpenRegister\Event\ObjectActivatedEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Listener\AggregationCacheInvalidationListener;
use OCA\OpenRegister\Listener\FacetCacheInvalidationListener;
use OCA\OpenRegister\Listener\ListenerRegistrar;
use OCA\OpenRegister\Listener\NotificationDedupePruneListener;
use OCA\OpenRegister\Listener\ObjectCleanupListener;
use OCA\OpenRegister\Listener\ReadStatePruneListener;
use OCA\OpenRegister\Listener\TranslationProjectionListener;
use OCA\OpenRegister\Listener\WatcherPruneListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Three ways around the rule, each closed here.
 *
 * 1. Creating an object event outside ObjectEventGate, so a draft's save
 *    dispatches ObjectCreatedEvent after all.
 * 2. Registering a listener on a draft event that is not on the list of
 *    listeners that must see a draft.
 * 3. Doing creation work on ObjectActivatedEvent as well as on
 *    ObjectCreatedEvent, which runs it twice when a draft leaves draft.
 *
 * @coversNothing
 */
class ObjectEventRuleTest extends TestCase {

	/**
	 * The listeners that must see a draft, per draft event. The same list as the spec's.
	 *
	 * Adding one here is a decision: it says this listener keeps the stored
	 * draft itself right and tells nobody anything.
	 *
	 * @var array<class-string, array<int, class-string>>
	 */
	private const MUST_SEE_A_DRAFT = [
		DraftCreatedEvent::class => [
			AggregationCacheInvalidationListener::class,
			FacetCacheInvalidationListener::class,
			TranslationProjectionListener::class,
		],
		DraftUpdatedEvent::class => [
			AggregationCacheInvalidationListener::class,
			FacetCacheInvalidationListener::class,
			TranslationProjectionListener::class,
		],
		DraftDeletedEvent::class => [
			AggregationCacheInvalidationListener::class,
			FacetCacheInvalidationListener::class,
			TranslationProjectionListener::class,
			ObjectCleanupListener::class,
			WatcherPruneListener::class,
			ReadStatePruneListener::class,
			NotificationDedupePruneListener::class,
		],
	];

	/**
	 * Every listener registration the app makes: event class => listener classes.
	 *
	 * Taken from the real registration code, run against a recording context.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function registrations(): array {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[$event][] = $listener;
			}
		);

		ListenerRegistrar::register(context: $context);

		return $registered;
	}//end registrations()

	/**
	 * Every PHP file under lib/, by path relative to the app.
	 *
	 * @return array<string, string> Path => source.
	 */
	private function sources(): array {
		$root = dirname(__DIR__, 3);
		$sources = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
			if ($file->getExtension() === 'php') {
				$sources[substr($file->getPathname(), (strlen($root) + 1))] = (string)file_get_contents($file->getPathname());
			}
		}

		$this->assertGreaterThan(500, count($sources), 'lib/ was not read.');

		return $sources;
	}//end sources()

	/**
	 * Only ObjectEventGate creates an object event, so no save or delete can pick its own event.
	 */
	public function testOnlyTheGateCreatesAnObjectEvent(): void {
		$offenders = [];
		foreach ($this->sources() as $path => $source) {
			if ($path === 'lib/Service/Object/ObjectEventGate.php') {
				// Control: the pattern finds the gate's own eight creations, so it can find another's.
				$this->assertSame(8, preg_match_all('/new\s+\\\\?(?:[A-Za-z]+\\\\)*(?:Object(?:Created|Updated|Deleted|Activated)|Draft(?:Created|Updated|Deleted))Event\s*\(/', $source));
				continue;
			}

			if (preg_match('/new\s+\\\\?(?:[A-Za-z]+\\\\)*(?:Object(?:Created|Updated|Deleted|Activated)|Draft(?:Created|Updated|Deleted))Event\s*\(/', $source) === 1) {
				$offenders[] = $path;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			'These classes create an object event themselves. Call ObjectEventGate instead: it decides whether the object is a draft, so a flow or a webhook never sees a half-typed object.'
		);
	}//end testOnlyTheGateCreatesAnObjectEvent()

	/**
	 * The listeners on the draft events are exactly the ones on the list.
	 */
	public function testOnlyTheListedListenersSeeADraft(): void {
		$registered = $this->registrations();

		// Control: the recording context saw the ordinary registrations, so an empty draft list would be a finding.
		$this->assertGreaterThan(10, count($registered[ObjectCreatedEvent::class] ?? []));

		foreach (self::MUST_SEE_A_DRAFT as $event => $listeners) {
			$found = ($registered[$event] ?? []);
			sort($found);
			sort($listeners);
			$this->assertSame(
				$listeners,
				$found,
				'The listeners on ' . $event . ' differ from the list of listeners that must see a draft. '
				. 'A listener that reacts to a new object belongs on ObjectCreatedEvent, where it never sees a draft.'
			);
		}
	}//end testOnlyTheListedListenersSeeADraft()

	/**
	 * A draft event is named in the gate and in the registration block, and nowhere else.
	 */
	public function testNoOtherClassNamesADraftEvent(): void {
		$allowed = [
			'lib/Service/Object/ObjectEventGate.php',
			'lib/Listener/ListenerRegistrar.php',
			'lib/Event/DraftCreatedEvent.php',
			'lib/Event/DraftUpdatedEvent.php',
			'lib/Event/DraftDeletedEvent.php',
		];
		$offenders = [];
		foreach ($this->sources() as $path => $source) {
			if (in_array($path, $allowed, true) === false && preg_match('/\bDraft(?:Created|Updated|Deleted)Event\b/', $source) === 1) {
				$offenders[] = $path;
			}
		}

		$this->assertSame([], $offenders, 'These classes name a draft event. Only ObjectEventGate dispatches one and only ListenerRegistrar::registerDraftListeners() registers on one.');
	}//end testNoOtherClassNamesADraftEvent()

	/**
	 * Nothing does creation work twice: no listener is on ObjectCreatedEvent and on ObjectActivatedEvent.
	 */
	public function testNoListenerIsOnBothTheCreatedAndTheActivatedEvent(): void {
		$registered = $this->registrations();

		$this->assertSame(
			[],
			array_values(array_intersect(($registered[ObjectCreatedEvent::class] ?? []), ($registered[ObjectActivatedEvent::class] ?? []))),
			'Leaving draft dispatches ObjectCreatedEvent and then ObjectActivatedEvent. A listener on both runs twice.'
		);
	}//end testNoListenerIsOnBothTheCreatedAndTheActivatedEvent()
}//end class
