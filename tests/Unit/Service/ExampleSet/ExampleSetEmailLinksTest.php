<?php

/**
 * Tests for the example set's email links.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-email-links/specs/example-sets/spec.md#requirement-a-fragment-may-link-mail-to-its-objects
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

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame([], $this->emailLinks, 'the check writes no link');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertCount(1, $this->emailLinks);
		$link = array_values($this->emailLinks)[0]->jsonSerialize();
		$this->assertSame('Subsidieformulier en de lijst met documenten gestuurd.', $link['subject']);
		$this->assertSame('esra.demir@zuiddrecht.nl', $link['sender']);
		$this->assertSame('2026-10-06T14:05:00+00:00', (new \DateTime($link['mailDate']))->setTimezone(new \DateTimeZone('UTC'))->format('c'));
		$this->assertSame('esra.demir', $link['linkedBy']);
		$this->assertSame('<subsidie-6-okt@zuiddrecht.nl>', $link['mailMessageUid']);
		$this->assertSame([0, 0], [$link['mailAccountId'], $link['mailMessageId']], 'a declared mail has no Mail ids');
		$this->assertNotNull($link['registerId']);
		$this->assertSame('example-set:alpha/subsidie', json_decode((string) $link['metadata'], true)['source']);

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(1, $this->emailLinks, 'a kept link is written once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame([], $this->emailLinks);
	}//end testALinkIsWrittenOnceAndRemoved()

	/**
	 * A real Mail message (a GreenMail test account's) is linked by its ids, and the shipping app links it without linkedBy.
	 *
	 * @return void
	 */
	public function testARealMailMessageIsLinkedByItsIds(): void {
		$row = self::subsidyMail(['mailAccountId' => 3, 'mailMessageId' => 41]);
		unset($row['messageId'], $row['linkedBy']);
		$this->organisationWithLinks(rows: [['key' => 'subsidie', 'data' => $row]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$link = array_values($this->emailLinks)[0]->jsonSerialize();
		$this->assertSame([3, 41, 'alpha'], [$link['mailAccountId'], $link['mailMessageId'], $link['linkedBy']]);
	}//end testARealMailMessageIsLinkedByItsIds()

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

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString($reason, $refusals[0]);
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
			'no object'       => [self::subsidyMail(['object' => '']), 'needs the object it is on'],
			'no subject'      => [self::subsidyMail(['subject' => '']), 'needs a subject'],
			'no mail'         => [$noMail, 'messageId, or mailAccountId and mailMessageId'],
			'foreign linker'  => [self::subsidyMail(['linkedBy' => 'nobody']), 'not an account the set creates or adopts'],
			'no moment'       => [self::subsidyMail(['mailDate' => 'gisteren']), 'mailDate is a date and time'],
			'unknown field'   => [self::subsidyMail(['folder' => 'INBOX']), 'not an email link field'],
		];
	}//end refusedLinks()
}//end class
