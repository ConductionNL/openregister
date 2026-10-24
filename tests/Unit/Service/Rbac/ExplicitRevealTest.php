<?php

/**
 * An explicit-reveal property reads as its mask on the objects API until the
 * request names it, and a write that sends the mask back keeps the value.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ConditionMatcher;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use OCA\OpenRegister\Service\Rbac\RevealCollector;
use OCA\OpenRegister\Tests\Support\BuildsStateFieldRuleResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ExplicitReveal
 * @covers \OCA\OpenRegister\Service\PropertyRbacHandler
 */
class ExplicitRevealTest extends TestCase {
	use BuildsStateFieldRuleResolver;

	/**
	 * A person schema whose BSN is read-restricted, audited and explicit-reveal.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('brpPerson');
		$schema->setProperties([
			'displayName' => ['type' => 'string'],
			'citizenServiceNumber' => [
				'type' => 'string',
				'authorization' => ['read' => [['group' => 'sensitive']], 'audit' => true, 'reveal' => 'explicit'],
			],
		]);

		return $schema;
	}//end schema()

	/**
	 * A handler for a caller in these groups, over this masking state.
	 *
	 * @param array<int, string> $groups   The caller's groups.
	 * @param ExplicitReveal     $reveal   The request's masking state.
	 * @param RevealCollector    $reveals  The reveal collector.
	 *
	 * @return PropertyRbacHandler The handler.
	 */
	private function handler(array $groups, ExplicitReveal $reveal, RevealCollector $reveals): PropertyRbacHandler {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);
		$groupManager->method('isAdmin')->willReturn(false);

		return new PropertyRbacHandler(
			$session,
			$groupManager,
			$this->createMock(ConditionMatcher::class),
			$this->createMock(LoggerInterface::class),
			self::stateFieldRuleResolver($session, $groupManager),
			$reveals,
			$reveal
		);
	}//end handler()

	/**
	 * The mask keeps the last four characters.
	 *
	 * @return void
	 */
	public function testTheMaskKeepsTheLastFour(): void {
		$this->assertSame('*****7609', (new ExplicitReveal())->mask(value: '123457609'));
		$this->assertSame('', (new ExplicitReveal())->mask(value: ''));
	}//end testTheMaskKeepsTheLastFour()

	/**
	 * On the objects API the reader with read rights gets the mask, and no reveal is recorded.
	 *
	 * @return void
	 */
	public function testAMaskedReadShowsTheMaskAndRecordsNothing(): void {
		$reveal = new ExplicitReveal();
		$reveal->open();
		$reveals = new RevealCollector();

		$out = $this->handler(['sensitive'], $reveal, $reveals)->filterReadableProperties(
			schema: $this->schema(),
			object: ['id' => 'p-1', 'displayName' => 'Sanne de Vries', 'citizenServiceNumber' => '123457609']
		);

		$this->assertSame('*****7609', $out['citizenServiceNumber']);
		$this->assertSame(0, $reveals->count());
	}//end testAMaskedReadShowsTheMaskAndRecordsNothing()

	/**
	 * A read that names the property gets the value, and the reveal is recorded.
	 *
	 * @return void
	 */
	public function testANamedReadRevealsAndRecords(): void {
		$reveal = new ExplicitReveal();
		$reveal->open(reveal: 'citizenServiceNumber');
		$reveals = new RevealCollector();

		$out = $this->handler(['sensitive'], $reveal, $reveals)->filterReadableProperties(
			schema: $this->schema(),
			object: ['id' => 'p-1', 'citizenServiceNumber' => '123457609']
		);

		$this->assertSame('123457609', $out['citizenServiceNumber']);
		$this->assertSame(1, $reveals->count());
	}//end testANamedReadRevealsAndRecords()

	/**
	 * Naming the property gives nothing to a reader without read rights: the
	 * permission check runs before the reveal.
	 *
	 * @return void
	 */
	public function testNamingItGivesNothingWithoutReadRights(): void {
		$reveal = new ExplicitReveal();
		$reveal->open(reveal: 'citizenServiceNumber');
		$reveals = new RevealCollector();

		$out = $this->handler(['frontdesk'], $reveal, $reveals)->filterReadableProperties(
			schema: $this->schema(),
			object: ['id' => 'p-1', 'citizenServiceNumber' => '123457609']
		);

		$this->assertArrayNotHasKey('citizenServiceNumber', $out);
		$this->assertSame(0, $reveals->count());
	}//end testNamingItGivesNothingWithoutReadRights()

	/**
	 * Off the objects API (an app's server code) nothing is masked.
	 *
	 * @return void
	 */
	public function testServerReadsAreNotMasked(): void {
		$out = $this->handler(['sensitive'], new ExplicitReveal(), new RevealCollector())->filterReadableProperties(
			schema: $this->schema(),
			object: ['id' => 'p-1', 'citizenServiceNumber' => '123457609']
		);

		$this->assertSame('123457609', $out['citizenServiceNumber']);
	}//end testServerReadsAreNotMasked()

	/**
	 * An update that sends the mask back keeps the stored value; a new value is written.
	 *
	 * @return void
	 */
	public function testAnUpdateSendingTheMaskKeepsTheValue(): void {
		$reveal = new ExplicitReveal();
		$stored = ['citizenServiceNumber' => '123457609'];

		$kept = $reveal->restoreMasked(schema: $this->schema(), incoming: ['citizenServiceNumber' => '*****7609'], stored: $stored);
		$this->assertSame('123457609', $kept['citizenServiceNumber']);

		$changed = $reveal->restoreMasked(schema: $this->schema(), incoming: ['citizenServiceNumber' => '999990000'], stored: $stored);
		$this->assertSame('999990000', $changed['citizenServiceNumber']);
	}//end testAnUpdateSendingTheMaskKeepsTheValue()
}//end class
