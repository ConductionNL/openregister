<?php

/**
 * Golden test for FlowRecipientAddresses: who a flow message may reach.
 *
 * Pins the allowed AND refused outcomes of screenAddresses() for every
 * external-recipient mode, and the split of a recipient list into users,
 * external addresses and unknown entries, so the class can be broken into
 * smaller units without moving a single recipient across the line.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolverInterface;
use OCA\OpenRegister\Service\Flow\FlowRecipientAddresses;
use PHPUnit\Framework\TestCase;

/**
 * Golden outcomes for recipient resolution and the external-address screen.
 */
class FlowRecipientAddressesGoldenTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var FlowRecipientAddresses
	 */
	private FlowRecipientAddresses $addresses;

	/**
	 * Every `resolve()` call the resolver saw, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $resolveCalls = [];

	/**
	 * Every `userExists()` call the resolver saw, in order.
	 *
	 * @var array<int, string>
	 */
	private array $userChecks = [];

	/**
	 * Wire a resolver that knows user `alice`, user `bob@corp.example`, and group `staff`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$resolver = $this->createMock(NotificationRecipientResolverInterface::class);
		$resolver->method('userExists')->willReturnCallback(function (string $uid): bool {
			$this->userChecks[] = $uid;
			return in_array($uid, ['alice', 'bob@corp.example', 'carol'], true);
		});
		$resolver->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'staff');
		$resolver->method('resolve')->willReturnCallback(function (array $recipientsSpec, array $data): array {
			$this->resolveCalls[] = ['spec' => $recipientsSpec, 'data' => $data];
			if ($recipientsSpec[0]['kind'] === 'groups') {
				return ['g1' => 'dave', 'g2' => 'erin'];
			}

			$found = [];
			foreach ((array)(reset($data) ?? []) as $value) {
				if ($value === 'carol' || $value === 'alice') {
					$found[] = $value;
				}
			}

			return $found;
		});
		$resolver->method('extractUidsFromRelation')->willReturnCallback(static function (mixed $value): array {
			$out = [];
			foreach ((array)$value as $entry) {
				if (is_string($entry) === true) {
					$out[] = $entry;
				}
			}

			return $out;
		});
		$this->addresses = new FlowRecipientAddresses($resolver);

	}//end setUp()

	/**
	 * A batch of candidate addresses, valid, invalid, duplicated and on/off the item.
	 *
	 * @return array<int, array{address: string, name: string}>
	 */
	private function candidates(): array {
		return [
			['address' => ' Ann@Mail.example ', 'name' => 'Ann'],
			['address' => 'not-an-address', 'name' => 'X'],
			['address' => 'ann@mail.example', 'name' => 'Second Ann'],
			['address' => 'off@item.example', 'name' => 'Off'],
			['address' => 'not-an-address', 'name' => 'Y'],
		];

	}//end candidates()

	/**
	 * The item the screen checks against in object mode.
	 *
	 * @return array<string, mixed>
	 */
	private function item(): array {
		return ['contact' => ['primary' => 'ANN@mail.example', 'n' => 3], 'note' => 'no address here', 'x' => null];

	}//end item()

	/**
	 * Mode `any` allows every valid address once, refuses only the invalid one.
	 *
	 * @return void
	 */
	public function testScreenAnyAllowsEveryValidAddress(): void {
		$result = $this->addresses->screenAddresses($this->candidates(), $this->item(), 'any');

		$this->assertSame(['ann@mail.example' => 'Ann', 'off@item.example' => 'Off'], $result['allowed']);
		$this->assertSame(
			['not-an-address|invalid-address' => ['recipient' => 'not-an-address', 'reason' => 'invalid-address']],
			$result['refused']
		);

	}//end testScreenAnyAllowsEveryValidAddress()

	/**
	 * Mode `none` refuses every address, invalid ones as invalid; a later duplicate wins the refusal entry.
	 *
	 * @return void
	 */
	public function testScreenNoneRefusesEveryAddress(): void {
		$result = $this->addresses->screenAddresses($this->candidates(), $this->item(), 'none');

		$this->assertSame([], $result['allowed']);
		$this->assertSame(
			[
				'ann@mail.example|external-recipients-off' => ['recipient' => 'ann@mail.example', 'reason' => 'external-recipients-off'],
				'not-an-address|invalid-address' => ['recipient' => 'not-an-address', 'reason' => 'invalid-address'],
				'off@item.example|external-recipients-off' => ['recipient' => 'off@item.example', 'reason' => 'external-recipients-off'],
			],
			$result['refused']
		);

	}//end testScreenNoneRefusesEveryAddress()

	/**
	 * Mode `object` allows only an address the item itself carries.
	 *
	 * @return void
	 */
	public function testScreenObjectAllowsOnlyAddressesOnTheItem(): void {
		$result = $this->addresses->screenAddresses($this->candidates(), $this->item(), 'object');

		$this->assertSame(['ann@mail.example' => 'Ann'], $result['allowed']);
		$this->assertSame(
			[
				'not-an-address|invalid-address' => ['recipient' => 'not-an-address', 'reason' => 'invalid-address'],
				'off@item.example|not-on-item' => ['recipient' => 'off@item.example', 'reason' => 'not-on-item'],
			],
			$result['refused']
		);

		// Mutation check: the same address becomes allowed the moment the item carries it.
		$onItem = $this->addresses->screenAddresses(
			[['address' => 'off@item.example', 'name' => 'Off']],
			['deep' => [['to' => ' Off@Item.Example ']]],
			'object'
		);
		$this->assertSame(['off@item.example' => 'Off'], $onItem['allowed']);
		$this->assertSame([], $onItem['refused']);

		// A scalar item carries nothing.
		$scalar = $this->addresses->screenAddresses([['address' => 'a@b.example', 'name' => '']], ['n' => 1], 'object');
		$this->assertSame([], $scalar['allowed']);
		$this->assertSame(['a@b.example|not-on-item'], array_keys($scalar['refused']));

	}//end testScreenObjectAllowsOnlyAddressesOnTheItem()

	/**
	 * An unknown or missing mode falls back to the closed mode.
	 *
	 * @return void
	 */
	public function testExternalRecipientModeFallsBackClosed(): void {
		$this->assertSame('any', $this->addresses->externalRecipientMode(['externalRecipients' => ' ANY ']));
		$this->assertSame('object', $this->addresses->externalRecipientMode(['externalRecipients' => 'object']));
		$this->assertSame('none', $this->addresses->externalRecipientMode(['externalRecipients' => 'open']));
		$this->assertSame('none', $this->addresses->externalRecipientMode([]));

	}//end testExternalRecipientModeFallsBackClosed()

	/**
	 * Literal entries without address acceptance: users, groups, unknowns; addresses are unknown.
	 *
	 * @return void
	 */
	public function testResolveRecipientsLiteralsWithoutAddresses(): void {
		$result = $this->addresses->resolveRecipients(
			[' alice ', 'staff', 'x@y.example', '', '   ', 42, 'alice', 'ghost', 'ghost'],
			[],
			false
		);

		$this->assertSame(['alice', 'dave', 'erin'], $result['uids']);
		$this->assertSame([], $result['addresses']);
		$this->assertSame(['x@y.example', 'ghost'], $result['unknown']);

		$single = $this->addresses->resolveRecipients('alice', []);
		$this->assertSame(['alice'], $single['uids']);
		$this->assertSame([], $this->addresses->resolveRecipients(null, [])['uids']);

	}//end testResolveRecipientsLiteralsWithoutAddresses()

	/**
	 * Literal and templated entries with address acceptance.
	 *
	 * @return void
	 */
	public function testResolveRecipientsWithAddresses(): void {
		$json = [
			'owner' => ['carol', ' out@side.example ', 'bob@corp.example', ['email' => ' p@q.example ', 'displayName' => 'P'], ['userId' => 'carol', 'email' => 'c@q.example'], ['email' => '  '], ['name' => 'nobody'], 7],
			'single' => 'solo@side.example',
			'map' => ['emailAddress' => 'm@q.example', 'name' => 'M'],
			'usermap' => ['uid' => 'alice'],
			'plainmap' => ['foo' => 'bar'],
			'number' => 12,
		];

		$result = $this->addresses->resolveRecipients(
			['x@y.example', '{{ item.owner }}', '{{single}}', '{{ map }}', '{{usermap}}', '{{plainmap}}', '{{number}}', '{{missing}}'],
			$json,
			true
		);

		$this->assertSame(['carol'], $result['uids']);
		$this->assertSame(
			[
				['address' => 'x@y.example', 'name' => ''],
				['address' => 'out@side.example', 'name' => ''],
				['address' => 'p@q.example', 'name' => 'P'],
				['address' => 'solo@side.example', 'name' => ''],
				['address' => 'm@q.example', 'name' => 'M'],
			],
			$result['addresses']
		);
		$this->assertSame(['bob@corp.example', 'bar'], $result['unknown']);

		// The `rest` each template handed the resolver, which decides who is a user.
		$this->assertSame(
			[
				['owner' => ['carol', 'bob@corp.example', ['userId' => 'carol', 'email' => 'c@q.example'], ['email' => '  '], ['name' => 'nobody'], 7]],
				['single' => []],
				['map' => []],
				['usermap' => [['uid' => 'alice']]],
				['plainmap' => ['bar']],
				['number' => 12],
				['missing' => null],
			],
			array_column($this->resolveCalls, 'data')
		);
		$this->assertSame(
			['x@y.example', 'out@side.example', 'bob@corp.example', 'solo@side.example'],
			array_values(array_filter($this->userChecks, static fn (string $uid): bool => str_contains($uid, '@')))
		);

	}//end testResolveRecipientsWithAddresses()
}//end class
