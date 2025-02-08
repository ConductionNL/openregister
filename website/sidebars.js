/** @type {import('@docusaurus/plugin-content-docs').SidebarsConfig} */
const sidebars = {
  tutorialSidebar: [
    {
      type: 'category',
      label: 'Getting Started',
      items: ['intro'],
    },
    {
      type: 'category',
      label: 'Features',
      items: [
        'audit-trails',
        'time-travel',
        'object-locking',
        'soft-deletes',
        'metadata',
        'object-relations',
        'file-attachments',
        'content-search',
        'automatic-facets',
        'elasticsearch',
        'schema-validation',
        'register-management',
        'access-control',
        'storing-objects'
      ],
    },
  ],
};

module.exports = sidebars; 