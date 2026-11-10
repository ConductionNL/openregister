<?php

/**
 * EmailLinkMapper query behaviour tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\EmailLinkMapper;
use OCA\OpenRegister\Db\MailMessageKey;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Finders filter on the column they name; the message-uid match treats null as IS NULL;
 * ambiguous or missing single lookups answer null.
 */
class EmailLinkMapperQueriesTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var EmailLinkMapper
	 */
	private EmailLinkMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new EmailLinkMapper($this->recordingDb());
	}//end setUp()

	/**
	 * find() binds the id as an integer.
	 *
	 * @return void
	 */
	public function testFindBindsTheIdAsAnInteger(): void {
		$this->results[] = [['id' => 2, 'sender' => 'a@b.nl']];

		$this->assertSame('a@b.nl', $this->mapper->find(2)->getSender());
		$this->assertSame('select(*) from(openregister_email_links) where(eq(id, :p0)) executeQuery()', $this->sql());
		$this->assertSame([2, IQueryBuilder::PARAM_INT], $this->params[':p0']);
	}//end testFindBindsTheIdAsAnInteger()

	/**
	 * findByObjectUuid() lists newest mail first with no paging by default.
	 *
	 * @return void
	 */
	public function testFindByObjectUuidListsNewestMailFirst(): void {
		$this->results[] = [['id' => 2], ['id' => 1]];

		$links = $this->mapper->findByObjectUuid('obj-1');

		$this->assertSame([2, 1], array_map(static fn (EmailLink $l): int => $l->getId(), $links));
		$this->assertSame(
			'select(*) from(openregister_email_links) where(eq(object_uuid, :p0)) orderBy(mail_date, DESC) executeQuery()',
			$this->sql()
		);
	}//end testFindByObjectUuidListsNewestMailFirst()

	/**
	 * findByObjectUuid() pages when asked.
	 *
	 * @return void
	 */
	public function testFindByObjectUuidPages(): void {
		$this->mapper->findByObjectUuid('obj-1', 20, 40);

		$this->assertStringEndsWith('orderBy(mail_date, DESC) setMaxResults(20) setFirstResult(40) executeQuery()', $this->sql());
	}//end testFindByObjectUuidPages()

	/**
	 * findBySender() filters on the sender, newest first.
	 *
	 * @return void
	 */
	public function testFindBySenderFiltersOnSender(): void {
		$this->results[] = [['id' => 5, 'sender' => 'x@y.nl']];

		$links = $this->mapper->findBySender('x@y.nl');

		$this->assertSame('x@y.nl', $links[0]->getSender());
		$this->assertSame(
			'select(*) from(openregister_email_links) where(eq(sender, :p0)) orderBy(mail_date, DESC) executeQuery()',
			$this->sql()
		);
	}//end testFindBySenderFiltersOnSender()

	/**
	 * objectUuidsByRfcMessageId() answers the distinct object uuids as strings.
	 *
	 * @return void
	 */
	public function testObjectUuidsByRfcMessageIdAnswersStrings(): void {
		$this->results[] = [['object_uuid' => 'obj-1'], ['object_uuid' => 12]];

		$this->assertSame(['obj-1', '12'], $this->mapper->objectUuidsByRfcMessageId('<m@x>'));
		$this->assertSame(
			'selectDistinct(object_uuid) from(openregister_email_links) where(eq(rfc_message_id, :p0)) executeQuery()',
			$this->sql()
		);
		$this->assertSame('<m@x>', $this->bound(':p0'));
	}//end testObjectUuidsByRfcMessageIdAnswersStrings()

	/**
	 * An unknown message id answers no uuids.
	 *
	 * @return void
	 */
	public function testObjectUuidsByUnknownMessageIdIsEmpty(): void {
		$this->assertSame([], $this->mapper->objectUuidsByRfcMessageId('<none@x>'));
	}//end testObjectUuidsByUnknownMessageIdIsEmpty()

	/**
	 * findByObjectAndMessage() returns the link on the object for that message.
	 *
	 * @return void
	 */
	public function testFindByObjectAndMessageReturnsTheLink(): void {
		$this->results[] = [['id' => 8, 'mail_message_id' => 31]];

		$link = $this->mapper->findByObjectAndMessage('obj-1', 31);

		$this->assertSame(8, $link?->getId());
		$this->assertStringContainsString('object_uuid', $this->sql());
		$this->assertStringContainsString('mail_message_id', $this->sql());
		$this->assertContains(31, $this->boundValues());
		$this->assertContains('obj-1', $this->boundValues());
	}//end testFindByObjectAndMessageReturnsTheLink()

	/**
	 * findByObjectAndMessage() answers null when nothing matches.
	 *
	 * @return void
	 */
	public function testFindByObjectAndMessageWithoutMatchIsNull(): void {
		$this->assertNull($this->mapper->findByObjectAndMessage('obj-1', 31));
	}//end testFindByObjectAndMessageWithoutMatchIsNull()

	/**
	 * A message uid is matched by value.
	 *
	 * @return void
	 */
	public function testFindByObjectAccountMessageUidBindsTheUid(): void {
		$this->results[] = [['id' => 9]];

		$link = $this->mapper->findByObjectAccountMessageUid('obj-1', new MailMessageKey(3, 31, 'uid-77'));

		$this->assertSame(9, $link?->getId());
		$this->assertSame(
			'select(*) from(openregister_email_links) where(eq(object_uuid, :p0)) andWhere(eq(mail_account_id, :p1)) andWhere(eq(mail_message_id, :p2)) andWhere(eq(mail_message_uid, :p3)) executeQuery()',
			$this->sql()
		);
		$this->assertSame([3, IQueryBuilder::PARAM_INT], $this->params[':p1']);
		$this->assertSame([31, IQueryBuilder::PARAM_INT], $this->params[':p2']);
		$this->assertSame('uid-77', $this->bound(':p3'));
	}//end testFindByObjectAccountMessageUidBindsTheUid()

	/**
	 * A null message uid matches links without one.
	 *
	 * @return void
	 */
	public function testFindByObjectAccountMessageUidMatchesANullUid(): void {
		$this->assertNull($this->mapper->findByObjectAccountMessageUid('obj-1', new MailMessageKey(3, 31, null)));
		$this->assertStringEndsWith('andWhere(isNull(mail_message_uid)) executeQuery()', $this->sql());
		$this->assertCount(3, $this->params);
	}//end testFindByObjectAccountMessageUidMatchesANullUid()

	/**
	 * More than one matching row is ambiguous and answers null.
	 *
	 * @return void
	 */
	public function testFindByObjectAccountMessageUidWithTwoRowsIsNull(): void {
		$this->results[] = [['id' => 1], ['id' => 2]];

		$this->assertNull($this->mapper->findByObjectAccountMessageUid('obj-1', new MailMessageKey(3, 31, 'uid-77')));
	}//end testFindByObjectAccountMessageUidWithTwoRowsIsNull()

	/**
	 * deleteByObjectAndId() deletes only that link of that object and reports the count.
	 *
	 * @return void
	 */
	public function testDeleteByObjectAndIdScopesToTheObject(): void {
		$this->affected = 1;

		$this->assertSame(1, $this->mapper->deleteByObjectAndId('obj-1', 8));
		$this->assertSame(
			'delete(openregister_email_links) where(eq(id, :p0)) andWhere(eq(object_uuid, :p1)) executeStatement()',
			$this->sql()
		);
		$this->assertSame([8, IQueryBuilder::PARAM_INT], $this->params[':p0']);
		$this->assertSame('obj-1', $this->bound(':p1'));
	}//end testDeleteByObjectAndIdScopesToTheObject()

	/**
	 * A delete that matches nothing reports zero.
	 *
	 * @return void
	 */
	public function testDeleteByObjectAndIdOfAnotherObjectReportsZero(): void {
		$this->affected = 0;

		$this->assertSame(0, $this->mapper->deleteByObjectAndId('obj-2', 8));
	}//end testDeleteByObjectAndIdOfAnotherObjectReportsZero()
}//end class
