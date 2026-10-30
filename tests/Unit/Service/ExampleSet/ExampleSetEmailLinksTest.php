<?php

/**
 * Tests for the example set's email links.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * A fragment links a mail to an organisation, once, and remove deletes the link.
 */
class ExampleSetEmailLinksTest extends ExampleSetTestCase {

	/**
	 * Set up an organisation schema and the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'crm', schema: 'client');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}//end setUp()

	/**
	 * An organisation with the given links.
	 *
	 * @param array<int, array<string, mixed>> $rows The link rows.
	 *
	 * @return void
	 */
	private function organisationWithLinks(array $rows): void {
		$this->app(app: 'alpha', fragment: [
			'objects'    => [['key' => 'oost', 'register' => 'crm', 'schema' => 'client', 'data' => ['name' => 'Buurtvereniging Oost']]],
			'emailLinks' => $rows,
		]);
	}//end organisationWithLinks()

	/**
	 * The subsidy mail, declared by its Message-ID.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function subsidyMail(array $extra = []): array {
		return [...[
			'object'    => '{{object:oost.id}}',
			'messageId' => '<subsidie-6-okt@zuiddrecht.nl>',
			'subject'   => 'Subsidieformulier en de lijst met documenten gestuurd.',
			'sender'    => 'esra.demir@zuiddrecht.nl',
			'mailDate'  => '2026-10-06T16:05:00+02:00',
			'linkedBy'  => '{{cast:esra.uid}}',
		], ...$extra];
	}//end subsidyMail()

	/**
	 * The link is on the object, with the mail as it shows, written once, and remove deletes it.
	 *
	 * @return void
	 */
	public function testALinkIsWrittenOnceAndRemoved(): void {
		$this->organisationWithLinks(rows: [['key' => 'subsidie', 'data' => self::subsidyMail()]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->emailLinks, message: 'the check writes no link');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertCount(expectedCount: 1, haystack: $this->emailLinks);
		$link = array_values($this->emailLinks)[0]->jsonSerialize();
		$this->assertSame(expected: 'Subsidieformulier en de lijst met documenten gestuurd.', actual: $link['subject']);
		$this->assertSame(expected: 'esra.demir@zuiddrecht.nl', actual: $link['sender']);
		$mailDate = (new \DateTime($link['mailDate']))->setTimezone(new \DateTimeZone('UTC'));
		$this->assertSame(expected: '2026-10-06T14:05:00+00:00', actual: $mailDate->format('c'));
		$this->assertSame(expected: 'esra.demir', actual: $link['linkedBy']);
		$this->assertSame(expected: '<subsidie-6-okt@zuiddrecht.nl>', actual: $link['mailMessageUid']);
		$this->assertSame(expected: [0, 0], actual: [$link['mailAccountId'], $link['mailMessageId']], message: 'a declared mail has no Mail ids');
		$this->assertNotNull(actual: $link['registerId']);
		$this->assertSame(expected: 'example-set:alpha/subsidie', actual: json_decode((string) $link['metadata'], true)['source']);

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(expectedCount: 1, haystack: $this->emailLinks, message: 'a kept link is written once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: [], actual: $this->emailLinks);
	}//end testALinkIsWrittenOnceAndRemoved()

	/**
	 * A real Mail message (a GreenMail test account's) is linked by its ids, and the shipping app links it without linkedBy.
	 *
	 * @return void
	 */
	public function testARealMailMessageIsLinkedByItsIds(): void {
		$row = self::subsidyMail(extra: ['mailAccountId' => 3, 'mailMessageId' => 41]);
		unset($row['messageId'], $row['linkedBy']);
		$this->organisationWithLinks(rows: [['key' => 'subsidie', 'data' => $row]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$link = array_values($this->emailLinks)[0]->jsonSerialize();
		$this->assertSame(expected: [3, 41, 'alpha'], actual: [$link['mailAccountId'], $link['mailMessageId'], $link['linkedBy']]);
	}//end testARealMailMessageIsLinkedByItsIds()

	/**
	 * A link carries the sender's name and the details the board shows under
	 * the subject (DqZaakEmail): direction, recipient, attachments, how it was
	 * linked and its archive copy, beside the set's source.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/email-link-sender-name-and-details/specs/email-link-sender-name-and-details/spec.md
	 */
	public function testALinkCarriesTheSenderNameAndItsDetails(): void {
		$row = self::subsidyMail(extra: [
			'senderName'      => 'Esra Demir',
			'direction'       => 'outbound',
			'recipientName'   => 'Buurtvereniging Oost',
			'attachmentCount' => 2,
			'linkedVia'       => 'file',
			'archivedAs'      => 'pdf',
		]);
		$this->organisationWithLinks(rows: [['key' => 'subsidie', 'data' => $row]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$link = array_values($this->emailLinks)[0]->jsonSerialize();
		$this->assertSame(expected: 'Esra Demir', actual: $link['senderName']);
		$this->assertSame(
			expected: [
				'direction'       => 'outbound',
				'recipientName'   => 'Buurtvereniging Oost',
				'attachmentCount' => 2,
				'linkedVia'       => 'file',
				'archivedAs'      => 'pdf',
				'mailboxName'     => null,
			],
			actual: $link['details']
		);
		$this->assertSame(expected: 'example-set:alpha/subsidie', actual: json_decode((string) $link['metadata'], true)['source']);
	}//end testALinkCarriesTheSenderNameAndItsDetails()

	/**
	 * `--refresh` writes a held link again from today's declaration, so a
	 * changed detail reaches it; without `--refresh` the held link is kept.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	public function testARefreshWritesTheLinkAgainFromTodaysDeclaration(): void {
		$this->organisationWithLinks(rows: [['key' => 'subsidie', 'data' => self::subsidyMail(extra: ['mailboxName' => 'Woo-postvak'])]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->organisationWithLinks(rows: [['key' => 'subsidie', 'data' => self::subsidyMail(extra: ['mailboxName' => 'het Woo-postvak'])]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$held = array_values($this->emailLinks)[0]->jsonSerialize();
		$this->assertSame(expected: 'Woo-postvak', actual: $held['details']['mailboxName'], message: 'a plain install keeps the held link');

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['alpha/subsidie' => 'refreshed'], actual: $report['emailLinks']);
		$this->assertCount(expectedCount: 1, haystack: $this->emailLinks, message: 'the old link is taken away, not doubled');
		$this->assertSame(expected: 'het Woo-postvak', actual: array_values($this->emailLinks)[0]->jsonSerialize()['details']['mailboxName']);
	}//end testARefreshWritesTheLinkAgainFromTodaysDeclaration()

	/**
	 * A faulty link is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedLinks
	 */
	public function testACheckRefusesAFaultyLink(array $data, string $reason): void {
		$this->organisationWithLinks(rows: [['key' => 'bad', 'data' => $data]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: $reason, haystack: $refusals[0]);
	}//end testACheckRefusesAFaultyLink()

	/**
	 * The faulty links and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedLinks(): array {
		$noMail = self::subsidyMail();
		unset($noMail['messageId']);

		return [
			'no object'       => [self::subsidyMail(extra: ['object' => '']), 'needs the object it is on'],
			'no subject'      => [self::subsidyMail(extra: ['subject' => '']), 'needs a subject'],
			'no mail'         => [$noMail, 'messageId, or mailAccountId and mailMessageId'],
			'foreign linker'  => [self::subsidyMail(extra: ['linkedBy' => 'nobody']), 'not an account the set creates or adopts'],
			'no moment'       => [self::subsidyMail(extra: ['mailDate' => 'gisteren']), 'mailDate is a date and time'],
			'unknown field'   => [self::subsidyMail(extra: ['folder' => 'INBOX']), 'not an email link field'],
			'bad direction'   => [self::subsidyMail(extra: ['direction' => 'sideways']), 'direction is inbound or outbound'],
			'bad linked via'  => [self::subsidyMail(extra: ['linkedVia' => 'pigeon']), 'linkedVia is manual, mailbox or file'],
			'bad attachments' => [self::subsidyMail(extra: ['attachmentCount' => -1]), 'attachmentCount is a whole number of 0 or more'],
		];
	}//end refusedLinks()
}//end class
