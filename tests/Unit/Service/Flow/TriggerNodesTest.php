<?php

/**
 * A trigger that never fires looks exactly like a flow with nothing to do.
 *
 * That is the whole reason these nodes validate at all. Every other node fails
 * loudly when it is misconfigured — it runs, it throws, the run goes red. A
 * trigger's failure mode is silence: the flow is saved, it looks configured on
 * the canvas, and nothing ever starts it. Nobody files a bug about a flow that
 * did not run, because a flow that did not run is also what a quiet week looks
 * like.
 *
 * So the assertions here are mostly about what must be REFUSED, and each one is
 * paired against the shape that must still be accepted, so the validation
 * cannot go quiet by becoming vacuous.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode
 */
class TriggerNodesTest extends TestCase {

	private TriggerObjectNode $object;

	private TriggerScheduleNode $schedule;

	private TriggerManualNode $manual;

	/**
	 * Build the three trigger nodes with stubbed collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []) {
				return $text;
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/icon.svg');

		// A schedule trigger must name a user its runs act as, and the node
		// resolves it to prove the account exists. `alice` resolves here; every
		// other uid does not, so the negative cases below are real.
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid): ?IUser => $uid === 'alice' ? $this->createMock(IUser::class) : null
		);

		$this->object = new TriggerObjectNode($l10n, $urls);
		$this->schedule = new TriggerScheduleNode($l10n, $urls, $userManager);
		$this->manual = new TriggerManualNode($l10n, $urls);

	}//end setUp()

	/**
	 * A fully-specified object trigger is accepted.
	 *
	 * The positive control. Without it every "must throw" assertion below is
	 * satisfied by a validator that rejects everything.
	 *
	 * @return void
	 */
	public function testAFullyNamedObjectTriggerIsAccepted(): void {
		$this->object->validateConfig(
			[
				'event' => 'object.updated',
				'register' => 'publications',
				'schema' => 'publication',
			]
		);

		$this->addToAssertionCount(1);

	}//end testAFullyNamedObjectTriggerIsAccepted()

	/**
	 * Each of the three keys is required on its own.
	 *
	 * @return void
	 */
	public function testEachMissingSubjectKeyIsRefused(): void {
		$complete = [
			'event' => 'object.created',
			'register' => 'publications',
			'schema' => 'publication',
		];

		foreach (array_keys($complete) as $missing) {
			$config = $complete;
			unset($config[$missing]);

			try {
				$this->object->validateConfig($config);
				$this->fail(
					sprintf(
						'An object trigger with no "%s" was accepted. It would match nothing and never fire, which is indistinguishable from a flow with nothing to do.',
						$missing
					)
				);
			} catch (InvalidArgumentException $e) {
				// The message has to NAME the missing key: "invalid trigger" sends
				// an author back to a form with three fields and no clue which.
				$this->assertStringContainsString($missing, $e->getMessage());
			}
		}

	}//end testEachMissingSubjectKeyIsRefused()

