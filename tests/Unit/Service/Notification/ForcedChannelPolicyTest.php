<?php

/**
 * The two notification decisions a user cannot overrule, and how they are told.
 *
 * 🔴 AN EMPTY CHANNEL LIST IS EXACTLY THE SHAPE THAT LOOKS LIKE SUCCESS. A
 * kind refused because the recipient is outside the organisation and a kind
 * nobody configured both end with nothing to send on, and a caller that sees
 * only the empty list cannot tell them apart — which matters the day somebody
 * asks why the applicant was never told. The refusal is asserted as a NAMED
 * value here, not as an absence.
 *
 * 🔴 FORCING ADDS, IT DOES NOT REPLACE. A person who also asked for e-mail
 * keeps e-mail; what they cannot do is remove the channel the process
 * requires. A policy that substituted the forced list would quietly take away
 * a channel somebody chose, and the loss would look like a preference that
 * never saved.
 *
 * 🔴 A FORCE WITH NO REASON IS REFUSED AT SAVE, because at send time it is
 * indistinguishable from a bug in the preference merge: the user cannot switch
 * it off and nothing says why.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Notification\ForcedChannelPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The forced-channel and internal-only layer.
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notifications/spec.md
 */
class ForcedChannelPolicyTest extends TestCase {

	private ForcedChannelPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new ForcedChannelPolicy();
	}//end setUp()

	/**
	 * What the preference merge resolved, before this layer.
	 *
	 * @param array<int,string> $channels What the user ended up with.
	 * @param bool              $enabled  Whether they left it on.
	 *
	 * @return array<string,mixed> The resolved preference.
	 */
	private function resolved(array $channels = ['email'], bool $enabled = true): array {
		return ['enabled' => $enabled, 'channels' => $channels, 'source' => 'user-override', 'scope' => 'global'];
	}//end resolved()

	public function testAForcedChannelSurvivesAUserWhoSwitchedItOff(): void {
		$decision = $this->policy->decide(
			$this->resolved([], false),
			['forcedChannels' => ['channels' => ['nc-notification'], 'reason' => 'Awb 4:3a verlangt een ontvangstbevestiging.']]
		);

		$this->assertTrue($decision['enabled']);
		$this->assertSame(['nc-notification'], $decision['channels']);
		$this->assertTrue($decision['forced']);
		$this->assertSame(ForcedChannelPolicy::LAYER, $decision['layer']);
		$this->assertStringContainsString('Awb 4:3a', $decision['reason']);
	}//end testAForcedChannelSurvivesAUserWhoSwitchedItOff()

	public function testForcingAddsToThePreferenceRatherThanReplacingIt(): void {
		$decision = $this->policy->decide(
			$this->resolved(['email']),
			['forcedChannels' => ['channels' => ['nc-notification'], 'reason' => 'proces']]
		);

		// The e-mail somebody chose is still there. Substituting the forced
		// list would take a channel away, and the loss would look like a
		// preference that never saved.
		$this->assertSame(['email', 'nc-notification'], $decision['channels']);
	}//end testForcingAddsToThePreferenceRatherThanReplacingIt()

	public function testAKindNobodyForcedIsLeftExactlyAsTheMergeResolvedIt(): void {
		$decision = $this->policy->decide($this->resolved(['email']), []);

		$this->assertSame(['email'], $decision['channels']);
		$this->assertFalse($decision['forced']);
		$this->assertSame('user-override', $decision['layer'], 'the deciding layer is still the preference merge');
		$this->assertSame('', $decision['refusal']);
	}//end testAKindNobodyForcedIsLeftExactlyAsTheMergeResolvedIt()

	/**
	 * The one the coordinator asked to keep visible: an empty list is the
	 * shape that looks like success.
	 *
	 * @return void
	 */
	public function testAnInternalKindRefusedOutsideReturnsTheRefusalNotAnEmptyList(): void {
		$decision = $this->policy->decide(
			$this->resolved(['email']),
			['internalOnly' => true],
			false
		);

		$this->assertSame(ForcedChannelPolicy::REFUSED_EXTERNAL, $decision['refusal']);
		$this->assertFalse($decision['enabled']);
		// The channels are empty too — which is exactly why the refusal has to
		// carry the reason. A caller reading only this list sees the same
		// bytes as a kind nobody configured.
		$this->assertSame([], $decision['channels']);
		$this->assertNotSame('', $decision['refusal'], 'an absence must never stand in for the refusal');
	}//end testAnInternalKindRefusedOutsideReturnsTheRefusalNotAnEmptyList()

	public function testAKindThatSimplyHasNoChannelsCarriesNoRefusal(): void {
		$decision = $this->policy->decide($this->resolved([]), []);

		// The control for the test above: same empty list, no refusal, and the
		// two are told apart by the refusal alone.
		$this->assertSame([], $decision['channels']);
		$this->assertSame('', $decision['refusal']);
		$this->assertFalse($decision['enabled']);
	}//end testAKindThatSimplyHasNoChannelsCarriesNoRefusal()

	public function testAnInternalKindNeverGoesOutOnAChannelThatCanLeave(): void {
		$decision = $this->policy->decide(
			$this->resolved(['email', 'nc-notification', 'webhook']),
			['internalOnly' => true],
			true
		);

		// Even to somebody inside the organisation: the channel is the leak,
		// not the recipient. A webhook fires at whatever URL an administrator
		// configured.
		$this->assertSame(['nc-notification'], $decision['channels']);
		$this->assertTrue($decision['enabled']);
	}//end testAnInternalKindNeverGoesOutOnAChannelThatCanLeave()

	public function testAnInternalKindWithOnlyExternalChannelsSendsNothing(): void {
		$decision = $this->policy->decide($this->resolved(['email']), ['internalOnly' => true], true);

		$this->assertSame([], $decision['channels']);
		$this->assertFalse($decision['enabled']);
	}//end testAnInternalKindWithOnlyExternalChannelsSendsNothing()

	public function testAForceWithNoReasonIsRefusedAtSave(): void {
		$errors = $this->policy->validate(['forcedChannels' => ['channels' => ['email']]]);

		$this->assertSame(['notification-forced-channel-without-reason'], array_column($errors, 'code'));
	}//end testAForceWithNoReasonIsRefusedAtSave()

	public function testAForceWithAReasonSavesCleanly(): void {
		$this->assertSame(
			[],
			$this->policy->validate(['channels' => ['email'], 'forcedChannels' => ['channels' => ['email'], 'reason' => 'wettelijk']])
		);
	}//end testAForceWithAReasonSavesCleanly()

	public function testAnInternalKindForcingAnExternalChannelIsRefusedAtSave(): void {
		$errors = $this->policy->validate([
			'internalOnly' => true,
			'channels' => ['nc-notification'],
			'forcedChannels' => ['channels' => ['email'], 'reason' => 'proces'],
		]);

		// The two declarations contradict each other, and at send time the
		// contradiction resolves silently into one of them.
		$this->assertContains('notification-internal-only-forces-external-channel', array_column($errors, 'code'));
	}//end testAnInternalKindForcingAnExternalChannelIsRefusedAtSave()

	public function testAnInternalKindWithNoInternalChannelIsRefusedAtSave(): void {
		$errors = $this->policy->validate(['internalOnly' => true, 'channels' => ['email', 'webhook']]);

		// It would never send at all, which at send time is indistinguishable
		// from a kind that is switched off.
		$this->assertContains('notification-internal-only-has-no-internal-channel', array_column($errors, 'code'));
	}//end testAnInternalKindWithNoInternalChannelIsRefusedAtSave()

	/**
	 * The rules reach the validator the schema save actually calls, not only
	 * the policy in isolation.
	 *
	 * A rule that lives in a class nobody wired in is a rule that holds in its
	 * own test and nowhere else, which is the shape this fleet has been bitten
	 * by before.
	 *
	 * @return void
	 */
	public function testTheRulesReachTheValidatorTheSaveCalls(): void {
		$validator = new \OCA\OpenRegister\Service\Notification\NotificationAnnotationValidator();

		$errors = $validator->validate([
			'x-openregister-notifications' => [
				'oplevering' => [
					'trigger' => ['on' => 'created'],
					'recipients' => [['kind' => 'users', 'users' => ['alice']]],
					'channels' => ['email'],
					'forcedChannels' => ['channels' => ['email']],
				],
			],
		]);

		$codes = array_column($errors, 'code');
		$this->assertContains('notification-forced-channel-without-reason', $codes);
	}//end testTheRulesReachTheValidatorTheSaveCalls()

	public function testAPlainDeclarationSavesCleanly(): void {
		$this->assertSame([], $this->policy->validate(['channels' => ['email', 'nc-notification']]));
	}//end testAPlainDeclarationSavesCleanly()

	public function testTheShorthandSpellingOfForcedChannelsIsRead(): void {
		// `forcedChannels: ["nc-notification"]` without the envelope is what a
		// hand-written schema reaches for; it is read, and then refused for
		// having no reason rather than ignored as an unknown shape.
		$errors = $this->policy->validate(['forcedChannels' => ['nc-notification']]);

		$this->assertSame(['notification-forced-channel-without-reason'], array_column($errors, 'code'));
	}//end testTheShorthandSpellingOfForcedChannelsIsRead()
}//end class
