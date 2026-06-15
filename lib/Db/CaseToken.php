<?php

/**
 * CaseToken entity — a public, token-bound "track your case" link to an
 * OpenRegister object.
 *
 * A leaf app (e.g. procest) mints a token through the Shares integration
 * provider; the token resolves anonymously to a public-safe view of the
 * referenced object. The token row carries the object coordinates
 * (register / schema / uuid) plus a lifecycle (created / expires /
 * revoked) so the public resolve endpoint can fail-closed on
 * expired / revoked / unknown tokens without leaking object existence.
 *
 * This is intentionally NOT an NC file share: it is a standalone public
 * read surface for a single OR object, independent of the Files app.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/integration-leaf-foundation-shares-analytics/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class CaseToken
 *
 * @method string|null getToken()
 * @method void getToken(?string $token)
 * @method void setToken(?string $token)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method DateTime|null getRevokedAt()
 * @method void setRevokedAt(?DateTime $revokedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class CaseToken extends Entity implements JsonSerializable
{

    /**
     * The opaque public token (URL-safe, high entropy).
     *
     * @var string|null
     */
    protected ?string $token = null;

    /**
     * The referenced object uuid.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The register id of the referenced object.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The schema id of the referenced object.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * Optional human label for the link (e.g. "Track your application").
     *
     * @var string|null
     */
    protected ?string $label = null;

    /**
     * The uid that minted the token.
     *
     * @var string|null
     */
    protected ?string $createdBy = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Optional expiry timestamp; null = never expires.
     *
     * @var DateTime|null
     */
    protected ?DateTime $expiresAt = null;

    /**
     * Revocation timestamp; non-null = revoked (resolve fails closed).
     *
     * @var DateTime|null
     */
    protected ?DateTime $revokedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'token', type: 'string');
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'label', type: 'string');
        $this->addType(fieldName: 'createdBy', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'expiresAt', type: 'datetime');
        $this->addType(fieldName: 'revokedAt', type: 'datetime');
    }//end __construct()

    /**
     * Whether the token is currently usable (not revoked, not expired).
     *
     * @param DateTime $now Reference instant.
     *
     * @return bool True when the token may be resolved.
     */
    public function isValidAt(DateTime $now): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }

        if ($this->expiresAt !== null && $this->expiresAt <= $now) {
            return false;
        }

        return true;
    }//end isValidAt()

    /**
     * JSON serialization — public-safe metadata only (never the
     * referenced object content, which the resolve endpoint renders
     * RBAC-scoped separately).
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'token'      => $this->token,
            'objectUuid' => $this->objectUuid,
            'registerId' => $this->registerId,
            'schemaId'   => $this->schemaId,
            'label'      => $this->label,
            'createdBy'  => $this->createdBy,
            'createdAt'  => $this->createdAt?->format(DateTime::ATOM),
            'expiresAt'  => $this->expiresAt?->format(DateTime::ATOM),
            'revokedAt'  => $this->revokedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
