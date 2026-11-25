<?php

declare(strict_types=1);

/**
 * Answering a survey from its invitation link: what a link opens, the three refusals, and the writes.
 *
 * The clock is frozen, so "expired" is decided against a fixed moment.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survey
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-002-an-invitation-is-answerable-without-an-account
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Survey;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkInterface;
use OCA\OpenRegister\Kernel\Contracts\AccessLinkReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Survey\InvitationVisit;
use OCA\OpenRegister\Service\Survey\SurveyAnswering;
use OCA\OpenRegister\Service\Survey\SurveyAnswerRefusedException;
use OCA\OpenRegister\Service\Survey\SurveyAnswerVisit;
use OCA\OpenRegister\Service\Survey\SurveyRecords;
use OCA\OpenRegister\Service\Survey\SurveyRules;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * SurveyAnswering over mocked links, records and write path.
 */
#[CoversClass(SurveyAnswering::class)]
#[CoversClass(SurveyAnswerRefusedException::class)]
#[UsesClass(SurveyAnswerVisit::class)]
#[UsesClass(SurveyRules::class)]
#[UsesClass(InvitationVisit::class)]
class SurveyAnsweringTest extends TestCase {

	/**
	 * The frozen moment of answering.
	 */
	private const NOW = '2026-11-25T10:00:00+00:00';

	/**
	 * The survey records.
	 *
	 * @var SurveyRecords&MockObject
	 */
	private SurveyRecords&MockObject $records;

	/**
	 * The write path.
	 *
	 * @var ObjectOperationsInterface&MockObject
	 */
	private ObjectOperationsInterface&MockObject $objects;

	/**
	 * The links.
	 *
	 * @var ObjectAccessLinksInterface&MockObject
	 */
	private ObjectAccessLinksInterface&MockObject $links;

	/**
	 * The link reader.
	 *
	 * @var AccessLinkReaderInterface&MockObject
	 */
	private AccessLinkReaderInterface&MockObject $reader;

	/**
	 * The survey the invitation asks about, as records->survey() answers it.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $survey = ['id' => 's-1', 'title' => 'KTO', 'introduction' => 'How did we do?', 'version' => 3, 'anonymity' => 'attributed', 'readerRoles' => ['kcc']];

	/**
	 * Every write, as [data, options].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: ObjectSaveOptions}>
	 */
	private array $writes = [];

	/**
	 * The service under test.
	 */
	private SurveyAnswering $answering;

	/**
	 * Wire the service over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->records = $this->createMock(SurveyRecords::class);
		$this->records->method('survey')->willReturnCallback(fn (): ?array => $this->survey);
		$this->records->method('tableOf')->willReturnCallback(
			static function (ObjectEntity $within, string $slug): RegisterSchema {
				$register = new Register();
				$register->setId(7);
				$schema = new Schema();
				$schema->setId(['surveyAnswerSet' => 13, 'surveyInvitation' => 12][$slug]);
				$schema->setSlug($slug);

				return new RegisterSchema(register: $register, schema: $schema);
			}
		);

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options): ObjectEntity {
				$this->writes[] = [$object, $options];
				$saved = new ObjectEntity();
				$saved->setUuid($options->uuid ?? 'answer-set-1');
				$saved->setObject($object);

				return $saved;
			}
		);

		$this->links = $this->createMock(ObjectAccessLinksInterface::class);
		$this->reader = $this->createMock(AccessLinkReaderInterface::class);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$this->answering = new SurveyAnswering(
			records: $this->records,
			rules: new SurveyRules(),
			objects: $this->objects,
			links: $this->links,
			reader: $this->reader,
			clock: $clock
		);
	}//end setUp()

	/**
	 * An invitation object.
	 *
	 * @param array<string, mixed> $data Its data over a sent, unexpired invitation.
	 *
	 * @return ObjectEntity The invitation.
	 */
	private static function invitation(array $data = []): ObjectEntity {
		$invitation = new ObjectEntity();
		$invitation->setUuid('inv-1');
		$invitation->setRegister('7');
		$invitation->setSchema('12');
		$invitation->setObject(array_merge(
			[
				'survey' => 's-1',
				'subjectObject' => 'case-1',
				'respondent' => 'jan@example.org',
				'state' => SurveyRules::SENT,
				'expiresAt' => '2026-12-25T10:00:00+00:00',
				'token' => 'link-uuid',
			],
			$data
		));

		return $invitation;
	}//end invitation()

