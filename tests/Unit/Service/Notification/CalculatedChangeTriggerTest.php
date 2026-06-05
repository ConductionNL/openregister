<?php

/**
 * OpenRegister CalculatedChangeTriggerTest
 *
 * Unit tests for the `calculatedChange` notification trigger (issue #1470 §3).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCP\Activity\IManager as IActivityManager;
use OCP\Http\Client\IClientService;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the calculatedChange notification trigger.
 *
 * Covers four debounce scenarios from the spec (issue #1470 §3):
 * (a) First save below threshold — old value also below — no crossing.
 * (b) Above-then-below crossing — both clauses satisfied — fires.
 * (c) Below-then-still-below — previously clause fails — debounced.
 * (d) Condition clause fails — new value not below threshold — no fire.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class CalculatedChangeTriggerTest extends TestCase
{

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var INotificationManager&MockObject */
    private INotificationManager $notificationManager;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var IGroupManager&MockObject */
    private IGroupManager $groupManager;

    /** @var IUserManager&MockObject */
    private IUserManager $userManager;

    /** @var IMailer&MockObject */
    private IMailer $mailer;

    /** @var IActivityManager&MockObject */
    private IActivityManager $activityManager;

    /** @var IClientService&MockObject */
    private IClientService $httpClient;

    /** @var IServerContainer&MockObject */
    private IServerContainer $serverContainer;


    /**
     * Set up mocks shared across all test methods.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->groupManager        = $this->createMock(IGroupManager::class);
        $this->userManager         = $this->createMock(IUserManager::class);
        $this->mailer              = $this->createMock(IMailer::class);
        $this->activityManager     = $this->createMock(IActivityManager::class);
        $this->httpClient          = $this->createMock(IClientService::class);
        $this->serverContainer     = $this->createMock(IServerContainer::class);

        // All UIDs resolve as real users so recipient filtering never drops them.
        $this->userManager->method('userExists')->willReturn(true);

    }//end setUp()


    /**
     * (a) First save: both new and old values are below 0.85.
     * The `previously` clause (gte: 0.85) is NOT satisfied by the old value,
     * so no boundary crossing occurred — the rule must NOT fire.
     *
     * @return void
     */
    public function testFirstSaveBelowThresholdDoesNotFire(): void
    {
        $schema = $this->schemaWithRule(condition: ['lt' => 0.85], previously: ['gte' => 0.85]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->notificationManager->expects($this->never())->method('createNotification');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch(
            $this->objectWithCoverage(0.70),
            'calculatedChange',
            [
                '_newData' => ['coveragePercent' => 0.70],
                '_oldData' => ['coveragePercent' => 0.72],
            ]
        );

    }//end testFirstSaveBelowThresholdDoesNotFire()


    /**
     * (b) Above-then-below crossing: old value was >= 0.85, new value is < 0.85.
     * Both clauses are satisfied — the rule must fire exactly once.
     *
     * @return void
     */
    public function testAboveThenBelowCrossingFires(): void
    {
        $schema = $this->schemaWithRule(condition: ['lt' => 0.85], previously: ['gte' => 0.85]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $delivered = [];
        $this->captureDeliveredUids($delivered);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch(
            $this->objectWithCoverage(0.80),
            'calculatedChange',
            [
                '_newData' => ['coveragePercent' => 0.80],
                '_oldData' => ['coveragePercent' => 0.90],
            ]
        );

        $this->assertSame(['officer'], $delivered, 'Notification must fire on a genuine boundary crossing.');

    }//end testAboveThenBelowCrossingFires()


    /**
     * (c) Below-then-still-below: old value was already below 0.85.
     * The `previously` (gte: 0.85) clause is NOT satisfied — debounce, no fire.
     *
     * @return void
     */
    public function testBelowThenStillBelowDebouncesAndDoesNotFire(): void
    {
        $schema = $this->schemaWithRule(condition: ['lt' => 0.85], previously: ['gte' => 0.85]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->notificationManager->expects($this->never())->method('createNotification');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch(
            $this->objectWithCoverage(0.75),
            'calculatedChange',
            [
                '_newData' => ['coveragePercent' => 0.75],
                '_oldData' => ['coveragePercent' => 0.80],
            ]
        );

    }//end testBelowThenStillBelowDebouncesAndDoesNotFire()


    /**
     * (d) Both `condition` AND `previously` must hold.
     * The condition clause fails (new value is above 0.85), so no fire.
     *
     * @return void
     */
    public function testConditionClauseFailureBlocksFire(): void
    {
        $schema = $this->schemaWithRule(condition: ['lt' => 0.85], previously: ['gte' => 0.85]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->notificationManager->expects($this->never())->method('createNotification');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch(
            $this->objectWithCoverage(0.90),
            'calculatedChange',
            [
                '_newData' => ['coveragePercent' => 0.90],
                '_oldData' => ['coveragePercent' => 0.95],
            ]
        );

    }//end testConditionClauseFailureBlocksFire()


    /**
     * When _oldData is absent from context the rule is skipped (fail-closed).
     * Better to miss a notification than to fire spuriously without proof of crossing.
     *
     * @return void
     */
    public function testMissingOldDataSkipsRule(): void
    {
        $schema = $this->schemaWithRule(condition: ['lt' => 0.85], previously: ['gte' => 0.85]);
        $this->schemaMapper->method('find')->willReturn($schema);

        $this->notificationManager->expects($this->never())->method('createNotification');

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch(
            $this->objectWithCoverage(0.80),
            'calculatedChange',
            ['_newData' => ['coveragePercent' => 0.80]]
        );

    }//end testMissingOldDataSkipsRule()


    /**
     * Without condition or previously the gate is open — fires on every
     * calculatedChange event for the named field.
     *
     * @return void
     */
    public function testOpenGateWithoutConditionOrPreviouslyFires(): void
    {
        $schema = $this->schemaWithRule(condition: null, previously: null);
        $this->schemaMapper->method('find')->willReturn($schema);

        $delivered = [];
        $this->captureDeliveredUids($delivered);

        $dispatcher = $this->makeDispatcher();
        $dispatcher->dispatch(
            $this->objectWithCoverage(0.80),
            'calculatedChange',
            [
                '_newData' => ['coveragePercent' => 0.80],
                '_oldData' => ['coveragePercent' => 0.90],
            ]
        );

        $this->assertSame(['officer'], $delivered);

    }//end testOpenGateWithoutConditionOrPreviouslyFires()


    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a schema with a single `calculatedChange` notification rule
     * monitoring the `coveragePercent` field.
     *
     * @param array<string, float|int|string>|null $condition  Operators the new value must satisfy.
     * @param array<string, float|int|string>|null $previously Operators the old value must satisfy.
     *
     * @return Schema
     */
    private function schemaWithRule(?array $condition, ?array $previously): Schema
    {
        $trigger = ['type' => 'calculatedChange', 'field' => 'coveragePercent'];
        if ($condition !== null) {
            $trigger['condition'] = $condition;
        }

        if ($previously !== null) {
            $trigger['previously'] = $previously;
        }

        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug('coverage-schema');
        $schema->setConfiguration(
            [
                'x-openregister-notifications' => [
                    'officerAlertOnCoverageDrop' => [
                        'trigger'    => $trigger,
                        'recipients' => [['kind' => 'users', 'users' => ['officer']]],
                        'channels'   => ['nc-notification'],
                        'subject'    => 'Coverage dropped below threshold',
                    ],
                ],
            ]
        );
        return $schema;

    }//end schemaWithRule()


    /**
     * Build a minimal ObjectEntity carrying a `coveragePercent` value.
     *
     * @param float $coverage Coverage percentage (0 to 1).
     *
     * @return ObjectEntity
     */
    private function objectWithCoverage(float $coverage): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('obj-uuid-1');
        $object->setSchema('coverage-schema');
        $object->setRegister('reg-1');
        $object->setObject(['coveragePercent' => $coverage]);
        return $object;

    }//end objectWithCoverage()


    /**
     * Build the dispatcher under test with minimal mocks.
     *
     * @return AnnotationNotificationDispatcher
     */
    private function makeDispatcher(): AnnotationNotificationDispatcher
    {
        return new AnnotationNotificationDispatcher(
            $this->schemaMapper,
            $this->notificationManager,
            $this->logger,
            $this->groupManager,
            $this->userManager,
            $this->mailer,
            $this->activityManager,
            $this->httpClient,
            $this->serverContainer
        );

    }//end makeDispatcher()


    /**
     * Stub INotificationManager so each notify() call appends the recipient
     * uid to $delivered.
     *
     * @param array<int, string> $delivered Out-param accumulating delivered uids.
     *
     * @return void
     */
    private function captureDeliveredUids(array &$delivered): void
    {
        $this->notificationManager->method('createNotification')
            ->willReturnCallback(
                function () use (&$delivered) {
                    $notif = $this->createMock(INotification::class);
                    $notif->method('setApp')->willReturnSelf();
                    $notif->method('setUser')->willReturnCallback(
                    function (string $uid) use ($notif, &$delivered) {
                        $delivered[] = $uid;
                        return $notif;
                    }
                    );
                    $notif->method('setDateTime')->willReturnSelf();
                    $notif->method('setObject')->willReturnSelf();
                    $notif->method('setSubject')->willReturnSelf();
                    return $notif;
                }
            );

    }//end captureDeliveredUids()
}//end class
