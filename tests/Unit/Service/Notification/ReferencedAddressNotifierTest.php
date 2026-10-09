<?php

/**
 * Tests for ReferencedAddressNotifier.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Calculation\ReferenceTenantGuard;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifier;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The `email` recipient kind: an address read off the object, directly or through a reference.
 *
 * @covers \OCA\OpenRegister\Service\Notification\ReferencedAddressNotifier
 */
class ReferencedAddressNotifierTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService $objects;

	/**
	 * @var ReferenceTenantGuard&MockObject
	 */
	private ReferenceTenantGuard $guard;

	/**
	 * @var OptOutAuthority&MockObject
	 */
	private OptOutAuthority $optOut;

	/**
	 * @var EmailSender&MockObject
	 */
	private EmailSender $email;

	private ReferencedAddressNotifier $notifier;

	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectService::class);
		$this->guard = $this->createMock(ReferenceTenantGuard::class);
		$this->optOut = $this->createMock(OptOutAuthority::class);
		$this->email = $this->createMock(EmailSender::class);
		$this->optOut->method('withLink')->willReturnArgument(0);
		$this->optOut->method('decisionFor')->willReturnCallback(
			static fn (array $decisions, string $address): array => ($decisions[$address] ?? ['send' => false, 'code' => OptOutAuthority::CODE_AUTHORITY_UNAVAILABLE, 'unsubscribe' => null])
		);

		$this->notifier = new ReferencedAddressNotifier(
			objectService: $this->objects,
			tenantGuard: $this->guard,
			optOut: $this->optOut,
			email: $this->email,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	private function object(array $data, ?string $organisation = 'org-a'): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setOrganisation($organisation);
		$object->setObject($data);

		return $object;
	}//end object()

	private function allowAll(): void {
		$this->optOut->method('ask')->willReturnCallback(
			static function (string $channel, string $category, array $addresses): array {
				$out = [];
				foreach ($addresses as $address) {
					$out[$address] = ['send' => true, 'code' => 'allowed', 'unsubscribe' => null];
				}

				return $out;
			}
		);
	}//end allowAll()

	/**
	 * `supplierRef.contactEmail`: the reference is read as the system, the
	 * tenant guard admits it, and its `contactEmail` is mailed.
	 */
	public function testAnAddressIsReadThroughAReference(): void {
		$supplier = $this->object(['contactEmail' => 'inkoop@leverancier.nl']);
		$supplier->setUuid('sup-1');
		$this->objects->expects($this->once())
			->method('find')
			->with('sup-1', $this->anything(), $this->anything(), null, null, false, false, false, false)
			->willReturn($supplier);
		$this->guard->expects($this->once())
			->method('firstAdmitted')
			->with('org-a', [$supplier])
			->willReturn($supplier);
		$this->allowAll();
		$this->email->expects($this->once())
			->method('sendToAddress')
			->with('inkoop@leverancier.nl', '', 'Nieuw bericht', 'Er is een bericht voor u.', null)
			->willReturn(EmailSender::OUTCOME_DISPATCHED);

		$outcomes = $this->notifier->notify(
			recipientsSpec: [['kind' => 'users', 'users' => ['admin']], ['kind' => 'email', 'field' => 'supplierRef.contactEmail']],
			object: $this->object(['supplierRef' => 'sup-1']),
			subject: 'Nieuw bericht',
			body: 'Er is een bericht voor u.',
			category: 'service'
		);

		$this->assertSame([['field' => 'supplierRef.contactEmail', 'outcome' => EmailSender::OUTCOME_DISPATCHED]], $outcomes);
	}//end testAnAddressIsReadThroughAReference()

	/**
	 * A reference may be stored as an object or a URL; both name the uuid.
	 */
	public function testAReferenceStoredAsAnObjectOrAUrlNamesTheSameObject(): void {
		$supplier = $this->object(['contact' => ['email' => 'a@b.nl']]);
		$this->objects->method('find')->willReturnCallback(
			function (string $id) use ($supplier): ObjectEntity {
				$this->assertSame('sup-1', $id);

				return $supplier;
			}
		);
		$this->guard->method('firstAdmitted')->willReturn($supplier);

		$this->assertSame('a@b.nl', $this->notifier->addressFor(object: $this->object(['ref' => ['id' => 'sup-1']]), field: 'ref.contact.email')['address']);
		$this->assertSame('a@b.nl', $this->notifier->addressFor(object: $this->object(['ref' => 'https://x.nl/api/objects/r/s/sup-1/']), field: 'ref.contact.email')['address']);
	}//end testAReferenceStoredAsAnObjectOrAUrlNamesTheSameObject()

	/**
	 * A reference into another tenant is not followed: no find result passes
	 * the guard, so nothing is mailed and the outcome says why.
	 */
	public function testAReferenceOutsideTheTenantIsNotMailed(): void {
		$this->objects->method('find')->willReturn($this->object(['contactEmail' => 'x@other.nl'], 'org-b'));
		$this->guard->method('firstAdmitted')->willReturn(null);
		$this->allowAll();
		$this->email->expects($this->never())->method('sendToAddress');

		$outcomes = $this->notifier->notify(
			recipientsSpec: [['kind' => 'email', 'field' => 'supplierRef.contactEmail']],
			object: $this->object(['supplierRef' => 'sup-9']),
			subject: 's',
			body: 'b',
			category: 'service'
		);

		$this->assertSame(ReferencedAddressNotifier::OUTCOME_REFERENCE_UNRESOLVED, $outcomes[0]['outcome']);
	}//end testAReferenceOutsideTheTenantIsNotMailed()

	/**
	 * A failed read is an unresolved reference, never a thrown dispatch.
	 */
	public function testAFailedReadIsUnresolvedNotThrown(): void {
		$this->objects->method('find')->willThrowException(new RuntimeException('gone'));

		$this->assertSame(
			ReferencedAddressNotifier::OUTCOME_REFERENCE_UNRESOLVED,
			$this->notifier->addressFor(object: $this->object(['supplierRef' => 'sup-1']), field: 'supplierRef.contactEmail')['outcome']
		);
	}//end testAFailedReadIsUnresolvedNotThrown()

	/**
	 * One segment reads the address off the object; a value that is not an
	 * address is not mailed.
	 */
	public function testADirectFieldMustHoldAnAddress(): void {
		$this->objects->expects($this->never())->method('find');

		$this->assertSame('melder@example.nl', $this->notifier->addressFor(object: $this->object(['email' => ' melder@example.nl ']), field: 'email')['address']);
		$this->assertSame(
			ReferencedAddressNotifier::OUTCOME_NO_ADDRESS,
			$this->notifier->addressFor(object: $this->object(['email' => 'not an address']), field: 'email')['outcome']
		);
	}//end testADirectFieldMustHoldAnAddress()

	/**
	 * Integriq's refusal is honoured, and its silence is a refusal too.
	 */
	public function testAnOptedOutAddressIsNotMailed(): void {
		$this->optOut->expects($this->once())
			->method('ask')
			->with('email', 'case-update', ['melder@example.nl'], 'openregister-email-field:case-1')
			->willReturn(['melder@example.nl' => ['send' => false, 'code' => 'opted-out', 'unsubscribe' => null]]);
		$this->email->expects($this->never())->method('sendToAddress');

		$outcomes = $this->notifier->notify(
			recipientsSpec: [['kind' => 'email', 'field' => 'email']],
			object: $this->object(['email' => 'melder@example.nl']),
			subject: 's',
			body: 'b',
			category: 'case-update'
		);

		$this->assertSame(ReferencedAddressNotifier::OUTCOME_REFUSED_OPTED_OUT, $outcomes[0]['outcome']);
	}//end testAnOptedOutAddressIsNotMailed()

	public function testARuleWithoutAnEmailRecipientAsksNobody(): void {
		$this->optOut->expects($this->never())->method('ask');

		$this->assertSame(
			[],
			$this->notifier->notify(recipientsSpec: [['kind' => 'parties']], object: $this->object([]), subject: 's', body: 'b', category: 'service')
		);
	}//end testARuleWithoutAnEmailRecipientAsksNobody()
}//end class
