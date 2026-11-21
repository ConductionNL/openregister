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
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Service\Notification\AddressDeliveryServices;
use OCA\OpenRegister\Service\Notification\MailingRequest;
use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifier;
use OCA\OpenRegister\Service\Notification\ReferenceLookupServices;
use OCA\OpenRegister\Service\Notification\ReferenceOrigin;
use OCA\OpenRegister\Service\Object\SaveObject\RelationCascadeHandler;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutQuestion;

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
	 * Resolves a `$ref` to a schema id.
	 *
	 * @var RelationCascadeHandler&MockObject
	 */
	private $refs;

	/**
	 * The schema mapper, for the referenced objects' own properties.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemas;

	/**
	 * The schema every `$ref` resolves to in these tests: id 7, slug `supplier`.
	 */
	private const SUPPLIER_SCHEMA = '7';

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
		$this->schemas = $this->createMock(SchemaMapper::class);
		$this->schemas->method('find')->willReturnCallback(
			fn (int|string $id): Schema => $this->schema(['next' => ['type' => 'string', '$ref' => '#/components/schemas/supplier']])
		);
		$this->email = $this->createMock(EmailSender::class);
		$this->email->method('sendMailTo')->willReturnCallback(
			function (\OCA\OpenRegister\Notifications\Service\Notification\MailAddressee $to, \OCA\OpenRegister\Notifications\Service\Notification\MailContent $mail): string {
				$this->sent[] = ['address' => $to->address, 'subject' => $mail->subject, 'body' => $mail->body, 'unsubscribe' => $mail->unsubscribe];
				return EmailSender::OUTCOME_DISPATCHED;
			}
		);
		$this->refs = $this->createMock(RelationCascadeHandler::class);
		$this->refs->method('resolveSchemaReference')->willReturnCallback(
			// Only a system-side lookup resolves: a caller-scoped one would not see the schema of an anonymous submit.
			static fn (string $reference, array $scope = []): ?string => (($scope === RelationCascadeHandler::SYSTEM_SCOPE && $reference === '#/components/schemas/supplier') ? self::SUPPLIER_SCHEMA : null)
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
			lookups: new ReferenceLookupServices(objects: $this->objects, schemas: $this->schemas, refs: $this->refs),
			delivery: new AddressDeliveryServices(email: $this->email, optOut: $this->optOut),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end notifier()

	/**
	 * A schema with the given properties.
	 *
	 * @param array<string, mixed> $properties Its properties.
	 *
	 * @return Schema
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		return $schema;
	}//end schema()

	/**
	 * The triggering object's schema: every property named here is a reference to a supplier.
	 *
	 * @param array<int, string> $properties The reference properties.
	 *
	 * @return Schema
	 */
	private function referencing(array $properties): Schema {
		$declared = [];
		foreach ($properties as $property) {
			$declared[$property] = ['type' => 'string', '$ref' => '#/components/schemas/supplier'];
		}

		return $this->schema($declared);
	}//end referencing()

	/**
	 * A stored object.
	 *
	 * @param string               $uuid         Its uuid.
	 * @param array<string, mixed> $data         Its data.
	 * @param string|null          $organisation Its organisation.
	 * @param string               $schema       Its schema id.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $data, ?string $organisation = 'org-1', string $schema = self::SUPPLIER_SCHEMA): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setSchema($schema);
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
		$this->optOut->method('askQuestion')->willReturnCallback(
			static function (OptOutQuestion $question): array {
				$decisions = [];
				foreach ($question->addresses as $address) {
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

		$outcomes = $this->notifier()->mail(
			origin: new ReferenceOrigin(object: $message, data: $message->getObject(), schema: $this->referencing(['supplierRef'])),
			request: new MailingRequest(
				recipients: [['kind' => 'email', 'field' => 'supplierRef.contactEmail']],
				subject: 'Nieuw bericht',
				body: 'U heeft een nieuw bericht.',
				category: 'service'
			)
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
		$this->assertSame('a@example.org', $notifier->resolveAddress(origin: new ReferenceOrigin(object: $object, data: $object->getObject()), path: 'email')['address']);
		$this->assertSame(
			'b@example.org',
			$notifier->resolveAddress(origin: new ReferenceOrigin(object: $object, data: $object->getObject(), schema: $this->referencing(['supplier'])), path: 'supplier.contactEmail')['address']
		);
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
		$schema = $this->referencing(['empty', 'missingRef', 'foreign', 'bad', 'ref', 'glob']);

		$cases = [
			'empty.contactEmail' => ReferencedAddressNotifier::REASON_NO_REFERENCE,
			'missingRef.contactEmail' => ReferencedAddressNotifier::REASON_REFERENCE_NOT_FOUND,
			'foreign.contactEmail' => ReferencedAddressNotifier::REASON_OTHER_TENANT,
			'bad.contactEmail' => ReferencedAddressNotifier::REASON_NOT_AN_ADDRESS,
			'nothing' => ReferencedAddressNotifier::REASON_NO_REFERENCE,
		];
		foreach ($cases as $path => $reason) {
			$resolved = $notifier->resolveAddress(origin: new ReferenceOrigin(object: $object, data: $object->getObject(), schema: $schema), path: $path);
			$this->assertNull($resolved['address'], $path);
			$this->assertSame($reason, $resolved['reason'], $path);
		}

		// An object without an organisation may not reach into a tenant, and
		// any object may read a referenced object that has no organisation.
		$this->store['global'] = $this->object('global', ['contactEmail' => 'g@example.org'], null);
		$untenanted = $this->object('u', ['ref' => 'sup-other', 'glob' => 'global'], null);
		$this->assertSame(ReferencedAddressNotifier::REASON_OTHER_TENANT, $notifier->resolveAddress(origin: new ReferenceOrigin(object: $untenanted, data: $untenanted->getObject(), schema: $schema), path: 'ref.contactEmail')['reason']);
		$this->assertSame('g@example.org', $notifier->resolveAddress(origin: new ReferenceOrigin(object: $object, data: ['glob' => 'global'], schema: $schema), path: 'glob.contactEmail')['address']);

		$outcomes = $notifier->mail(
			origin: new ReferenceOrigin(object: $object, data: $object->getObject(), schema: $schema),
			request: new MailingRequest(
				recipients: [['kind' => 'email', 'field' => 'foreign.contactEmail']],
				subject: 's',
				body: 'b',
				category: 'service'
			)
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
		$this->optOut->method('askQuestion')->willReturnCallback(
			static fn (OptOutQuestion $question): array => [
				'out@example.org' => ['send' => false, 'code' => 'opted-out', 'unsubscribe' => null],
			]
		);
		$this->store['a'] = $this->object('a', ['contactEmail' => 'out@example.org']);
		$this->store['b'] = $this->object('b', ['contactEmail' => 'silent@example.org']);
		$object = $this->object('m', ['refA' => 'a', 'refB' => 'b']);

		$outcomes = $this->notifier()->mail(
			origin: new ReferenceOrigin(object: $object, data: $object->getObject(), schema: $this->referencing(['refA', 'refB'])),
			request: new MailingRequest(recipients: [
				['kind' => 'email', 'field' => 'refA.contactEmail'],
				['kind' => 'parties'],
				['kind' => 'email', 'field' => 'refB.contactEmail'],
			], subject: 's', body: 'b', category: 'service')
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
		$this->optOut->expects($this->never())->method('askQuestion');
		$object = $this->object('m', []);
		$this->assertSame(
			[],
			$this->notifier()->mail(
				origin: new ReferenceOrigin(object: $object, data: []),
				request: new MailingRequest(recipients: [['kind' => 'users', 'users' => ['a']]], subject: 's', body: 'b', category: 'service')
			)
		);
	}//end testARuleWithoutEmailEntriesDoesNothing()

	/**
	 * A reference to an object of another schema than the property points at
	 * is refused as not found, and nothing is sent: the reference value is
	 * data the person saving the triggering object controls, so it must not
	 * reach any same-organisation object that happens to carry the field.
	 *
	 * @return void
	 */
	public function testAReferenceToAnObjectOfAnotherSchemaIsRefusedAndNothingIsSent(): void {
		$this->optOut->method('askQuestion')->willReturnCallback(
			function (OptOutQuestion $question): array {
				$this->assertSame([], $question->addresses, 'no address of the wrong object may be put to integriq');
				return [];
			}
		);
		$this->store['staff-1'] = $this->object('staff-1', ['contactEmail' => 'staff@example.org'], 'org-1', '99');
		$message = $this->object('msg-1', ['supplierRef' => 'staff-1']);

		$outcomes = $this->notifier()->mail(
			origin: new ReferenceOrigin(object: $message, data: $message->getObject(), schema: $this->referencing(['supplierRef'])),
			request: new MailingRequest(
				recipients: [['kind' => 'email', 'field' => 'supplierRef.contactEmail']],
				subject: 's',
				body: 'b',
				category: 'service'
			)
		);

		$this->assertSame([['field' => 'supplierRef.contactEmail', 'outcome' => ReferencedAddressNotifier::REASON_REFERENCE_NOT_FOUND]], $outcomes);
		$this->assertSame([], $this->sent);
	}//end testAReferenceToAnObjectOfAnotherSchemaIsRefusedAndNothingIsSent()

	/**
	 * A reference property that declares no `$ref` (or no schema is known)
	 * names nothing to check against, so it is not followed.
	 *
	 * @return void
	 */
	public function testAReferencePropertyWithoutARefIsNotFollowed(): void {
		$this->store['sup-1'] = $this->object('sup-1', ['contactEmail' => 'info@bouw.example']);
		$message = $this->object('msg-1', ['supplierRef' => 'sup-1']);
		$notifier = $this->notifier();

		$plain = $this->schema(['supplierRef' => ['type' => 'string', 'format' => 'uuid']]);
		$this->assertSame(
			ReferencedAddressNotifier::REASON_REFERENCE_NOT_FOUND,
			$notifier->resolveAddress(origin: new ReferenceOrigin(object: $message, data: $message->getObject(), schema: $plain), path: 'supplierRef.contactEmail')['reason']
		);
		$this->assertSame(
			ReferencedAddressNotifier::REASON_REFERENCE_NOT_FOUND,
			$notifier->resolveAddress(origin: new ReferenceOrigin(object: $message, data: $message->getObject()), path: 'supplierRef.contactEmail')['reason']
		);
		$this->assertNull($notifier->resolveAddress(origin: new ReferenceOrigin(object: $message, data: $message->getObject(), schema: $plain), path: 'supplierRef.contactEmail')['address']);
	}//end testAReferencePropertyWithoutARefIsNotFollowed()

	/**
	 * Every hop of a longer path is held to the schema its own property
	 * declares, read from the schema of the object reached.
	 *
	 * @return void
	 */
	public function testEveryHopOfALongerPathIsCheckedAgainstItsOwnSchema(): void {
		$this->store['sup-1'] = $this->object('sup-1', ['next' => 'sup-2']);
		$this->store['sup-2'] = $this->object('sup-2', ['contactEmail' => 'deep@example.org']);
		$this->store['other'] = $this->object('other', ['contactEmail' => 'staff@example.org'], 'org-1', '99');
		$notifier = $this->notifier();
		$schema = $this->referencing(['first']);

		$ok = $this->object('m-1', ['first' => 'sup-1']);
		$this->assertSame('deep@example.org', $notifier->resolveAddress(origin: new ReferenceOrigin(object: $ok, data: $ok->getObject(), schema: $schema), path: 'first.next.contactEmail')['address']);

		$this->store['sup-1'] = $this->object('sup-1', ['next' => 'other']);
		$this->assertSame(
			ReferencedAddressNotifier::REASON_REFERENCE_NOT_FOUND,
			$notifier->resolveAddress(origin: new ReferenceOrigin(object: $ok, data: $ok->getObject(), schema: $schema), path: 'first.next.contactEmail')['reason']
		);
	}//end testEveryHopOfALongerPathIsCheckedAgainstItsOwnSchema()

	/**
	 * integriq is asked once, about the resolved addresses only, under the
	 * rule's category and the triggering object's correlation id; entries
	 * that are not `email` entries, or not entries at all, are skipped.
	 *
	 * @return void
	 */
	public function testIntegriqIsAskedOnceAboutResolvedAddressesOnly(): void {
		$asked = [];
		$this->optOut->method('askQuestion')->willReturnCallback(
			static function (OptOutQuestion $question) use (&$asked): array {
				$asked[] = [$question->channel, $question->category, $question->addresses, $question->correlationId];
				return ['ok@example.org' => ['send' => true, 'code' => 'allowed', 'unsubscribe' => null]];
			}
		);
		$this->store['a'] = $this->object('a', ['contactEmail' => 'ok@example.org']);
		$object = $this->object('msg-9', ['refA' => 'a', 'missing' => 'gone']);

		$outcomes = $this->notifier()->mail(
			origin: new ReferenceOrigin(object: $object, data: $object->getObject(), schema: $this->referencing(['refA', 'missing'])),
			request: new MailingRequest(recipients: [
				'not-an-entry',
				['kind' => 'email', 'field' => ' refA.contactEmail '],
				['kind' => 'users', 'users' => ['x']],
				['kind' => 'email', 'field' => 'missing.contactEmail'],
			], subject: 'Onderwerp', body: 'Tekst', category: 'marketing')
		);

		$this->assertSame([['email', 'marketing', ['ok@example.org'], 'openregister-email:msg-9']], $asked);
		$this->assertSame(
			[
				['field' => 'refA.contactEmail', 'outcome' => EmailSender::OUTCOME_DISPATCHED],
				['field' => 'missing.contactEmail', 'outcome' => ReferencedAddressNotifier::REASON_REFERENCE_NOT_FOUND],
			],
			$outcomes
		);
		$this->assertCount(1, $this->sent);
		$this->assertSame(['address' => 'ok@example.org', 'subject' => 'Onderwerp', 'body' => 'Tekst', 'unsubscribe' => null], $this->sent[0]);
	}//end testIntegriqIsAskedOnceAboutResolvedAddressesOnly()
}//end class
