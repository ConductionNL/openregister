<?php

/**
 * Golden test for the access events: verb declarations, custom-scope verdicts and action outcomes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ActionEvaluatedEvent;
use OCA\OpenRegister\Event\CustomScopeEvaluatedEvent;
use OCA\OpenRegister\Event\CustomScopeEvaluatingEvent;
use OCA\OpenRegister\Event\PermissionsDeclaringEvent;
use PHPUnit\Framework\TestCase;

/**
 * Pins what each access event accepts and refuses, and what it reports.
 */
class AccessEventDeclarationsGoldenTest extends TestCase {

	/**
	 * A verb in the grammar is declared with its string levels only; a bad or taken verb is refused.
	 *
	 * @return void
	 */
	public function testVerbDeclarationsAcceptAndRefuse(): void {
		$event = new PermissionsDeclaringEvent();

		$this->assertTrue($event->declareVerb('sign_off', 'appA', 'Sign off', ['schema', 'object']));
		$this->assertTrue($event->declareVerb('Read-2', 'appB', 'Second read'));
		$this->assertFalse($event->declareVerb('sign_off', 'appC', 'Steal'));
		$this->assertFalse($event->declareVerb('bad verb', 'appD', 'Space'));
		$this->assertFalse($event->declareVerb('', 'appE', 'Empty'));
		$this->assertFalse($event->declareVerb(str_repeat('a', 65), 'appF', 'Long'));
		$this->assertTrue($event->declareVerb(str_repeat('a', 64), 'appF', 'Long enough', []));

		$this->assertSame(
			[
				'sign_off' => ['verb' => 'sign_off', 'app' => 'appA', 'description' => 'Sign off', 'levels' => ['schema', 'object']],
				'Read-2' => ['verb' => 'Read-2', 'app' => 'appB', 'description' => 'Second read', 'levels' => ['register', 'schema', 'object']],
				str_repeat('a', 64) => ['verb' => str_repeat('a', 64), 'app' => 'appF', 'description' => 'Long enough', 'levels' => []],
			],
			$event->getDeclared()
		);
		$this->assertSame(
			[
				'sign_off' => 'The app "appC" declared the verb "sign_off", which "appA" already owns.',
				'bad verb' => 'The app "appD" declared a verb the grammar does not accept.',
				'' => 'The app "appE" declared a verb the grammar does not accept.',
				str_repeat('a', 65) => 'The app "appF" declared a verb the grammar does not accept.',
			],
			$event->getRejected()
		);
	}//end testVerbDeclarationsAcceptAndRefuse()

	/**
	 * The first verdict on a custom scope wins; no verdict stays null.
	 *
	 * @return void
	 */
	public function testCustomScopeFirstVerdictWins(): void {
		$schema = new Schema();
		$object = new ObjectEntity();

		$allowed = new CustomScopeEvaluatingEvent($schema, 'read', 'alice', ['staff'], $object);
		$this->assertFalse($allowed->hasVerdict());
		$this->assertNull($allowed->getVerdict());
		$allowed->allow();
		$allowed->deny();
		$this->assertTrue($allowed->hasVerdict());
		$this->assertTrue($allowed->getVerdict());

		$refused = new CustomScopeEvaluatingEvent($schema, 'update', null, []);
		$refused->deny();
		$refused->allow();
		$this->assertFalse($refused->getVerdict());

		$this->assertSame(
			[$schema, 'read', 'alice', ['staff'], $object],
			[$allowed->getSchema(), $allowed->getAction(), $allowed->getUserId(), $allowed->getUserGroups(), $allowed->getObject()]
		);
		$this->assertSame(
			[$schema, 'update', null, [], null],
			[$refused->getSchema(), $refused->getAction(), $refused->getUserId(), $refused->getUserGroups(), $refused->getObject()]
		);
	}//end testCustomScopeFirstVerdictWins()

	/**
	 * The evaluated event reports exactly what it was given.
	 *
	 * @return void
	 */
	public function testCustomScopeEvaluatedReportsAllowedAndRefused(): void {
		$schema = new Schema();

		$allowed = new CustomScopeEvaluatedEvent($schema, 'read', 'alice', true, true);
		$refused = new CustomScopeEvaluatedEvent($schema, 'delete', null, false, false);

		$this->assertSame(
			[$schema, 'read', 'alice', true, true],
			[$allowed->getSchema(), $allowed->getAction(), $allowed->getUserId(), $allowed->getVerdict(), $allowed->isFromListener()]
		);
		$this->assertSame(
			[$schema, 'delete', null, false, false],
			[$refused->getSchema(), $refused->getAction(), $refused->getUserId(), $refused->getVerdict(), $refused->isFromListener()]
		);
	}//end testCustomScopeEvaluatedReportsAllowedAndRefused()

	/**
	 * An action outcome is granted or refused, with or without an object.
	 *
	 * @return void
	 */
	public function testActionEvaluatedGrantedAndRefused(): void {
		$schema = new Schema();
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setRegister('5');

		$granted = new ActionEvaluatedEvent($schema, 'approve', true, 'alice', $object);
		$refused = new ActionEvaluatedEvent($schema, 'approve', false, null);

		$this->assertSame(
			[$schema, 'approve', true, false, 'alice', $object, '5', 'obj-1'],
			[
				$granted->getSchema(),
				$granted->getAction(),
				$granted->isGranted(),
				$granted->isRefused(),
				$granted->getActor(),
				$granted->getObject(),
				$granted->getRegister(),
				$granted->getObjectId(),
			]
		);
		$this->assertSame(
			[$schema, 'approve', false, true, null, null, null, null],
			[
				$refused->getSchema(),
				$refused->getAction(),
				$refused->isGranted(),
				$refused->isRefused(),
				$refused->getActor(),
				$refused->getObject(),
				$refused->getRegister(),
				$refused->getObjectId(),
			]
		);
	}//end testActionEvaluatedGrantedAndRefused()
}//end class
