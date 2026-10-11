<?php

/**
 * Tests for the `email` recipient kind: an address read through a reference.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifier;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
 */
class ReferencedAddressNotifierTest extends TestCase {

	/**
	 * The object layer, for the referenced object.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objects;

	/**
	 * The email channel.
	 *
	 * @var EmailSender&MockObject
	 */
	private $email;

	/**
	 * The opt-out authority.
	 *
	 * @var OptOutAuthority&MockObject
	 */
	private $optOut;

	/**
	 * What reached the mailer.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Referenced objects by id.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $store = [];

	/**
	 * Build the notifier on doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectService::class);
		$this->objects->method('find')->willReturnCallback(
			fn (int|string $id): ?ObjectEntity => ($this->store[(string)$id] ?? null)
		);
		$this->email = $this->createMock(EmailSender::class);
		$this->email->method('sendToAddress')->willReturnCallback(
			function (string $address, string $displayName, string $subject, string $body, ?array $unsubscribe = null): string {
				$this->sent[] = ['address' => $address, 'subject' => $subject, 'body' => $body, 'unsubscribe' => $unsubscribe];
				return EmailSender::OUTCOME_DISPATCHED;
			}
		);
		$this->optOut = $this->createMock(OptOutAuthority::class);
		$this->optOut->method('withLink')->willReturnCallback(
			static fn (string $body, ?array $unsubscribe): string => $body . (($unsubscribe['url'] ?? '') !== '' ? "\n\nstop: " . $unsubscribe['url'] : '')
		);
		$this->optOut->method('decisionFor')->willReturnCallback(
			static fn (array $decisions, string $address): array => ($decisions[$address] ?? ['send' => false, 'code' => 'authority-unavailable', 'unsubscribe' => null])
		);
	}//end setUp()

	/**
	 * The notifier under test.
	 *
	 * @return ReferencedAddressNotifier
	 */
	private function notifier(): ReferencedAddressNotifier {
		return new ReferencedAddressNotifier(
			objects: $this->objects,
			email: $this->email,
			optOut: $this->optOut,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end notifier()

	/**
	 * A stored object.
	 *
	 * @param string               $uuid         Its uuid.
	 * @param array<string, mixed> $data         Its data.
	 * @param string|null          $organisation Its organisation.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $data, ?string $organisation = 'org-1'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setObject($data);
		$object->setOrganisation($organisation);
		return $object;
	}//end object()

	/**
	 * integriq allows every address it is asked about.
	 *
	 * @return void
	 */
	private function integriqAllows(): void {
		$this->optOut->method('ask')->willReturnCallback(
			static function (string $channel, string $category, array $addresses): array {
				$decisions = [];
				foreach ($addresses as $address) {
					$decisions[$address] = ['send' => true, 'code' => 'allowed', 'unsubscribe' => ['url' => 'https://nc.example/u/1']];
				}

				return $decisions;
			}
		);
	}//end integriqAllows()

	/**
	 * The address is read from the referenced object and mailed with the
	 * rule's subject and body plus integriq's link.
	 *
	 * @return void
	 */
	public function testTheAddressIsReadThroughTheReferenceAndMailed(): void {
		$this->integriqAllows();
		$this->store['sup-1'] = $this->object('sup-1', ['name' => 'Bouw BV', 'contactEmail' => 'info@bouw.example']);
		$message = $this->object('msg-1', ['supplierRef' => 'sup-1', 'subject' => 'Hallo']);

		$outcomes = $this->notifier()->notify(
			object: $message,
			data: $message->getObject(),
			recipientsSpec: [['kind' => 'email', 'field' => 'supplierRef.contactEmail']],
			subject: 'Nieuw bericht',
			body: 'U heeft een nieuw bericht.',
			category: 'service'
		);

		$this->assertSame([['field' => 'supplierRef.contactEmail', 'outcome' => EmailSender::OUTCOME_DISPATCHED]], $outcomes);
		$this->assertCount(1, $this->sent);
		$this->assertSame('info@bouw.example', $this->sent[0]['address']);
		$this->assertSame('Nieuw bericht', $this->sent[0]['subject']);
		$this->assertStringContainsString('stop: https://nc.example/u/1', $this->sent[0]['body']);
	}//end testTheAddressIsReadThroughTheReferenceAndMailed()

	/**
	 * A field without a dot reads the address from the object itself, and a
	 * reference stored as an object (`{id: ...}`) is followed too.
	 *
	 * @return void
	 */
	public function testAPlainFieldAndAnObjectShapedReferenceBothResolve(): void {
		$this->integriqAllows();
		$this->store['sup-2'] = $this->object('sup-2', ['contactEmail' => 'b@example.org']);
		$object = $this->object('x-1', ['email' => 'a@example.org', 'supplier' => ['id' => 'sup-2']]);

		$notifier = $this->notifier();
		$this->assertSame('a@example.org', $notifier->resolveAddress(object: $object, data: $object->getObject(), path: 'email')['address']);
		$this->assertSame('b@example.org', $notifier->resolveAddress(object: $object, data: $object->getObject(), path: 'supplier.contactEmail')['address']);
	}//end testAPlainFieldAndAnObjectShapedReferenceBothResolve()

	/**
	 * Every way the address can fail to resolve is named, and nothing is sent.
	 *
	 * @return void
	 */
	public function testAnUnresolvableAddressIsNamedAndNothingIsSent(): void {
		$this->integriqAllows();
		$this->store['sup-other'] = $this->object('sup-other', ['contactEmail' => 'x@other.example'], 'org-2');
		$this->store['sup-bad'] = $this->object('sup-bad', ['contactEmail' => 'not an address']);
		$object = $this->object('m', ['empty' => '', 'missingRef' => 'gone', 'foreign' => 'sup-other', 'bad' => 'sup-bad']);
		$notifier = $this->notifier();

		$cases = [
			'empty.contactEmail' => ReferencedAddressNotifier::REASON_NO_REFERENCE,
			'missingRef.contactEmail' => ReferencedAddressNotifier::REASON_REFERENCE_NOT_FOUND,
			'foreign.contactEmail' => ReferencedAddressNotifier::REASON_OTHER_TENANT,
			'bad.contactEmail' => ReferencedAddressNotifier::REASON_NOT_AN_ADDRESS,
			'nothing' => ReferencedAddressNotifier::REASON_NO_REFERENCE,
		];
		foreach ($cases as $path => $reason) {
			$resolved = $notifier->resolveAddress(object: $object, data: $object->getObject(), path: $path);
			$this->assertNull($resolved['address'], $path);
			$this->assertSame($reason, $resolved['reason'], $path);
		}

		// An object without an organisation may not reach into a tenant, and
		// any object may read a referenced object that has no organisation.
		$this->store['global'] = $this->object('global', ['contactEmail' => 'g@example.org'], null);
		$untenanted = $this->object('u', ['ref' => 'sup-other', 'glob' => 'global'], null);
		$this->assertSame(ReferencedAddressNotifier::REASON_OTHER_TENANT, $notifier->resolveAddress(object: $untenanted, data: $untenanted->getObject(), path: 'ref.contactEmail')['reason']);
		$this->assertSame('g@example.org', $notifier->resolveAddress(object: $object, data: ['glob' => 'global'], path: 'glob.contactEmail')['address']);

		$outcomes = $notifier->notify(
			object: $object,
			data: $object->getObject(),
			recipientsSpec: [['kind' => 'email', 'field' => 'foreign.contactEmail']],
			subject: 's',
			body: 'b',
			category: 'service'
		);
		$this->assertSame([['field' => 'foreign.contactEmail', 'outcome' => ReferencedAddressNotifier::REASON_OTHER_TENANT]], $outcomes);
		$this->assertSame([], $this->sent);
	}//end testAnUnresolvableAddressIsNamedAndNothingIsSent()

	/**
	 * An opted-out address is not mailed, and an absent integriq reads as
	 * authority-unavailable, never as allowed.
	 *
	 * @return void
	 */
	public function testIntegriqRefusalsAreRespected(): void {
		$this->optOut->method('ask')->willReturnCallback(
			static fn (string $channel, string $category, array $addresses): array => [
				'out@example.org' => ['send' => false, 'code' => 'opted-out', 'unsubscribe' => null],
			]
		);
		$this->store['a'] = $this->object('a', ['contactEmail' => 'out@example.org']);
		$this->store['b'] = $this->object('b', ['contactEmail' => 'silent@example.org']);
		$object = $this->object('m', ['refA' => 'a', 'refB' => 'b']);

		$outcomes = $this->notifier()->notify(
			object: $object,
			data: $object->getObject(),
			recipientsSpec: [
				['kind' => 'email', 'field' => 'refA.contactEmail'],
				['kind' => 'parties'],
				['kind' => 'email', 'field' => 'refB.contactEmail'],
			],
			subject: 's',
			body: 'b',
			category: 'service'
		);

		$this->assertSame(
			[
				['field' => 'refA.contactEmail', 'outcome' => ReferencedAddressNotifier::OUTCOME_REFUSED_OPTED_OUT],
				['field' => 'refB.contactEmail', 'outcome' => ReferencedAddressNotifier::OUTCOME_AUTHORITY_UNAVAILABLE],
			],
			$outcomes
		);
		$this->assertSame([], $this->sent);
	}//end testIntegriqRefusalsAreRespected()

	/**
	 * A rule without an `email` entry asks nobody and sends nothing.
	 *
	 * @return void
	 */
	public function testARuleWithoutEmailEntriesDoesNothing(): void {
		$this->optOut->expects($this->never())->method('ask');
		$object = $this->object('m', []);
		$this->assertSame(
			[],
			$this->notifier()->notify(object: $object, data: [], recipientsSpec: [['kind' => 'users', 'users' => ['a']]], subject: 's', body: 'b', category: 'service')
		);
	}//end testARuleWithoutEmailEntriesDoesNothing()
}//end class
