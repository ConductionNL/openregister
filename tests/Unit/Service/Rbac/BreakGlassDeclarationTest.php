<?php

/**
 * The break-glass declaration of a schema: what it offers and what it refuses (REQ-GSR-004).
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
 * @spec openspec/changes/grants-that-follow-a-slot-a-relation-or-a-reason/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Reading and checking `x-openregister-break-glass`.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\BreakGlassDeclaration
 */
class BreakGlassDeclarationTest extends TestCase {

	/**
	 * A valid declaration: read for four hours, the team lead told.
	 *
	 * @return array<string, mixed> The block.
	 */
	private static function valid(): array {
		return ['verbs' => ['read'], 'maxDuration' => 'PT4H', 'notify' => ['users' => ['teamlead']]];
	}//end valid()

	/**
	 * A schema carrying the block in its configuration.
	 *
	 * @param mixed $block The block, or null for none.
	 *
	 * @return Schema The schema.
	 */
	private static function schemaWith(mixed $block): Schema {
		$schema = new Schema();
		$configuration = ['other' => true];
		if ($block !== null) {
			$configuration[BreakGlassDeclaration::ANNOTATION] = $block;
		}

		$schema->setConfiguration($configuration);

		return $schema;
	}//end schemaWith()

	/**
	 * A valid block reads back as its verbs, its period in seconds and its recipients.
	 *
	 * @return void
	 */
	public function testAValidDeclarationReadsBack(): void {
		$block = self::valid();
		$block['notify']['groups'] = ['beheer', ' beheer '];
		$block['groups'] = ['balie'];

		$declaration = BreakGlassDeclaration::fromSchema(schema: self::schemaWith(block: $block));

		$this->assertNotNull($declaration);
		$this->assertSame(['read'], $declaration->verbs);
		$this->assertSame(4 * 3600, $declaration->durationSeconds);
		$this->assertSame(['teamlead'], $declaration->notifyUsers);
		$this->assertSame(['beheer'], $declaration->notifyGroups);
		$this->assertSame(['balie'], $declaration->takerGroups);
		$this->assertSame([], BreakGlassDeclaration::errorsOf(block: $block));
	}//end testAValidDeclarationReadsBack()

	/**
	 * A schema without the key, or with no schema at all, offers nothing.
	 *
	 * @return void
	 */
	public function testNoDeclarationOffersNothing(): void {
		$this->assertNull(BreakGlassDeclaration::fromSchema(schema: self::schemaWith(block: null)));
		$this->assertNull(BreakGlassDeclaration::fromSchema(schema: null));
		$this->assertNull(BreakGlassDeclaration::fromSchema(schema: new Schema()));
	}//end testNoDeclarationOffersNothing()

	/**
	 * `manage` can never be granted: it is not a route to administration.
	 *
	 * @return void
	 */
	public function testManageIsRefused(): void {
		$block = self::valid();
		$block['verbs'] = ['read', 'manage'];

		$errors = BreakGlassDeclaration::errorsOf(block: $block);

		$this->assertCount(1, $errors);
		$this->assertStringContainsString('manage', $errors[0]);
		$this->assertNull(BreakGlassDeclaration::fromBlock(block: $block));
	}//end testManageIsRefused()

	/**
	 * A declaration nobody would hear of is refused.
	 *
	 * @return void
	 */
	public function testARecipientIsRequired(): void {
		foreach ([null, [], ['users' => []], ['users' => [''], 'groups' => []], ['users' => 'teamlead']] as $notify) {
			$block = self::valid();
			$block['notify'] = $notify;

			$this->assertSame(
				['`notify` must name at least one user or group in `users` or `groups`'],
				BreakGlassDeclaration::errorsOf(block: $block),
				json_encode($notify)
			);
		}

		$block = self::valid();
		$block['notify'] = ['groups' => ['beheer']];
		$this->assertSame([], BreakGlassDeclaration::errorsOf(block: $block));
	}//end testARecipientIsRequired()

	/**
	 * A duration must be a positive ISO-8601 duration.
	 *
	 * @return void
	 */
	public function testTheDurationMustBePositiveIso(): void {
		foreach (['4 hours', '', 'PT0S', 14400, null] as $duration) {
			$block = self::valid();
			$block['maxDuration'] = $duration;

			$this->assertSame(
				['`maxDuration` must be a positive ISO-8601 duration such as PT4H'],
				BreakGlassDeclaration::errorsOf(block: $block),
				var_export($duration, true)
			);
		}

		$this->assertSame(90 * 60, BreakGlassDeclaration::durationSecondsOf(value: 'PT1H30M'));
		$this->assertSame(86400, BreakGlassDeclaration::durationSecondsOf(value: ' P1D '));
	}//end testTheDurationMustBePositiveIso()

	/**
	 * Verbs must be a non-empty list of names; taker groups a list of ids; the block an object.
	 *
	 * @return void
	 */
	public function testShapeFaultsAreNamed(): void {
		$this->assertSame(['the declaration must be an object'], BreakGlassDeclaration::errorsOf(block: ['read']));
		$this->assertSame(['the declaration must be an object'], BreakGlassDeclaration::errorsOf(block: 'yes'));

		$block = self::valid();
		$block['verbs'] = [];
		$this->assertSame(['`verbs` must be a non-empty list of verbs'], BreakGlassDeclaration::errorsOf(block: $block));

		$block = self::valid();
		$block['groups'] = 'balie';
		$this->assertSame(['`groups` must be a list of group ids'], BreakGlassDeclaration::errorsOf(block: $block));
	}//end testShapeFaultsAreNamed()

	/**
	 * Only the declared verbs are granted.
	 *
	 * @return void
	 */
	public function testOnlyTheDeclaredVerbsAreGranted(): void {
		$declaration = new BreakGlassDeclaration(verbs: ['read'], durationSeconds: 60, notifyUsers: ['teamlead']);

		$this->assertTrue($declaration->grants(verb: 'read'));
		$this->assertFalse($declaration->grants(verb: 'update'));
		$this->assertFalse($declaration->grants(verb: 'manage'));
	}//end testOnlyTheDeclaredVerbsAreGranted()

	/**
	 * Without taker groups anybody may take it; with them only their members.
	 *
	 * @return void
	 */
	public function testTakerGroupsNarrowWhoMayTakeIt(): void {
		$open = new BreakGlassDeclaration(verbs: ['read'], durationSeconds: 60, notifyUsers: ['teamlead']);
		$this->assertTrue($open->mayBeTakenBy(callerGroups: []));

		$narrow = new BreakGlassDeclaration(verbs: ['read'], durationSeconds: 60, notifyUsers: ['teamlead'], takerGroups: ['balie']);
		$this->assertTrue($narrow->mayBeTakenBy(callerGroups: ['medewerkers', 'balie']));
		$this->assertFalse($narrow->mayBeTakenBy(callerGroups: ['medewerkers']));
	}//end testTakerGroupsNarrowWhoMayTakeIt()
}//end class
