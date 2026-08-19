<?php

declare(strict_types=1);

/*
 * This file is part of CycloneDX PHP Library.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * SPDX-License-Identifier: Apache-2.0
 * Copyright (c) OWASP Foundation. All Rights Reserved.
 */

namespace CycloneDX\Core\Enums;

/**
 * Classification - aka ComponentType.
 *
 * See {@link https://cyclonedx.org/schema/bom/1.0 Schema 1.0} for `classification`.
 * See {@link https://cyclonedx.org/schema/bom/1.1 Schema 1.1} for `classification`.
 * See {@link https://cyclonedx.org/schema/bom/1.2 Schema 1.2} for `classification`.
 * See {@link https://cyclonedx.org/schema/bom/1.3 Schema 1.3} for `classification`.
 * See {@link https://cyclonedx.org/schema/bom/1.4 Schema 1.4} for `classification`.
 * See {@link https://cyclonedx.org/schema/bom/1.5 Schema 1.5} for `classification`.
 * See {@link https://cyclonedx.org/schema/bom/1.6 Schema 1.6} for `classification`.
 * See {@link https://cyclonedx.org/schema/bom/1.7 Schema 1.7} for `classification`.
 *
 * @author jkowalleck
 */
enum ComponentType: string
{
    case Application = 'application';
    case Framework = 'framework';
    case Library = 'library';
    case Container = 'container';
    case Platform = 'platform';
    case OperatingSystem = 'operating-system';
    case Device = 'device';
    case DeviceDriver = 'device-driver';
    case Firmware = 'firmware';
    case File = 'file';
    case MachineLearningModel = 'machine-learning-model';
    case Data = 'data';
    case CryptographicAsset = 'cryptographic-asset';
}
