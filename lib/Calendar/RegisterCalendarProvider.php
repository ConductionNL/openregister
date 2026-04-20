<?php

/**
 * OpenRegister Calendar Provider
 *
 * Implements ICalendarProvider to register virtual calendars
 * for OpenRegister schemas with calendar-enabled date fields.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCP\Calendar\ICalendarProvider;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Calendar provider that creates virtual calendars from OpenRegister schemas
 *
 * Registers one ICalendar per schema that has calendarProvider.enabled = true
 * in its configuration. These virtual calendars surface object date fields
 * as read-only events in the Nextcloud Calendar app.
 *
 * @package OCA\OpenRegister\Calendar
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class RegisterCalendarProvider implements ICalendarProvider
{

    /**
     * The schema mapper for loading schemas
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * The register mapper for loading registers
     *
     * @var RegisterMapper
     */
    private RegisterMapper $registerMapper;

    /**
     * The MagicMapper for querying objects
     *
     * @var MagicMapper
     */
    private MagicMapper $magicMapper;

    /**
     * The user session for authentication context
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * The event transformer
     *
     * @var CalendarEventTransformer
     */
    private CalendarEventTransformer $transformer;

    /**
     * Cached calendar-enabled schemas (per-request)
     *
     * @var array|null
     */
    private ?array $enabledSchemasCache = null;

    /**
     * Constructor
     *
     * @param SchemaMapper             $schemaMapper   The schema mapper
     * @param RegisterMapper           $registerMapper The register mapper
     * @param MagicMapper              $magicMapper    The MagicMapper
     * @param IUserSession             $userSession    The user session
     * @param LoggerInterface          $logger         Logger instance
     * @param CalendarEventTransformer $transformer    The event transformer
     */
    public function __construct(
        SchemaMapper $schemaMapper,
        RegisterMapper $registerMapper,
        MagicMapper $magicMapper,
        IUserSession $userSession,
        LoggerInterface $logger,
        CalendarEventTransformer $transformer
    ) {
        $this->schemaMapper   = $schemaMapper;
        $this->registerMapper = $registerMapper;
        $this->magicMapper    = $magicMapper;
        $this->userSession    = $userSession;
        $this->logger         = $logger;
        $this->transformer    = $transformer;
    }//end __construct()

    /**
     * Get virtual calendars for the given principal
     *
     * Returns one RegisterCalendar per schema that has calendar provider enabled.
     * Respects RBAC: anonymous/unauthenticated principals get no calendars.
     *
     * @param string $principalUri The principal URI (e.g., principals/users/admin)
     * @param array  $calendarUris Optional URI filter to return only specific calendars
     *
     * @return array Array of ICalendar instances
     */
    public function getCalendars(string $principalUri, array $calendarUris=[]): array
    {
        try {
            // Reject anonymous/unauthenticated principals.
            if ($this->isValidUserPrincipal(principalUri: $principalUri) === false) {
                return [];
            }

            $enabledSchemas = $this->getCalendarEnabledSchemas();

            if (empty($enabledSchemas) === true) {
                return [];
            }

            $calendars = [];

            foreach ($enabledSchemas as $schemaData) {
                $schema      = $schemaData['schema'];
                $config      = $schemaData['config'];
                $calendarUri = 'openregister-schema-'.$schema->getId();

                // Filter by requested URIs if provided.
                if (empty($calendarUris) === false && in_array($calendarUri, $calendarUris, true) === false) {
                    continue;
                }

                $calendars[] = new RegisterCalendar(
                    schema: $schema,
                    calendarConfig: $config,
                    magicMapper: $this->magicMapper,
                    registerMapper: $this->registerMapper,
                    transformer: $this->transformer,
                    principalUri: $principalUri,
                    logger: $this->logger
                );
            }

            return $calendars;
        } catch (\Exception $e) {
            $this->logger->warning(
                '[RegisterCalendarProvider] Failed to load calendars: '.$e->getMessage(),
                ['exception' => $e]
            );
            return [];
        }//end try
    }//end getCalendars()

    /**
     * Get all schemas that have calendar provider enabled
     *
     * Results are cached within the request to avoid repeated DB queries.
     *
     * @return array Array of ['schema' => Schema, 'config' => array] entries
     */
    private function getCalendarEnabledSchemas(): array
    {
        if ($this->enabledSchemasCache !== null) {
            return $this->enabledSchemasCache;
        }

        $this->enabledSchemasCache = [];

        try {
            $allSchemas = $this->schemaMapper->findAll();

            foreach ($allSchemas as $schema) {
                $calendarConfig = $schema->getCalendarProviderConfig();

                if ($calendarConfig === null) {
                    continue;
                }

                $this->enabledSchemasCache[] = [
                    'schema' => $schema,
                    'config' => $calendarConfig,
                ];
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                '[RegisterCalendarProvider] Failed to load schemas: '.$e->getMessage(),
                ['exception' => $e]
            );
            $this->enabledSchemasCache = [];
        }//end try

        return $this->enabledSchemasCache;
    }//end getCalendarEnabledSchemas()

    /**
     * Check if a principal URI represents a valid authenticated user
     *
     * @param string $principalUri The principal URI
     *
     * @return bool True if the principal is a valid user
     */
    private function isValidUserPrincipal(string $principalUri): bool
    {
        return preg_match('/^principals\/users\/.+$/', $principalUri) === 1;
    }//end isValidUserPrincipal()
}//end class