	/**
	 * A visit to an invitation.
	 *
	 * @param array<string, mixed> $data The invitation's data.
	 *
	 * @return SurveyAnswerVisit The visit.
	 */
	private function visit(array $data = []): SurveyAnswerVisit {
		return new SurveyAnswerVisit(link: $this->createMock(AccessLinkInterface::class), invitation: self::invitation(data: $data));
	}//end visit()

	/**
	 * A live link to an invitation opens it; a dead link, a vanished subject or a link to anything else opens nothing.
	 *
	 * @return void
	 */
	public function testALinkOpensOnlyASurveyInvitation(): void {
		$link = $this->createMock(AccessLinkInterface::class);
		$this->links->method('liveObjectLink')->willReturnCallback(fn (string $anchor): ?AccessLinkInterface => ($anchor === 'dead' ? null : $link));
		$case = new ObjectEntity();
		$this->reader->method('subjectObject')->willReturnOnConsecutiveCalls(null, $case, self::invitation());
		$this->records->method('schemaSlugOf')->willReturnCallback(
			static fn (ObjectEntity $object): ?string => ($object === $case ? 'case' : 'surveyInvitation')
		);

		$this->assertNull($this->answering->open(anchor: 'dead'));
		$this->assertNull($this->answering->open(anchor: 'gone'), 'the subject no longer resolves');
		$this->assertNull($this->answering->open(anchor: 'case'), 'a link shared to read a case is no invitation');

		$visit = $this->answering->open(anchor: 'live');
		$this->assertInstanceOf(SurveyAnswerVisit::class, $visit);
		$this->assertSame($link, $visit->link);
		$this->assertSame('inv-1', $visit->invitation->getUuid());
	}//end testALinkOpensOnlyASurveyInvitation()

	/**
	 * A subject whose schema cannot be read is not an invitation.
	 *
	 * @return void
	 */
	public function testASubjectOfUnreadableSchemaOpensNothing(): void {
		$this->links->method('liveObjectLink')->willReturn($this->createMock(AccessLinkInterface::class));
		$this->reader->method('subjectObject')->willReturn(self::invitation());
		$this->records->method('schemaSlugOf')->willThrowException(new RuntimeException('schema gone'));

		$this->assertNull($this->answering->open(anchor: 'live'));
	}//end testASubjectOfUnreadableSchemaOpensNothing()

	/**
	 * The form shows the survey and its questions in order, with only the fields a respondent may see.
	 *
	 * @return void
	 */
	public function testTheFormListsTheQuestionsInOrderWithOnlyTheirPublicFields(): void {
		$this->records->method('questions')->willReturn([
			['id' => 'q-2', 'slug' => 'remarks', 'text' => 'Remarks?', 'kind' => 'text', 'order' => 2, 'survey' => 's-1'],
			['id' => 'q-1', 'slug' => 'score', 'text' => 'Score?', 'kind' => 'scale', 'order' => 1, 'required' => true],
		]);

		$form = $this->answering->form(visit: $this->visit());

		$this->assertSame(
			['id' => 's-1', 'title' => 'KTO', 'introduction' => 'How did we do?', 'version' => 3, 'anonymity' => 'attributed'],
			$form['survey'],
			'the reader roles are nobody\'s business but the survey\'s readers'
		);
		$this->assertSame(['q-1', 'q-2'], array_column($form['questions'], 'id'));
		$this->assertSame(['id' => 'q-2', 'slug' => 'remarks', 'text' => 'Remarks?', 'kind' => 'text', 'order' => 2], $form['questions'][1]);
	}//end testTheFormListsTheQuestionsInOrderWithOnlyTheirPublicFields()

	/**
	 * The three refusals, and a survey that is gone: each answers GONE with its own words, and nothing is written.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function unanswerable(): array {
		return [
			'expired a second ago' => [['expiresAt' => '2026-11-25T09:59:59+00:00'], 'This invitation has expired'],
			'already answered' => [['state' => SurveyRules::ANSWERED], 'already been answered from this link'],
			'never sent' => [['state' => SurveyRules::BLOCKED, 'blockedReason' => 'fatigue'], 'never sent: fatigue'],
		];
	}//end unanswerable()

	/**
	 * Each unanswerable invitation is refused on the form and on the submission.
	 *
	 * @param array<string, mixed> $data   The invitation's data.
	 * @param string               $reason A fragment of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider unanswerable
	 */
	public function testAnUnanswerableInvitationIsRefusedWithItsReason(array $data, string $reason): void {
		foreach (['form', 'answer'] as $door) {
			try {
				if ($door === 'form') {
					$this->answering->form(visit: $this->visit(data: $data));
				} else {
					$this->answering->answer(visit: $this->visit(data: $data), answers: [['question' => 'score', 'value' => 5]]);
				}

				$this->fail($door.' was not refused');
			} catch (SurveyAnswerRefusedException $refused) {
				$this->assertSame(SurveyAnswerRefusedException::GONE, $refused->status);
				$this->assertStringContainsString($reason, $refused->getMessage());
			}
		}

		$this->assertSame([], $this->writes);
	}//end testAnUnanswerableInvitationIsRefusedWithItsReason()

