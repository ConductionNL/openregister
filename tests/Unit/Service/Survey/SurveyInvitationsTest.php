<?php

declare(strict_types=1);

/**
 * Sending a survey invitation: the link it carries, and the fatigue block that records instead of sends.
 *
 * The clock is frozen, so the fatigue window and the expiry are asserted against
 * a fixed moment rather than against whenever the suite happens to run.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Survey
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Survey;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessLinksInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Survey\FatigueLimit;
use OCA\OpenRegister\Service\Survey\SurveyInvitationRequest;
use OCA\OpenRegister\Service\Survey\SurveyInvitations;
use OCA\OpenRegister\Service\Survey\SurveyRecords;
use OCA\OpenRegister\Service\Survey\SurveyRules;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * SurveyInvitations against a frozen clock, a configurable fatigue limit and a recording write path.
 */
#[CoversClass(SurveyInvitations::class)]
#[UsesClass(SurveyInvitationRequest::class)]
#[UsesClass(SurveyRules::class)]
#[UsesClass(FatigueLimit::class)]
class SurveyInvitationsTest extends TestCase {

	/**
	 * The frozen moment of sending.
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
	 * The link minter.
	 *
	 * @var ObjectAccessLinksInterface&MockObject
	 */
	private ObjectAccessLinksInterface&MockObject $links;

	/**
	 * The configured fatigue limit, as app config values.
	 *
	 * @var array<string, int>
	 */
	private array $config = [];

	/**
	 * Every write, as [data, options].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: ObjectSaveOptions, 2: bool}>
	 */
	private array $writes = [];

	/**
	 * The service under test.
	 */
	private SurveyInvitations $invitations;

	/**
	 * Wire the service over mocks that record what it writes and mints.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->records = $this->createMock(SurveyRecords::class);
		$this->records->method('survey')->willReturn(['id' => 's-1', 'title' => 'KTO', 'version' => 2]);
		$this->records->method('tableOf')->willReturn(self::table());

		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options, bool $_rbac = true, bool $_multitenancy = true): ObjectEntity {
				$this->writes[] = [$object, $options, $_rbac];
				$saved = new ObjectEntity();
				$saved->setUuid($options->uuid ?? 'generated-uuid');
				$saved->setObject($object);

				return $saved;
			}
		);

		$this->links = $this->createMock(ObjectAccessLinksInterface::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => ($this->config[$key] ?? $default)
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$this->invitations = new SurveyInvitations(
			records: $this->records,
			rules: new SurveyRules(),
			objects: $this->objects,
			links: $this->links,
			appConfig: $appConfig,
			clock: $clock
		);
	}//end setUp()

	/**
	 * The invitation table of register 7.
	 *
	 * @return RegisterSchema The table.
	 */
	private static function table(): RegisterSchema {
		$register = new Register();
		$register->setId(7);
		$schema = new Schema();
		$schema->setId(12);
		$schema->setSlug('surveyInvitation');

		return new RegisterSchema(register: $register, schema: $schema);
	}//end table()

	/**
	 * A request to invite one respondent.
	 *
	 * @param string $respondent The respondent.
	 * @param string $expiresAt  The expiry.
	 *
	 * @return SurveyInvitationRequest The request.
	 */
	private static function request(string $respondent = 'jan@example.org', string $expiresAt = '2026-12-25T10:00:00+00:00'): SurveyInvitationRequest {
		return new SurveyInvitationRequest(
			register: '7',
			survey: 's-1',
			subjectObject: 'case-1',
			respondent: $respondent,
			expiresAt: $expiresAt,
			minter: 'flow-owner'
		);
	}//end request()

	/**
	 * An earlier invitation to the respondent.
	 *
	 * @param string      $state   Its state.
	 * @param string|null $created When it was created, or null.
	 *
	 * @return ObjectEntity The invitation.
	 */
	private static function earlier(string $state, ?string $created): ObjectEntity {
		$invitation = new ObjectEntity();
		$invitation->setObject(['state' => $state, 'respondent' => 'jan@example.org']);
		$invitation->setCreated($created === null ? null : new DateTime($created));

		return $invitation;
	}//end earlier()

