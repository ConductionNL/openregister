import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WHAT A LINK HANDS OVER WHEN IT CROSSES A DOMAIN BOUNDARY.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once the change's specs are archived into `openspec/specs/`:
 *
 * @e2e row-field-level-security::a-wmo-case-sees-two-fields-of-a-jeugdwet-case
 * @e2e row-field-level-security::withheld-is-not-empty
 * @e2e row-field-level-security::an-unknown-property-is-refused-at-schema-save
 *
 * `LinkExposure` had its rule and its unit tests from the day the change
 * opened, and no caller at all. A rule with no caller is the worst shape a
 * control can take: every test of it passes, every schema that declares
 * `exposes` is accepted, and every field it was written to withhold travels
 * anyway. So this spec is about the two places the rule is now called from,
 * over HTTP, because that is the only way to see whether it runs.
 *
 * It probes as an ordinary authenticated user, never as an administrator: the
 * withholding is a render-boundary rule, and asserting it as the one principal
 * who bypasses most things would prove the least.
 *
 * HERMETIC BY CONSTRUCTION. It creates its own register, two schemas and its
 * objects, and removes all of them. It needs no `occ` and no docker.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

/** The seeded non-admin account, provisioned by the workflow's seed command. */
const READER = 'e2e-other'
const READER_PASS = 'E2e-Share-Pass-123'

/** A short unique suffix so repeated runs never collide. */
const RUN = Math.random().toString(36).slice(2, 10)

const API = '/index.php/apps/openregister/api'

/** The marker a withheld property reads as. Mirrors LinkExposure::WITHHELD. */
const WITHHELD = '__withheld__'

/** Build an API context authenticated as one user. */
async function contextFor(
	user: string,
	password: string,
): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** Every action open to any signed-in caller, so the LINK is what narrows. */
const OPEN_TO_EVERYONE = {
	read: ['authenticated'],
	create: ['authenticated'],
	update: ['authenticated'],
	delete: ['authenticated'],
}

