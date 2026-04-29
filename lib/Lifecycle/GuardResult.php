<?php

/**
 * OpenRegister Lifecycle GuardResult
 *
 * Value object returned by `LifecycleGuardInterface::check`. Two factory
 * constructors (`allow` and `deny`) plus read-only inspectors.
 *
 * @category Lifecycle
 * @package  OCA\OpenRegister\Lifecycle
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

namespace OCA\OpenRegister\Lifecycle;

/**
 * Allow / deny verdict from a guard, optionally with a deny message.
 */
final class GuardResult
{

    /**
     * Whether the transition is allowed.
     *
     * @var bool
     */
    private bool $allowed;

    /**
     * Deny message when allowed=false.
     *
     * @var string|null
     */
    private ?string $message;

    /**
     * Private constructor — use static factories.
     *
     * @param bool        $allowed
     * @param string|null $message
     */
    private function __construct(bool $allowed, ?string $message)
    {
        $this->allowed = $allowed;
        $this->message = $message;
    }//end __construct()

    /**
     * Allow the transition.
     */
    public static function allow(): self
    {
        return new self(allowed: true, message: null);
    }//end allow()

    /**
     * Deny the transition with a user-visible message.
     *
     * @param string $message Human-readable reason. Surfaced to the caller in the 403 response.
     */
    public static function deny(string $message): self
    {
        return new self(allowed: false, message: $message);
    }//end deny()

    public function isAllowed(): bool
    {
        return $this->allowed;
    }//end isAllowed()

    public function getMessage(): ?string
    {
        return $this->message;
    }//end getMessage()

}//end class
