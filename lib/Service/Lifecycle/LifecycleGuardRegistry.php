<?php

/**
 * OpenRegister LifecycleGuardRegistry
 *
 * Resolves a transition's `requires` DI tag to a concrete
 * `LifecycleGuardInterface` instance. Apps register guards via Application's
 * `registerService()` keyed by tag (e.g. `decidesk.meeting.openGuard`).
 *
 * Cache per request — multiple transitions on the same object during one
 * request reuse the resolved instance.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolves DI tag → guard instance.
 *
 * Missing tag is fail-closed: a transition that references an unregistered
 * guard cannot proceed. The runtime exception is mapped to HTTP 500 in the
 * controller; install-time `occ openregister:check-guards` (future) catches
 * the same problem before it reaches a request.
 */
final class LifecycleGuardRegistry
{

    /**
     * Per-request cache.
     *
     * @var array<string, LifecycleGuardInterface>
     */
    private array $cache = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Resolve a guard tag to its registered implementation.
     *
     * @param string $tag DI service tag (e.g. `decidesk.meeting.openGuard`).
     *
     * @return LifecycleGuardInterface
     *
     * @throws RuntimeException When the tag is not registered or the resolved service does not implement the interface.
     */
    public function resolve(string $tag): LifecycleGuardInterface
    {
        if (isset($this->cache[$tag]) === true) {
            return $this->cache[$tag];
        }

        $instance = null;
        $errors   = [];
        // Try OR's app container first (covers OR-internal guards) and
        // fall back to the server container (covers FQCN-based references
        // to guards in other apps that Nextcloud can autowire).
        foreach ([$this->container, \OC::$server] as $candidate) {
            try {
                $instance = $candidate->get($tag);
                break;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($instance === null) {
            $this->logger->error(
                sprintf('Lifecycle guard tag "%s" could not be resolved: %s', $tag, implode(' | ', $errors))
            );
            throw new RuntimeException(
                message: sprintf('Lifecycle guard "%s" is not registered.', $tag)
            );
        }

        if (($instance instanceof LifecycleGuardInterface) === false) {
            throw new RuntimeException(
                sprintf(
                    'Service "%s" does not implement %s.',
                    $tag,
                    LifecycleGuardInterface::class
                )
            );
        }

        $this->cache[$tag] = $instance;
        return $instance;
    }//end resolve()
}//end class