	/**
	 * An answered invitation on a survey that allows reopening may be answered again.
	 *
	 * @return void
	 */
	public function testReopeningLetsAnAnsweredInvitationBeAnsweredAgain(): void {
		$this->survey['allowReopening'] = true;
		$this->records->method('questions')->willReturn([]);

		$this->assertSame('s-1', $this->answering->form(visit: $this->visit(data: ['state' => SurveyRules::ANSWERED]))['survey']['id']);
	}//end testReopeningLetsAnAnsweredInvitationBeAnsweredAgain()

	/**
	 * An invitation whose survey is gone is refused, not answered into nothing.
	 *
	 * @return void
	 */
	public function testAnInvitationToAVanishedSurveyIsGone(): void {
		$this->survey = null;

		$this->expectException(SurveyAnswerRefusedException::class);
		$this->expectExceptionMessage('no longer exists');
		$this->answering->form(visit: $this->visit());
	}//end testAnInvitationToAVanishedSurveyIsGone()

	/**
	 * A submission writes the answer set linked to the subject and the version, then marks the invitation answered.
	 *
	 * @return void
	 */
	public function testASubmissionWritesTheAnswerSetThenMarksTheInvitationAnswered(): void {
		$answerSet = $this->answering->answer(
			visit: $this->visit(),
			answers: [['question' => 'score', 'value' => 4, 'extra' => 'dropped'], 'not an answer', ['value' => 'no question']]
		);

		$this->assertSame('answer-set-1', $answerSet['id']);
		$this->assertCount(2, $this->writes);

		[$set, $setOptions] = $this->writes[0];
		$this->assertNull($setOptions->uuid);
		$this->assertSame('13', $setOptions->target->schema);
		$this->assertSame(
			[
				'survey' => 's-1',
				'surveyVersion' => 3,
				'subjectObject' => 'case-1',
				'respondent' => 'jan@example.org',
				'answers' => [['question' => 'score', 'value' => 4]],
				'submittedAt' => self::NOW,
			],
			$set
		);

		[$invitation, $invitationOptions] = $this->writes[1];
		$this->assertSame('inv-1', $invitationOptions->uuid);
		$this->assertSame('12', $invitationOptions->target->schema);
		$this->assertSame(SurveyRules::ANSWERED, $invitation['state']);
		$this->assertSame(self::NOW, $invitation['answeredAt']);
		$this->assertSame('link-uuid', $invitation['token'], 'the rest of the invitation is kept');
	}//end testASubmissionWritesTheAnswerSetThenMarksTheInvitationAnswered()

	/**
	 * A submission the survey's rules refuse (a missing required answer) is INCOMPLETE, and the invitation stays sent.
	 *
	 * @return void
	 */
	public function testARefusedSubmissionIsIncompleteAndLeavesTheInvitationSent(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('saveObject')
			->willThrowException(new HookStoppedException(message: 'This question still needs an answer: "Score?".'));
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));
		$answering = new SurveyAnswering(
			records: $this->records,
			rules: new SurveyRules(),
			objects: $objects,
			links: $this->links,
			reader: $this->reader,
			clock: $clock
		);

		try {
			$answering->answer(visit: $this->visit(), answers: []);
			$this->fail('The submission was accepted.');
		} catch (SurveyAnswerRefusedException $refused) {
			$this->assertSame(SurveyAnswerRefusedException::INCOMPLETE, $refused->status);
			$this->assertSame('This question still needs an answer: "Score?".', $refused->getMessage());
		}
	}//end testARefusedSubmissionIsIncompleteAndLeavesTheInvitationSent()

	/**
	 * A use is recorded on the invitation with the act, the address and the survey it belongs to.
	 *
	 * @return void
	 */
	public function testAUseIsRecordedOnTheInvitation(): void {
		$visit = $this->visit();
		$this->links->expects($this->once())->method('recordObjectUse')
			->with($visit->link, $visit->invitation, SurveyAnswering::ACT_ANSWERED, '192.0.2.1', ['survey' => 's-1']);

		$this->answering->recordUse(visit: $visit, act: SurveyAnswering::ACT_ANSWERED, ipAddress: '192.0.2.1');
	}//end testAUseIsRecordedOnTheInvitation()
}//end class
