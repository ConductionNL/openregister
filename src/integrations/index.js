/**
 * OpenRegister integration registry — built-in registration barrel.
 *
 * Imports and invokes each built-in integration's register function so the
 * registry is populated before CnObjectSidebar and dashboard surfaces mount.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { registerTalkIntegration } from './builtin/talk.js'

export { registerTalkIntegration }
