<?php


/**
 * OpenConnector Consumers Controller
 *
 * This file contains the controller for handling consumer related operations
 * in the OpenRegister application.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppInfo;

use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\SearchTrailService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\MySQLJsonService;
use OCA\OpenRegister\Service\ObjectHandlers\DeleteObject;
use OCA\OpenRegister\Service\ObjectHandlers\GetObject;
use OCA\OpenRegister\Service\ObjectHandlers\RenderObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObject;
use OCA\OpenRegister\Service\ObjectHandlers\SaveObjects;
use OCA\OpenRegister\Service\ObjectHandlers\ValidateObject;
use OCA\OpenRegister\Service\ObjectHandlers\PublishObject;
use OCA\OpenRegister\Service\ObjectHandlers\DepublishObject;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Class Application
 *
 * Application class for the OpenRegister app that handles bootstrapping.
 *
 * @category AppInfo
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author  Nextcloud Dev Team
 * @license AGPL-3.0-or-later
 *
 * @link https://github.com/nextcloud/server/blob/master/apps-extra/openregister
 */
class Application extends App implements IBootstrap
{
    /**
     * Application ID for the OpenRegister app
     *
     * @var string
     */
    public const APP_ID = 'openregister';


    /**
     * Constructor for the Application class
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(self::APP_ID);

    }//end __construct()


    /**
     * Register application components
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__.'/../../vendor/autoload.php';

        // @TODO: Usually, services are autowired. Les figure out why we need to do this
        // Register SearchTrail components
        $context->registerService(
                SearchTrailMapper::class,
                function ($container) {
                    return new SearchTrailMapper(
                    $container->get('OCP\IDBConnection'),
                    $container->get('OCP\IRequest'),
                    $container->get('OCP\IUserSession')
                    );
                }
                );

        $context->registerService(
                SearchTrailService::class,
                function ($container) {
                    return new SearchTrailService(
                    $container->get(SearchTrailMapper::class),
                    $container->get(RegisterMapper::class),
                    $container->get(SchemaMapper::class)
                    );
                }
                );

        // Register OrganisationMapper (event dispatching removed - handled by cron job)
        // $context->registerService(OrganisationMapper::class, function ($container) {
        // return new OrganisationMapper(
        // $container->get('OCP\IDBConnection')
        // );
        // });
        // Register ObjectEntityMapper with IGroupManager and IUserManager dependencies
        $context->registerService(
                ObjectEntityMapper::class,
                function ($container) {
                    return new ObjectEntityMapper(
                    $container->get('OCP\IDBConnection'),
                    $container->get(MySQLJsonService::class),
                    $container->get('OCP\EventDispatcher\IEventDispatcher'),
                    $container->get('OCP\IUserSession'),
                    $container->get(SchemaMapper::class),
                    $container->get('OCP\IGroupManager'),
                    $container->get('OCP\IUserManager'),
                    $container->get('OCP\IAppConfig'),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register OrganisationService with IConfig and IGroupManager dependencies
        $context->registerService(
                OrganisationService::class,
                function ($container) {
                    return new OrganisationService(
                    $container->get(OrganisationMapper::class),
                    $container->get('OCP\IUserSession'),
                    $container->get('OCP\ISession'),
                    $container->get('OCP\IConfig'),
                    $container->get('OCP\IGroupManager'),
                    $container->get('Psr\Log\LoggerInterface')
                    );
                }
                );

        // Register SaveObjects handler with dependencies
        $context->registerService(
                SaveObjects::class,
                function ($container) {
                    return new SaveObjects(
                    $container->get(ObjectEntityMapper::class),
                    $container->get(SchemaMapper::class),
                    $container->get(RegisterMapper::class),
                    $container->get(SaveObject::class),
                    $container->get(ValidateObject::class),
                    $container->get('OCP\IUserSession'),
                    $container->get(OrganisationService::class)
                    );
                }
                );

        // Register ObjectService with IGroupManager and IUserManager dependencies
        $context->registerService(
                ObjectService::class,
                function ($container) {
                    return new ObjectService(
                    $container->get(DeleteObject::class),
                    $container->get(GetObject::class),
                    $container->get(RenderObject::class),
                    $container->get(SaveObject::class),
                    $container->get(SaveObjects::class),
                    $container->get(ValidateObject::class),
                    $container->get(PublishObject::class),
                    $container->get(DepublishObject::class),
                    $container->get(RegisterMapper::class),
                    $container->get(SchemaMapper::class),
                    $container->get(ObjectEntityMapper::class),
                    $container->get(FileService::class),
                    $container->get('OCP\IUserSession'),
                    $container->get(SearchTrailService::class),
                    $container->get('OCP\IGroupManager'),
                    $container->get('OCP\IUserManager'),
                    $container->get(OrganisationService::class)
                    );
                }
                );

    }//end register()


    /**
     * Boot application components
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {

    }//end boot()


}//end class