test.describe('a link hands over the fields it declares', () => {
	let admin: APIRequestContext
	let reader: APIRequestContext
	let registerId: string
	let besluitSchemaId: string
	let zaakSchemaId: string
	const created: Array<[string, string]> = []

	/** The slug the far schema takes from its title. */
	const besluitSlug = `e2e-link-exposure-besluit-${RUN}`

	async function createObject(
		schemaId: string,
		data: Record<string, unknown>,
	): Promise<string> {
		const res = await admin.post(`${API}/objects/${registerId}/${schemaId}`, {
			data,
		})
		expect(res.ok(), `object create failed: ${await res.text()}`).toBeTruthy()

		const body = await res.json()
		const uuid = String(body['@self']?.id ?? body.id ?? body.uuid)
		expect(uuid, 'no uuid came back from the object create').toBeTruthy()
		created.push([schemaId, uuid])

		return uuid
	}

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		reader = await contextFor(READER, READER_PASS)

		const reg = await admin.post(`${API}/registers`, {
			data: { title: `e2e link exposure register ${RUN}`, description: 'e2e' },
		})
		expect(reg.ok(), `register create failed: ${await reg.text()}`).toBeTruthy()
		registerId = String((await reg.json()).id)

		// The FAR schema: a besluit with three fields, one of which is the one
		// the link is not meant to carry.
		const besluit = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e link exposure besluit ${RUN}`,
				description: 'e2e',
				properties: {
					zaaknummer: { type: 'string', title: 'Zaaknummer', maxLength: 64 },
					status: { type: 'string', title: 'Status', maxLength: 64 },
					toelichting: { type: 'string', title: 'Toelichting', maxLength: 255 },
				},
				authorization: OPEN_TO_EVERYONE,
			},
		})
		expect(
			besluit.ok(),
			`far schema create failed: ${await besluit.text()}`,
		).toBeTruthy()
		besluitSchemaId = String((await besluit.json()).id)

		// The NEAR schema: a zaak whose link to a besluit declares that it
		// exposes two of those three fields.
		const zaak = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e link exposure zaak ${RUN}`,
				description: 'e2e',
				properties: {
					onderwerp: { type: 'string', title: 'Onderwerp', maxLength: 255 },
					besluit: {
						$ref: besluitSlug,
						'x-openregister-relation': { type: 'gerelateerd' },
					},
				},
				configuration: {
					'x-openregister-relation-types': [
						{
							key: 'gerelateerd',
							label: 'related to',
							inverseLabel: 'related from',
							exposes: ['zaaknummer', 'status'],
						},
					],
				},
				authorization: OPEN_TO_EVERYONE,
			},
		})
		expect(zaak.ok(), `near schema create failed: ${await zaak.text()}`).toBeTruthy()
		zaakSchemaId = String((await zaak.json()).id)
	})

	test.afterAll(async () => {
		for (const [schemaId, uuid] of created) {
			await admin.delete(`${API}/objects/${registerId}/${schemaId}/${uuid}`)
			await admin.delete(`${API}/deleted/${uuid}?force=true`)
		}

		for (const schemaId of [zaakSchemaId, besluitSchemaId]) {
			if (schemaId) {
				await admin.delete(`${API}/schemas/${schemaId}`)
			}
		}

		if (registerId) {
			await admin.delete(`${API}/registers/${registerId}`)
		}
	})

	test('the link shows the two fields it declares and withholds the rest', async () => {
		const besluitUuid = await createObject(besluitSchemaId, {
			zaaknummer: `B-${RUN}`,
			status: 'genomen',
			toelichting: 'de persoonlijke afweging',
		})
		const zaakUuid = await createObject(zaakSchemaId, {
			onderwerp: `Zaak ${RUN}`,
			besluit: besluitUuid,
		})

		const res = await reader.get(
			`${API}/objects/${registerId}/${zaakSchemaId}/${zaakUuid}?_extend=besluit`,
		)
		expect(res.ok(), `the extended read failed: ${await res.text()}`).toBeTruthy()

		const far = (await res.json()).besluit
		expect(
			far,
			'control: the link was not extended at all, so this test would prove nothing',
		).toBeTruthy()
		expect(
			typeof far,
			'the link came back as a bare identifier: nothing was projected',
		).toBe('object')

		// The two the link declares.
		expect(far.zaaknummer).toBe(`B-${RUN}`)
		expect(far.status).toBe('genomen')

		// The one it does not. WITHHELD, not absent: empty reads as "there is no
		// toelichting" and withheld reads as "you may not see it", and the two
		// send a reader to different places.
		expect(
			far.toelichting,
			'a field outside the declared set travelled through the link',
		).toBe(WITHHELD)

		// The envelope is not a field. A link that withheld the identity of the
		// record it points at would be unusable.
		expect(far.id, 'the link must still say which record it points at').toBe(
			besluitUuid,
		)
	})

	test('a link exposing a property the far schema does not declare is refused', async () => {
		const res = await admin.post(`${API}/schemas`, {
			data: {
				title: `e2e link exposure refused ${RUN}`,
				description: 'e2e',
				properties: {
					besluit: {
						$ref: besluitSlug,
						'x-openregister-relation': { type: 'gerelateerd' },
					},
				},
				configuration: {
					'x-openregister-relation-types': [
						{
							key: 'gerelateerd',
							label: 'related to',
							// A typo. Unrefused, it is simply absent from every
							// projection afterwards while its author reads a 200.
							exposes: ['zaaknummer', 'zaknummer'],
						},
					],
				},
				authorization: OPEN_TO_EVERYONE,
			},
		})

		expect(
			res.status(),
			`a link exposing an undeclared property was accepted: ${await res.text()}`,
		).toBe(422)
		expect(JSON.stringify(await res.json())).toContain('zaknummer')
	})
})
