<?php

/**
 * Pins the payload NotificationService puts on the wire.
 *
 * WHY THIS EXISTS. NotificationServiceTest has eight tests over
 * `notifyConfigurationUpdate()`, and every one of them stubs the notification
 * with `willReturnSelf()`:
 *
 *     $notification->method('setSubject')->willReturnSelf();
 *
 * That asserts the call CHAIN does not break. It asserts nothing about the
 * subject key or the parameter names, so renaming
 * `configuration_update_available`, dropping `currentVersion`, or changing the
 * object type would leave all eight green while every consumer of the
 * notification stopped recognising it.
 *
 * The subject and its parameter map are a CONTRACT — `lib/Notification/
 * Notifier.php` reads them back by name to render the message. This file pins
 * that contract from the producing side.
 *
 * The class was also fatally broken until 2026-08-26: it declared three
 * readonly properties and had no constructor, so nothing here could run
 * outside a test that reflected into it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Service\NotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The notification subject and parameters are a contract.
 */
class NotificationPayloadTest extends TestCase
{


    /**
     * Build a Configuration with the given identity and versions.
     *
     * @param int         $id      The configuration id.
     * @param string      $title   The configuration title.
     * @param string|null $local   The installed version.
     * @param string|null $remote  The available version.
     *
     * @return Configuration The entity.
     */
    private function configuration(int $id, string $title, ?string $local, ?string $remote): Configuration
    {
        $config = new Configuration();
        $config->setTitle($title);
        $config->setNotificationGroups([]);
        $config->setLocalVersion($local);
        $config->setRemoteVersion($remote);

        $ref  = new ReflectionClass($config);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($config, $id);

        return $config;

    }//end configuration()


    /**
     * Run one notification and capture what was put on the notification object.
     *
     * @param Configuration $config The configuration to announce.
     *
     * @return array<string,mixed> The captured app/user/object/subject values.
     */
    private function capture(Configuration $config): array
    {
        $captured = [];

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');

        $group = $this->createMock(IGroup::class);
        $group->method('getUsers')->willReturn([$user]);

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->willReturnMap([['admin', $group]]);

        $notification = $this->createMock(INotification::class);
        $notification->method('setApp')->willReturnCallback(
            function (string $app) use (&$captured, $notification): INotification {
                $captured['app'] = $app;
                return $notification;
            }
        );
        $notification->method('setUser')->willReturnCallback(
            function (string $uid) use (&$captured, $notification): INotification {
                $captured['user'] = $uid;
                return $notification;
            }
        );
        $notification->method('setDateTime')->willReturnSelf();
        $notification->method('setObject')->willReturnCallback(
            function (string $type, string $id) use (&$captured, $notification): INotification {
                $captured['objectType'] = $type;
                $captured['objectId']   = $id;
                return $notification;
            }
        );
        $notification->method('setSubject')->willReturnCallback(
            function (string $subject, array $parameters = []) use (&$captured, $notification): INotification {
                $captured['subject']    = $subject;
                $captured['parameters'] = $parameters;
                return $notification;
            }
        );

        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willReturn($notification);

        $service = new NotificationService(
            $manager,
            $groupManager,
            $this->createMock(LoggerInterface::class)
        );

        $service->notifyConfigurationUpdate($config);

        return $captured;

    }//end capture()


    /**
     * The app, user, object and subject keys are exactly what the Notifier reads.
     *
     * @return void
     */
    public function testTheNotificationCarriesTheContractedIdentity(): void
    {
        $captured = $this->capture($this->configuration(7, 'Zaakregister', '1.0', '2.0'));

        $this->assertSame('openregister', $captured['app']);
        $this->assertSame('admin', $captured['user']);
        $this->assertSame('configuration', $captured['objectType']);
        $this->assertSame('7', $captured['objectId'], 'the object id is stringified');
        $this->assertSame('configuration_update_available', $captured['subject']);

    }//end testTheNotificationCarriesTheContractedIdentity()


    /**
     * Every parameter the message template needs is present, by name.
     *
     * @return void
     */
    public function testTheSubjectParametersArePresentByName(): void
    {
        $captured = $this->capture($this->configuration(7, 'Zaakregister', '1.0', '2.0'));

        $this->assertSame(
            ['configurationTitle', 'configurationId', 'currentVersion', 'newVersion'],
            array_keys($captured['parameters']),
            'the Notifier reads these back by name — renaming one breaks rendering silently'
        );
        $this->assertSame('Zaakregister', $captured['parameters']['configurationTitle']);
        $this->assertSame(7, $captured['parameters']['configurationId']);
        $this->assertSame('1.0', $captured['parameters']['currentVersion']);
        $this->assertSame('2.0', $captured['parameters']['newVersion']);

    }//end testTheSubjectParametersArePresentByName()


    /**
     * Absent versions render as 'unknown' rather than null.
     *
     * A null reaching the template is not equivalent: the Notifier interpolates
     * these into a sentence, and a null renders as an empty string, producing
     * "update from  to " with no indication that anything is missing.
     *
     * @return void
     */
    public function testAbsentVersionsBecomeTheStringUnknown(): void
    {
        $captured = $this->capture($this->configuration(9, 'Untitled', null, null));

        $this->assertSame('unknown', $captured['parameters']['currentVersion']);
        $this->assertSame('unknown', $captured['parameters']['newVersion']);

    }//end testAbsentVersionsBecomeTheStringUnknown()


}//end class