	/**
	 * A sent invitation carries the link's uuid as its token, never the anchor, and is written once.
	 *
	 * @return void
	 */
	public function testAnInvitationIsWrittenOnceWithTheLinkItsRespondentFollows(): void {
		$minted = null;
		$this->links->expects($this->once())->method('mintObjectLink')->willReturnCallback(
			function (string $minter, string $objectUuid, string $expiresAt, string $label) use (&$minted): array {
				$minted = [$minter, $objectUuid, $expiresAt, $label];

				return ['uuid' => 'link-uuid', 'anchor' => 'AnchorOnlyTheRespondentGets'];
			}
		);

		$sent = $this->invitations->invite(request: self::request());

		$this->assertSame('flow-owner', $minted[0]);
		// The link outlives the invitation by the grace period, so a late visitor reads "expired", not 404.
		$this->assertSame('2027-01-24T10:00:00+00:00', $minted[2]);
		$this->assertCount(1, $this->writes);
		[$data, $options, $rbac] = $this->writes[0];
		$this->assertSame($minted[1], $options->uuid, 'the link opens the invitation it was minted for');
		$this->assertSame('7', $options->target->register);
		$this->assertSame('12', $options->target->schema);
		$this->assertFalse($rbac);
		$this->assertSame(
			[
				'survey' => 's-1',
				'subjectObject' => 'case-1',
				'respondent' => 'jan@example.org',
				'state' => SurveyRules::SENT,
				'expiresAt' => '2026-12-25T10:00:00+00:00',
				'token' => 'link-uuid',
			],
			$data
		);
		$this->assertStringNotContainsString('AnchorOnlyTheRespondentGets', (string)json_encode($data));
		$this->assertFalse($sent['blocked']);
		$this->assertSame('AnchorOnlyTheRespondentGets', $sent['anchor']);
		$this->assertSame($minted[1], $sent['invitation']['id']);
	}//end testAnInvitationIsWrittenOnceWithTheLinkItsRespondentFollows()

	/**
	 * A respondent surveyed last month under a limit of one per 90 days gets a blocked record, and no link.
	 *
	 * @return void
	 */
	public function testAnOverSurveyedRespondentIsRecordedBlockedWithTheReasonAndNothingIsSent(): void {
		$this->config = [SurveyInvitations::FATIGUE_MAXIMUM_KEY => 1, SurveyInvitations::FATIGUE_PERIOD_KEY => 90];
		$this->records->method('invitationsTo')->willReturn([self::earlier(state: SurveyRules::ANSWERED, created: '2026-10-25T10:00:00+00:00')]);
		$this->links->expects($this->never())->method('mintObjectLink');

		$blocked = $this->invitations->invite(request: self::request());

		$this->assertTrue($blocked['blocked']);
		$this->assertNull($blocked['anchor']);
		$this->assertSame('This person has already been sent 1 of a maximum 1 surveys in the last 90 days.', $blocked['reason']);
		$this->assertCount(1, $this->writes);
		[$data, $options] = $this->writes[0];
		$this->assertNull($options->uuid);
		$this->assertSame(SurveyRules::BLOCKED, $data['state']);
		$this->assertSame($blocked['reason'], $data['blockedReason']);
		$this->assertArrayNotHasKey('token', $data);
	}//end testAnOverSurveyedRespondentIsRecordedBlockedWithTheReasonAndNothingIsSent()

	/**
	 * Outside the period, or blocked, an earlier invitation does not count; inside it, or undated, it does.
	 *
	 * @return void
	 */
	public function testOnlySentInvitationsInsideThePeriodCount(): void {
		$this->records->method('invitationsTo')->willReturn([
			self::earlier(state: SurveyRules::SENT, created: '2026-08-26T09:59:59+00:00'),
			self::earlier(state: SurveyRules::SENT, created: '2026-08-27T10:00:00+00:00'),
			self::earlier(state: SurveyRules::BLOCKED, created: '2026-11-20T10:00:00+00:00'),
			self::earlier(state: SurveyRules::EXPIRED, created: '2026-11-01T10:00:00+00:00'),
			self::earlier(state: SurveyRules::SENT, created: null),
		]);

		$count = $this->invitations->recentInvitations(
			within: SurveyRecords::placeIn(register: '7'),
			respondent: 'jan@example.org',
			periodDays: 90,
			now: new DateTimeImmutable(self::NOW)
		);

		// 27 Aug is exactly 90 days back and counts; 26 Aug one second earlier does not.
		$this->assertSame(3, $count);
	}//end testOnlySentInvitationsInsideThePeriodCount()

