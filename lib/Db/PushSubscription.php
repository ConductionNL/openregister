<?php

/**
 * PushSubscription entity — a Web Push browser endpoint owned by one user.
 *
 * Infrastructure DB state (NOT an OpenRegister object/register): a transient
 * cryptographic endpoint (endpoint + p256dh/auth keys) per (user, browser).
 * Backed by the `openregister_push_subscriptions` table. Per ADR-001
 * reasoning these are not modelled as OR objects — they carry no business
 * meaning, no RBAC beyond "owner only", and no audit/relation value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class PushSubscription
 *
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getEndpoint()
 * @method void setEndpoint(?string $endpoint)
 * @method string|null getP256dh()
 * @method void setP256dh(?string $p256dh)
 * @method string|null getAuth()
 * @method void setAuth(?string $auth)
 * @method string|null getUserAgent()
 * @method void setUserAgent(?string $userAgent)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class PushSubscription extends Entity implements JsonSerializable
{

    /**
     * The owning Nextcloud user id.
     *
     * @var string|null
     */
    protected ?string $userId = null;

    /**
     * The push service endpoint URL (FCM / Mozilla / Apple).
     *
     * @var string|null
     */
    protected ?string $endpoint = null;

    /**
     * The client public key (P-256 ECDH) used for payload encryption.
     *
     * @var string|null
     */
    protected ?string $p256dh = null;

    /**
     * The client auth secret used for payload encryption.
     *
     * @var string|null
     */
    protected ?string $auth = null;

    /**
     * The browser user agent at subscribe time (diagnostics only).
     *
     * @var string|null
     */
    protected ?string $userAgent = null;

    /**
     * When the subscription was stored.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'userId', type: 'string');
        $this->addType(fieldName: 'endpoint', type: 'string');
        $this->addType(fieldName: 'p256dh', type: 'string');
        $this->addType(fieldName: 'auth', type: 'string');
        $this->addType(fieldName: 'userAgent', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization (never exposes another user's row — controller-gated).
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'        => $this->id,
            'endpoint'  => $this->endpoint,
            'userAgent' => $this->userAgent,
            'createdAt' => $this->createdAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
