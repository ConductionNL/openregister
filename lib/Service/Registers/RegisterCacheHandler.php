<?php

/**
 * OpenRegister Register Cache Handler
 *
 * Handler class responsible for invalidating cached register data after a
 * runtime CRUD mutation. Mirrors {@see \OCA\OpenRegister\Service\Schemas\SchemaCacheHandler}
 * for the register entity but stays intentionally small — Registers do not
 * have a persistent cache table today; this handler closes the request-scoped
 * cache window on {@see \OCA\OpenRegister\Db\RegisterMapper} so that follow-up
 * reads in the same PHP worker observe a fresh state.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Registers;

use OCA\OpenRegister\Db\RegisterMapper;
use Psr\Log\LoggerInterface;

/**
 * RegisterCacheHandler — runtime-schema-api cache invalidator
 *
 * Public entry point invoked by the controllers (create/update/delete) after
 * a successful mapper round-trip. Drops every cached lookup keyed on the
 * given register ID so the next read re-fetches from the database within
 * the same PHP worker.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Registers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class RegisterCacheHandler
{

    /**
     * Register mapper for clearing the request-scoped find cache
     *
     * @var RegisterMapper Register mapper instance.
     */
    private readonly RegisterMapper $registerMapper;

    /**
     * Logger for invalidation audit
     *
     * @var LoggerInterface Logger instance.
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * Injects the register mapper (for cache clear) and logger (for audit
     * of every invalidation event).
     *
     * @param RegisterMapper  $registerMapper Register mapper to clear find cache on.
     * @param LoggerInterface $logger         Logger for invalidation audit trail.
     *
     * @return void
     */
    public function __construct(
        RegisterMapper $registerMapper,
        LoggerInterface $logger
    ) {
        $this->registerMapper = $registerMapper;
        $this->logger         = $logger;
    }//end __construct()

    /**
     * Invalidate cache for a specific register
     *
     * Drops every cached lookup keyed on the given register ID so that any
     * follow-up read in the same PHP worker re-fetches the register from the
     * database. The next read MUST observe the new state.
     *
     * Called by the runtime-schema-api CRUD path
     * (RegistersController::create/update/patch/destroy) after a successful
     * mapper round-trip and before the controller returns.
     *
     * @param int $registerId The register ID to invalidate.
     *
     * @return void
     */
    public function invalidate(int $registerId): void
    {
        // Clear the request-scoped find cache on the mapper itself.
        $this->registerMapper->clearFindCache(registerId: $registerId);

        $this->logger->debug(
            message: '[RegisterCacheHandler] Register cache invalidated',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'registerId' => $registerId,
            ]
        );
    }//end invalidate()
}//end class