	/**
	 * Under the limit the invitation goes out; with no limit configured the invitations are never read.
	 *
	 * @return void
	 */
	public function testUnderTheLimitOrWithoutOneTheInvitationIsSent(): void {
		$this->links->method('mintObjectLink')->willReturn(['uuid' => 'link-uuid', 'anchor' => 'a']);
		$this->records->expects($this->once())->method('invitationsTo')
			->willReturn([self::earlier(state: SurveyRules::BLOCKED, created: '2026-11-24T10:00:00+00:00')]);

		$this->config = [SurveyInvitations::FATIGUE_MAXIMUM_KEY => 1];
		$this->assertFalse($this->invitations->invite(request: self::request())['blocked']);

		$this->config = [];
		$this->assertFalse($this->invitations->invite(request: self::request())['blocked']);
	}//end testUnderTheLimitOrWithoutOneTheInvitationIsSent()

	/**
	 * A negative maximum is no limit and a period below a day is a day.
	 *
	 * @return void
	 */
	public function testTheFatigueLimitIsClampedToSenseFromConfig(): void {
		$this->assertSame(0, $this->invitations->fatigueLimit()->maximum);
		$this->assertSame(SurveyInvitations::DEFAULT_FATIGUE_PERIOD_DAYS, $this->invitations->fatigueLimit()->periodDays);

		$this->config = [SurveyInvitations::FATIGUE_MAXIMUM_KEY => -3, SurveyInvitations::FATIGUE_PERIOD_KEY => 0];
		$this->assertSame(0, $this->invitations->fatigueLimit()->maximum);
		$this->assertSame(1, $this->invitations->fatigueLimit()->periodDays);
	}//end testTheFatigueLimitIsClampedToSenseFromConfig()

	/**
	 * A request that cannot be sent is refused before anything is minted or written.
	 *
	 * @return array<string, array{0: SurveyInvitationRequest, 1: string}>
	 */
	public static function unsendable(): array {
		return [
			'no respondent' => [self::request(respondent: '  '), 'needs a respondent'],
			'no expiry' => [self::request(expiresAt: ''), 'needs an expiry'],
			'unreadable expiry' => [self::request(expiresAt: 'whenever'), 'is not a date'],
			'expiry now' => [self::request(expiresAt: self::NOW), 'must expire in the future'],
		];
	}//end unsendable()

	/**
	 * Each unsendable request is refused with its reason, and nothing is minted or written.
	 *
	 * @param SurveyInvitationRequest $request The request.
	 * @param string                  $reason  A fragment of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider unsendable
	 */
	public function testAnUnsendableRequestIsRefusedBeforeAnyWrite(SurveyInvitationRequest $request, string $reason): void {
		$this->links->expects($this->never())->method('mintObjectLink');

		try {
			$this->invitations->invite(request: $request);
			$this->fail('The request was sent.');
		} catch (InvalidArgumentException $refused) {
			$this->assertStringContainsString($reason, $refused->getMessage());
		}

		$this->assertSame([], $this->writes);
	}//end testAnUnsendableRequestIsRefusedBeforeAnyWrite()

	/**
	 * An invitation to a survey the register does not hold is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnInvitationToAnUnknownSurveyIsRefused(): void {
		$records = $this->createMock(SurveyRecords::class);
		$records->method('survey')->willThrowException(new \RuntimeException('no such row'));
		$this->links->expects($this->never())->method('mintObjectLink');
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));

		$invitations = new SurveyInvitations(
			records: $records,
			rules: new SurveyRules(),
			objects: $this->objects,
			links: $this->links,
			appConfig: $this->createMock(IAppConfig::class),
			clock: $clock
		);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("'s-1' is not a survey in this register.");
		$invitations->invite(request: self::request());
	}//end testAnInvitationToAnUnknownSurveyIsRefused()
}//end class
