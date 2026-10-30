<?php
/**
 * ScheduledReportInterface: what callers outside lib/Db need of a ScheduledReport row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use JsonSerializable;

/**
 * A scheduled report: an export that runs on a schedule and goes to its recipients.
 *
 * Implemented by \OCA\OpenRegister\Db\ScheduledReport. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 *
 * @method int getId()
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getFilters()
 * @method void setFilters(?string $filters)
 * @method string|null getFormat()
 * @method void setFormat(?string $format)
 * @method int|null getProfileId()
 * @method void setProfileId(?int $profileId)
 * @method string|null getScheduleType()
 * @method void setScheduleType(?string $scheduleType)
 * @method int|null getScheduleHour()
 * @method void setScheduleHour(?int $scheduleHour)
 * @method int|null getScheduleDayOfWeek()
 * @method void setScheduleDayOfWeek(?int $scheduleDayOfWeek)
 * @method int|null getScheduleDayOfMonth()
 * @method void setScheduleDayOfMonth(?int $scheduleDayOfMonth)
 * @method string|null getDeliveryFolder()
 * @method void setDeliveryFolder(?string $deliveryFolder)
 * @method string|null getDeliveryMode()
 * @method void setDeliveryMode(?string $deliveryMode)
 * @method string|null getRecipients()
 * @method void setRecipients(?string $recipients)
 * @method bool|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 * @method DateTime|null getLastRunAt()
 * @method void setLastRunAt(?DateTime $lastRunAt)
 * @method string|null getLastStatus()
 * @method void setLastStatus(?string $lastStatus)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 */
interface ScheduledReportInterface extends JsonSerializable {

	/**
	 * The decoded filter map, or an empty array when none is stored.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function getFiltersArray(): array;

	/**
	 * The decoded recipient email list, or an empty array when none is stored
	 * (default-to-owner-email is resolved at run time, not here).
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function getRecipientsArray(): array;

	/**
	 * JSON serialization — the row is only ever exposed to its owner or an admin (controller-gated).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function jsonSerialize(): array;
}//end interface
