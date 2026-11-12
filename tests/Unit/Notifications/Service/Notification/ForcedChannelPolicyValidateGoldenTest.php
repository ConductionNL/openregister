<?php

/**
 * Golden output of ForcedChannelPolicy::validate().
 *
 * Pins the exact refusals (code, message, order) a declaration gets at schema
 * save, for every combination of the three checks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Notifications\Service\Notification\ForcedChannelPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ForcedChannelPolicyValidateGoldenTest extends TestCase {

	private const NO_REASON = [
		'code' => 'notification-forced-channel-without-reason',
		'message' => 'A forced channel needs the reason it is forced: a user who cannot switch a notification off is owed the sentence that says why.',
	];

	private const NO_INTERNAL = [
		'code' => 'notification-internal-only-has-no-internal-channel',
		'message' => 'An internal-only kind declares only channels that can leave the organisation, so it would never send at all.',
	];

	private static function forcesExternal(string $channels): array {
		return [
			'code' => 'notification-internal-only-forces-external-channel',
			'message' => 'An internal-only kind forces ' . $channels
				. ', which can carry it outside the organisation. The two declarations contradict each other.',
		];
	}

	public static function declarations(): array {
		return [
			'empty' => [[], []],
			'plain' => [['channels' => ['email']], []],
			'forced with reason' => [['forcedChannels' => ['channels' => ['email'], 'reason' => ' x ']], []],
			'forced, blank reason' => [['forcedChannels' => ['channels' => ['email'], 'reason' => '   ']], [self::NO_REASON]],
			'shorthand forced' => [['forcedChannels' => ['nc-notification']], [self::NO_REASON]],
			'forced list of non-strings' => [['forcedChannels' => ['channels' => [1, null, ' ']]], []],
			'internal, internal channel' => [['internalOnly' => true, 'channels' => ['nc-notification']], []],
			'internal, truthy not true' => [['internalOnly' => 1, 'channels' => ['email']], []],
			'internal, no channels' => [['internalOnly' => true], [self::NO_INTERNAL]],
			'internal, external only' => [['internalOnly' => true, 'channels' => ['email', 'webhook']], [self::NO_INTERNAL]],
			'internal, forced internal saves usable' => [
				['internalOnly' => true, 'channels' => ['email'], 'forcedChannels' => ['channels' => ['activity'], 'reason' => 'r']],
				[],
			],
			'internal, forced external' => [
				[
					'internalOnly' => true,
					'channels' => ['nc-notification'],
					'forcedChannels' => ['channels' => ['email', 'web-push', 'activity'], 'reason' => 'r'],
				],
				[self::forcesExternal('email, web-push')],
			],
			'all three' => [
				['internalOnly' => true, 'channels' => ['webhook'], 'forcedChannels' => ['channels' => ['email', 'email']]],
				[self::NO_REASON, self::NO_INTERNAL, self::forcesExternal('email')],
			],
		];
	}

	#[DataProvider('declarations')]
	public function testValidateReturnsExactlyTheseRefusals(array $declaration, array $expected): void {
		$this->assertSame($expected, (new ForcedChannelPolicy())->validate($declaration));
	}
}
