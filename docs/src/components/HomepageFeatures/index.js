import React from 'react';
import clsx from 'clsx';
import styles from './styles.module.css';

const FeatureList = [
  {
    title: 'Schema-Driven Registers',
    description: (
      <>
        Define object types with JSON Schema and store them in configurable registers. Full CRUD with validation, relations, audit trails, and time travel.
      </>
    ),
  },
  {
    title: 'AI-Powered Search',
    description: (
      <>
        Built-in semantic search using PostgreSQL pgvector. Automatic vectorization, content classification, summarization, and translation — all local.
      </>
    ),
  },
  {
    title: 'Multi-Tenancy & RBAC',
    description: (
      <>
        Complete organisation-based data isolation with role-based access control. Designed for Dutch Common Ground and government compliance.
      </>
    ),
  },
];

function Feature({title, description}) {
  return (
    <div className={clsx('col col--4')}>
      <div className="text--center padding-horiz--md">
        <h3>{title}</h3>
        <p>{description}</p>
      </div>
    </div>
  );
}

export default function HomepageFeatures() {
  return (
    <section className={styles.features}>
      <div className="container">
        <div className="row">
          {FeatureList.map((props, idx) => (
            <Feature key={idx} {...props} />
          ))}
        </div>
      </div>
    </section>
  );
} 