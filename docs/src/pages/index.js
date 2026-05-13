/**
 * OpenRegister landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the connext page
 * at sites/www/src/pages/apps/openregister.mdx.
 *
 * Written as .js (not .mdx) because the docs site has the docs plugin
 * pointed at `path: './'`, and an MDX file in src/pages/ trips the
 * MDX-ESM parser even with the docs plugin's `src/**` exclude — likely
 * a quirk of how mdx-loader's micromark stack reuses parser state
 * across files in this Docusaurus 3.10 + this preset combination.
 * Authoring the page in JSX keeps the same component composition.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

const OPENREGISTER_ICON = (
  <svg viewBox="0 0 24 24">
    <ellipse cx="12" cy="6" rx="8" ry="3" />
    <path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6" />
    <path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3" />
  </svg>
);

const TAGLINE = (
  <>
    Schemas, registers, structured data objects. The typed-data backbone
    underneath every other Conduction app, and the install you reach for
    first. Define a schema once, get a REST and GraphQL API, validation,
    an audit log, and a citation-stable identifier per record.
  </>
);

function RecentRecordsPanel() {
  const tones = [
    'var(--c-forest-300)',
    'var(--c-lavender-300)',
    'var(--c-mint-300)',
    'var(--c-terracotta-300)',
    'var(--c-forest-300)',
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {tones.map((tone, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 18,
              height: 18,
              borderRadius: 2,
              background: tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              gap: 3,
            }}
          >
            <div
              style={{
                height: 4,
                width: '70%',
                background: 'var(--c-cobalt-700)',
                borderRadius: 1,
              }}
            />
            <div
              style={{
                height: 3,
                width: '50%',
                background: 'var(--c-cobalt-200)',
                borderRadius: 1,
              }}
            />
          </div>
          <div
            style={{
              height: 3,
              width: 22,
              background: 'var(--c-cobalt-100)',
              borderRadius: 1,
            }}
          />
        </div>
      ))}
    </div>
  );
}

function SchemaActivityPanel() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      <div style={{ display: 'flex', alignItems: 'baseline', gap: 6 }}>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 26,
            fontWeight: 700,
            color: 'var(--c-cobalt-900)',
          }}
        >
          847
        </div>
        <div
          style={{
            fontFamily: 'var(--conduction-typography-font-family-code)',
            fontSize: 11,
            fontWeight: 600,
            color: 'var(--c-mint-500)',
          }}
        >
          +12%
        </div>
      </div>
      <svg
        viewBox="0 0 200 60"
        preserveAspectRatio="none"
        style={{ width: '100%', height: 50 }}
      >
        <path
          d="M 0 48 L 28 36 L 56 42 L 84 24 L 112 30 L 140 16 L 168 22 L 200 8"
          stroke="var(--c-blue-cobalt)"
          strokeWidth="2"
          fill="none"
        />
        <path
          d="M 0 48 L 28 36 L 56 42 L 84 24 L 112 30 L 140 16 L 168 22 L 200 8 L 200 60 L 0 60 Z"
          fill="var(--c-cobalt-100)"
        />
        <circle cx="200" cy="8" r="3" fill="var(--c-orange-knvb)" />
      </svg>
    </div>
  );
}

function AuditTrailPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', label: 'WRITE', w: '85%' },
    { tone: 'var(--c-cobalt-300)', label: 'READ', w: '70%' },
    { tone: 'var(--c-lavender-300)', label: 'WRITE', w: '60%' },
    { tone: 'var(--c-cobalt-300)', label: 'READ', w: '50%' },
    { tone: 'var(--c-orange-knvb)', label: 'SCHEMA', w: '45%' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{ display: 'flex', alignItems: 'center', gap: 8 }}
        >
          <span
            style={{
              width: 14,
              height: 16,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 8,
              fontWeight: 700,
              letterSpacing: '0.05em',
              color: 'var(--c-cobalt-700)',
              minWidth: 42,
            }}
          >
            {row.label}
          </div>
          <div
            style={{
              flex: 1,
              height: 6,
              background: 'var(--c-cobalt-50)',
              borderRadius: 1,
              overflow: 'hidden',
            }}
          >
            <div
              style={{
                height: '100%',
                width: row.w,
                background: 'var(--c-cobalt-300)',
                borderRadius: 1,
              }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

const WIDGETS = [
  {
    title: 'Schema-driven JSON store',
    desc: 'Define a register shape in JSON Schema, get a typed object store. Every write is validated, every record lands with a citation-stable identifier.',
    panel: <RecentRecordsPanel />,
  },
  {
    title: 'REST and GraphQL, auto-generated',
    desc: 'Both APIs roll out of the schema. No controllers to write, no spec to update when the schema changes, no glue code between OpenRegister and the apps that consume it.',
    panel: <SchemaActivityPanel />,
  },
  {
    title: 'Signed audit log per record',
    desc: 'Every read, write, and schema change leaves a tamper-evident trail. WOO and BIO compliance evidence ships with the install, no spreadsheet exports at audit time.',
    panel: <AuditTrailPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="OpenRegister"
      description="Schema-driven object store with audit trail. The data foundation underneath every Conduction workspace app."
    >
      <main className="marketing-page">
        <DetailHero
          background="cobalt"
          appId="openregister"
          {/* status + version dropped — preset 2.10+ auto-derives from appinfo/info.xml */}
          locales="NL · EN"
          title="OpenRegister"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/openregister',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/openregister',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={OPENREGISTER_ICON}
          illustration={<AppMock app="openregister" />}
        />

        <WidgetShelf
          eyebrow="Widgets we ship"
          title="On every Nextcloud dashboard."
          lede="Install OpenRegister and the home screen surfaces what changed in your data, what people search for, and what slipped through validation."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
