<?php

/**
 * MCP Protocol Service
 *
 * Handles Model Context Protocol (MCP) standard handshake, session management,
 * and protocol-level operations for the OpenRegister MCP server.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Mcp;

use OCP\ICacheFactory;
use OCP\ICache;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * McpProtocolService manages MCP protocol handshake and sessions
 *
 * Implements the MCP standard initialize/ping methods and session
 * management via Nextcloud's distributed cache (APCu).
 *
 * @psalm-suppress UnusedClass - Injected via DI container
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class McpProtocolService
{

    /**
     * MCP protocol version supported by this server
     *
     * @var string
     */
    private const PROTOCOL_VERSION = '2025-03-26';

    /**
     * Server name reported in MCP initialize response
     *
     * @var string
     */
    private const SERVER_NAME = 'OpenRegister';

    /**
     * Server version reported in MCP initialize response
     *
     * @var string
     */
    private const SERVER_VERSION = '1.0.0';

    /**
     * Session TTL in seconds (1 hour)
     *
     * @var int
     */
    private const SESSION_TTL = 3600;

    /**
     * Distributed cache for MCP sessions
     *
     * @var ICache
     */
    private ICache $sessionCache;

    /**
     * McpProtocolService constructor
     *
     * @param ICacheFactory   $cacheFactory Nextcloud cache factory
     * @param ISecureRandom   $secureRandom Secure random generator
     * @param LoggerInterface $logger       Logger
     */
    public function __construct(
        ICacheFactory $cacheFactory,
        private readonly ISecureRandom $secureRandom,
        private readonly LoggerInterface $logger
    ) {
        $this->sessionCache = $cacheFactory->createDistributed(
            prefix: 'openregister_mcp_sessions'
        );
    }//end __construct()

    /**
     * Handle MCP initialize request
     *
     * Creates a new MCP session and returns server capabilities.
     *
     * @param array  $params Initialize parameters from client
     * @param string $userId Authenticated Nextcloud user ID
     *
     * @return array{result: array, sessionId: string} Result and session ID
     */
    public function initialize(array $params, string $userId): array
    {
        $sessionId = $this->createSession(userId: $userId);

        $result = [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => [
                'tools'     => ['listChanged' => false],
                'resources' => [
                    'subscribe'   => false,
                    'listChanged' => false,
                ],
            ],
            'serverInfo'      => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            'instructions'    => 'OpenRegister is a flexible data register platform for Nextcloud. Use tools to manage registers, schemas, and objects. Use resources to browse available data.',
        ];

        return [
            'result'    => $result,
            'sessionId' => $sessionId,
        ];
    }//end initialize()

    /**
     * Handle MCP ping request
     *
     * @return array Empty result per MCP spec
     */
    public function ping(): array
    {
        return [];
    }//end ping()

    /**
     * Create a new MCP session
     *
     * @param string $userId Nextcloud user ID to associate with session
     *
     * @return string Generated session ID (UUID v4 format)
     */
    public function createSession(string $userId): string
    {
        $sessionId = $this->secureRandom->generate(
            length: 32,
            characters: ISecureRandom::CHAR_ALPHANUMERIC
        );

        $this->sessionCache->set(
            key: $sessionId,
            value: $userId,
            ttl: self::SESSION_TTL
        );

        $this->logger->debug(
            message: '[MCP] Session created',
            context: ['sessionId' => $sessionId, 'userId' => $userId]
        );

        return $sessionId;
    }//end createSession()

    /**
     * Validate an MCP session ID
     *
     * @param string $sessionId Session ID from Mcp-Session-Id header
     *
     * @return string|null User ID if valid, null if expired/invalid
     */
    public function validateSession(string $sessionId): ?string
    {
        $userId = $this->sessionCache->get(key: $sessionId);

        if ($userId === null) {
            $this->logger->debug(
                message: '[MCP] Invalid or expired session',
                context: ['sessionId' => $sessionId]
            );
            return null;
        }

        return (string) $userId;
    }//end validateSession()

    /**
     * Destroy an MCP session
     *
     * @param string $sessionId Session ID to destroy
     *
     * @return void
     */
    public function destroySession(string $sessionId): void
    {
        $this->sessionCache->remove(key: $sessionId);

        $this->logger->debug(
            message: '[MCP] Session destroyed',
            context: ['sessionId' => $sessionId]
        );
    }//end destroySession()
}//end class