	/**
	 * An empty string is as absent as a missing key.
	 *
	 * A form that writes '' for an untouched field is the ordinary case, so a
	 * presence check that only tested `isset()` would pass every trigger the UI
	 * could produce.
	 *
	 * @return void
	 */
	public function testAnEmptySubjectValueIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->object->validateConfig(
			[
				'event' => 'object.updated',
				'register' => 'publications',
				'schema' => '   ',
			]
		);

	}//end testAnEmptySubjectValueIsRefused()

	/**
	 * An event the engine never fires is refused.
	 *
	 * `object.published` is the shape of the mistake: a plausible name, spelled
	 * consistently, that nothing in the engine dispatches. Accepted, it becomes
	 * a trigger that is saved, looks right, and can never start anything.
	 *
	 * @return void
	 */
	public function testAnUnknownEventIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->object->validateConfig(
			[
				'event' => 'object.published',
				'register' => 'publications',
				'schema' => 'publication',
			]
		);

	}//end testAnUnknownEventIsRefused()

	/**
	 * All three fired events are accepted.
	 *
	 * @return void
	 */
	public function testEveryFiredEventIsAccepted(): void {
		foreach (TriggerObjectNode::EVENTS as $event) {
			$this->object->validateConfig(
				[
					'event' => $event,
					'register' => 'publications',
					'schema' => 'publication',
				]
			);
		}

		$this->addToAssertionCount(count(TriggerObjectNode::EVENTS));

	}//end testEveryFiredEventIsAccepted()

	/**
	 * The object trigger's vocabulary is exactly its subject.
	 *
	 * Named so the preflight can report a key written in another node's dialect
	 * — which would otherwise be stored, ignored, and reported as healthy.
	 *
	 * @return void
	 */
	public function testTheObjectTriggerNamesItsVocabulary(): void {
		$this->assertSame(['event', 'register', 'schema'], $this->object->configKeys());

	}//end testTheObjectTriggerNamesItsVocabulary()

	/**
	 * A five-field cron expression is accepted; anything else is refused.
	 *
	 * @return void
	 */
	public function testTheScheduleTriggerChecksTheShapeOfItsExpression(): void {
		$this->schedule->validateConfig(['cron' => '*/5 * * * *', 'runAs' => 'alice']);
		$this->addToAssertionCount(1);

		foreach (['', '   ', '*/5 * * *', '*/5 * * * * *', 'every five minutes'] as $bad) {
			try {
				$this->schedule->validateConfig(['cron' => $bad, 'runAs' => 'alice']);
				$this->fail(sprintf('The cron expression "%s" was accepted.', $bad));
			} catch (InvalidArgumentException $e) {
				$this->addToAssertionCount(1);
			}
		}

	}//end testTheScheduleTriggerChecksTheShapeOfItsExpression()

	/**
	 * A schedule trigger that names no acting identity is refused.
	 *
	 * Nobody is present when a schedule fires, so unlike every other trigger it
	 * has no caller to take an identity from. Accepting it produced a flow that
	 * saved cleanly and then either ran as whoever authored it — standing consent
	 * nobody gave — or ran as nobody and was refused one node at a time, reported
	 * as a permissions error (ADR-099).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAScheduleTriggerWithoutAnActingIdentityIsRefused(): void {
		foreach ([null, '', '   '] as $missing) {
			$config = ['cron' => '*/5 * * * *'];
			if ($missing !== null) {
				$config['runAs'] = $missing;
			}

			try {
				$this->schedule->validateConfig($config);
				$this->fail('A schedule trigger with no runAs was accepted.');
			} catch (InvalidArgumentException $e) {
				// Name the key, so an author lands on the right field rather than
				// re-reading a five-field cron expression that was never wrong.
				$this->assertStringContainsString('runAs', $e->getMessage());
			}
		}

	}//end testAScheduleTriggerWithoutAnActingIdentityIsRefused()

	/**
	 * A schedule trigger naming a user that does not exist is refused.
	 *
	 * A uid that resolves to nothing is not an identity. Storing it would defer
	 * the failure to the first firing, where it reads as an outage rather than a
	 * typo.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAScheduleTriggerNamingAnUnknownUserIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/ghost/');

		$this->schedule->validateConfig(['cron' => '*/5 * * * *', 'runAs' => 'ghost']);

	}//end testAScheduleTriggerNamingAnUnknownUserIsRefused()

	/**
	 * The schedule trigger's vocabulary includes the identity it runs as.
	 *
	 * The preflight reports a key written in another node's dialect, so `runAs`
	 * has to be declared here or an author's correctly-spelled identity would be
	 * stored, ignored, and reported as healthy.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testTheScheduleTriggerNamesItsVocabulary(): void {
		$this->assertSame(['cron', 'runAs'], $this->schedule->configKeys());

	}//end testTheScheduleTriggerNamesItsVocabulary()

	/**
	 * A manual trigger accepts no configuration at all.
	 *
	 * @return void
	 */
	public function testTheManualTriggerHasNoVocabulary(): void {
		$this->assertSame([], $this->manual->configKeys());

		$this->manual->validateConfig([]);
		$this->addToAssertionCount(1);

	}//end testTheManualTriggerHasNoVocabulary()

	/**
	 * Every trigger passes its items through untouched.
	 *
	 * A trigger is an entry point, not work: by the time a run exists the
	 * trigger has already fired. A node that dropped or rewrote items here
	 * would silently change what the rest of the flow sees.
	 *
	 * @return void
	 */
	public function testEveryTriggerPassesItemsThrough(): void {
		$items = [['id' => 'a'], ['id' => 'b']];

		$this->assertSame($items, $this->object->execute($items, [], []));
		$this->assertSame($items, $this->schedule->execute($items, [], []));
		$this->assertSame($items, $this->manual->execute($items, [], []));

	}//end testEveryTriggerPassesItemsThrough()

	/**
	 * The three trigger types have distinct ids.
	 *
	 * Two nodes sharing an id means one silently displaces the other in the
	 * registry, and the palette shows a type that resolves to different code
	 * than it names.
	 *
	 * @return void
	 */
	public function testTheTriggerTypesHaveDistinctIds(): void {
		$ids = [
			$this->object->getId(),
			$this->schedule->getId(),
			$this->manual->getId(),
		];

		$this->assertSame($ids, array_unique($ids));
		$this->assertSame('openregister.trigger-object', $ids[0]);

	}//end testTheTriggerTypesHaveDistinctIds()

}//end class
