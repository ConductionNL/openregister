"use strict";(self.webpackChunkopen_catalogi_docs=self.webpackChunkopen_catalogi_docs||[]).push([[5695],{7870:(e,n,r)=>{r.r(n),r.d(n,{assets:()=>c,contentTitle:()=>a,default:()=>u,frontMatter:()=>o,metadata:()=>s,toc:()=>d});const s=JSON.parse('{"id":"Core/sources","title":"Sources","description":"What is a Source?","source":"@site/docs/Core/sources.md","sourceDirName":"Core","slug":"/Core/sources","permalink":"/docs/Core/sources","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/openregister/tree/main/website/docs/Core/sources.md","tags":[],"version":"current","sidebarPosition":5,"frontMatter":{"title":"Sources","sidebar_position":5},"sidebar":"tutorialSidebar","previous":{"title":"Objects","permalink":"/docs/Core/objects"},"next":{"title":"Files","permalink":"/docs/Core/files"}}');var i=r(4848),t=r(8453);const o={title:"Sources",sidebar_position:5},a="Sources",c={},d=[{value:"What is a Source?",id:"what-is-a-source",level:2},{value:"Source Structure",id:"source-structure",level:2},{value:"Example Source",id:"example-source",level:2},{value:"Supported Source Types",id:"supported-source-types",level:2},{value:"1. Internal",id:"1-internal",level:3},{value:"2. MongoDB",id:"2-mongodb",level:3},{value:"3. Custom Sources (via Extensions)",id:"3-custom-sources-via-extensions",level:3},{value:"Source Use Cases",id:"source-use-cases",level:2},{value:"1. Storage Configuration",id:"1-storage-configuration",level:3},{value:"2. Performance Optimization",id:"2-performance-optimization",level:3},{value:"3. Data Segregation",id:"3-data-segregation",level:3},{value:"4. Scalability",id:"4-scalability",level:3},{value:"Working with Sources",id:"working-with-sources",level:2},{value:"Creating a Source",id:"creating-a-source",level:3},{value:"Retrieving Source Information",id:"retrieving-source-information",level:3},{value:"Updating a Source",id:"updating-a-source",level:3},{value:"Deleting a Source",id:"deleting-a-source",level:3},{value:"Source Configuration Best Practices",id:"source-configuration-best-practices",level:2},{value:"Relationship to Other Concepts",id:"relationship-to-other-concepts",level:2},{value:"Advanced Source Features",id:"advanced-source-features",level:2},{value:"Connection Pooling",id:"connection-pooling",level:3},{value:"Read/Write Separation",id:"readwrite-separation",level:3},{value:"Sharding and Partitioning",id:"sharding-and-partitioning",level:3},{value:"Conclusion",id:"conclusion",level:2}];function l(e){const n={code:"code",h1:"h1",h2:"h2",h3:"h3",header:"header",li:"li",ol:"ol",p:"p",pre:"pre",strong:"strong",table:"table",tbody:"tbody",td:"td",th:"th",thead:"thead",tr:"tr",ul:"ul",...(0,t.R)(),...e.components};return(0,i.jsxs)(i.Fragment,{children:[(0,i.jsx)(n.header,{children:(0,i.jsx)(n.h1,{id:"sources",children:"Sources"})}),"\n",(0,i.jsx)(n.h2,{id:"what-is-a-source",children:"What is a Source?"}),"\n",(0,i.jsxs)(n.p,{children:["In Open Register, a ",(0,i.jsx)(n.strong,{children:"Source"})," defines where and how data is stored. Sources provide the connection details and configuration for the storage backends that hold your registers and objects. They act as the bridge between your data model and the physical storage layer."]}),"\n",(0,i.jsx)(n.p,{children:"Sources allow Open Register to support multiple storage technologies, giving you flexibility in how you deploy and scale your data management solution."}),"\n",(0,i.jsx)(n.h2,{id:"source-structure",children:"Source Structure"}),"\n",(0,i.jsx)(n.p,{children:"A source in Open Register consists of the following key components:"}),"\n",(0,i.jsxs)(n.table,{children:[(0,i.jsx)(n.thead,{children:(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.th,{children:"Property"}),(0,i.jsx)(n.th,{children:"Description"})]})}),(0,i.jsxs)(n.tbody,{children:[(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:(0,i.jsx)(n.code,{children:"id"})}),(0,i.jsx)(n.td,{children:"Unique identifier for the source"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:(0,i.jsx)(n.code,{children:"title"})}),(0,i.jsx)(n.td,{children:"Human-readable name of the source"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:(0,i.jsx)(n.code,{children:"description"})}),(0,i.jsx)(n.td,{children:"Detailed explanation of the source's purpose"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:(0,i.jsx)(n.code,{children:"databaseUrl"})}),(0,i.jsx)(n.td,{children:"URL of the database"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:(0,i.jsx)(n.code,{children:"type"})}),(0,i.jsx)(n.td,{children:"Type of the source (e.g., 'internal', 'mongodb')"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:(0,i.jsx)(n.code,{children:"updated"})}),(0,i.jsx)(n.td,{children:"Timestamp of last update"})]}),(0,i.jsxs)(n.tr,{children:[(0,i.jsx)(n.td,{children:(0,i.jsx)(n.code,{children:"created"})}),(0,i.jsx)(n.td,{children:"Timestamp of creation"})]})]})]}),"\n",(0,i.jsx)(n.h2,{id:"example-source",children:"Example Source"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-json",children:'{\n  "id": "primary-source",\n  "title": "Primary Database",\n  "description": "Main database for production data",\n  "databaseUrl": "mongodb://localhost:27017/openregister",\n  "type": "mongodb",\n  "updated": "2023-03-10T16:45:00Z",\n  "created": "2023-01-01T00:00:00Z"\n}\n'})}),"\n",(0,i.jsx)(n.h2,{id:"supported-source-types",children:"Supported Source Types"}),"\n",(0,i.jsx)(n.p,{children:"Open Register supports multiple types of storage backends:"}),"\n",(0,i.jsx)(n.h3,{id:"1-internal",children:"1. Internal"}),"\n",(0,i.jsx)(n.p,{children:"The internal source type uses Nextcloud's built-in database for storage. This is the simplest option and works well for smaller deployments or when you want to keep everything within Nextcloud."}),"\n",(0,i.jsx)(n.h3,{id:"2-mongodb",children:"2. MongoDB"}),"\n",(0,i.jsx)(n.p,{children:"MongoDB sources provide scalable, document-oriented storage that works well with JSON data. This option is good for larger deployments or when you need advanced querying capabilities."}),"\n",(0,i.jsx)(n.h3,{id:"3-custom-sources-via-extensions",children:"3. Custom Sources (via Extensions)"}),"\n",(0,i.jsx)(n.p,{children:"The Open Register architecture allows for extending the system with custom source types through extensions, enabling integration with other database technologies or specialized storage systems."}),"\n",(0,i.jsx)(n.h2,{id:"source-use-cases",children:"Source Use Cases"}),"\n",(0,i.jsx)(n.p,{children:"Sources serve several important purposes in Open Register:"}),"\n",(0,i.jsx)(n.h3,{id:"1-storage-configuration",children:"1. Storage Configuration"}),"\n",(0,i.jsx)(n.p,{children:"Sources define where your data is physically stored, allowing you to choose the right database technology for your needs."}),"\n",(0,i.jsx)(n.h3,{id:"2-performance-optimization",children:"2. Performance Optimization"}),"\n",(0,i.jsx)(n.p,{children:"Different sources can be configured for different performance characteristics:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"High-throughput sources for frequently accessed data"}),"\n",(0,i.jsx)(n.li,{children:"Archival sources for historical data"}),"\n",(0,i.jsx)(n.li,{children:"In-memory sources for ultra-fast access to critical data"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"3-data-segregation",children:"3. Data Segregation"}),"\n",(0,i.jsx)(n.p,{children:"Multiple sources allow you to segregate data based on security requirements, regulatory needs, or organizational boundaries."}),"\n",(0,i.jsx)(n.h3,{id:"4-scalability",children:"4. Scalability"}),"\n",(0,i.jsx)(n.p,{children:"As your data grows, you can add new sources to distribute the load across multiple databases or clusters."}),"\n",(0,i.jsx)(n.h2,{id:"working-with-sources",children:"Working with Sources"}),"\n",(0,i.jsx)(n.h3,{id:"creating-a-source",children:"Creating a Source"}),"\n",(0,i.jsx)(n.p,{children:"To create a new source, you define its connection details and type:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-json",children:'POST /api/sources\n{\n  "title": "Analytics Database",\n  "description": "Database for analytics data",\n  "databaseUrl": "mongodb://analytics.example.com:27017/analytics",\n  "type": "mongodb"\n}\n'})}),"\n",(0,i.jsx)(n.h3,{id:"retrieving-source-information",children:"Retrieving Source Information"}),"\n",(0,i.jsx)(n.p,{children:"You can retrieve information about a specific source:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{children:"GET /api/sources/{id}\n"})}),"\n",(0,i.jsx)(n.p,{children:"Or list all available sources:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{children:"GET /api/sources\n"})}),"\n",(0,i.jsx)(n.h3,{id:"updating-a-source",children:"Updating a Source"}),"\n",(0,i.jsx)(n.p,{children:"Sources can be updated to change connection details or other properties:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-json",children:'PUT /api/sources/{id}\n{\n  "title": "Analytics Database",\n  "description": "Updated database for analytics data",\n  "databaseUrl": "mongodb://new-analytics.example.com:27017/analytics",\n  "type": "mongodb"\n}\n'})}),"\n",(0,i.jsx)(n.h3,{id:"deleting-a-source",children:"Deleting a Source"}),"\n",(0,i.jsx)(n.p,{children:"Sources can be deleted when no longer needed:"}),"\n",(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{children:"DELETE /api/sources/{id}\n"})}),"\n",(0,i.jsxs)(n.p,{children:[(0,i.jsx)(n.strong,{children:"Note"}),": Deleting a source does not delete the data in the underlying database. It only removes the connection configuration from Open Register."]}),"\n",(0,i.jsx)(n.h2,{id:"source-configuration-best-practices",children:"Source Configuration Best Practices"}),"\n",(0,i.jsxs)(n.ol,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Use Descriptive Names"}),": Give sources clear, descriptive names that indicate their purpose"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Document Connection Details"}),": Keep detailed documentation of connection strings and credentials"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Monitor Performance"}),": Regularly monitor source performance and adjust as needed"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Plan for Growth"}),": Design your source strategy with future growth in mind"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Security First"}),": Use secure connection strings and follow database security best practices"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Regular Backups"}),": Ensure all sources have appropriate backup strategies"]}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"relationship-to-other-concepts",children:"Relationship to Other Concepts"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Registers"}),": Registers are associated with sources that determine where their data is stored"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Objects"}),": Objects are stored in the sources configured for their registers"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Databases"}),": Sources provide the connection details for the physical databases"]}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"advanced-source-features",children:"Advanced Source Features"}),"\n",(0,i.jsx)(n.h3,{id:"connection-pooling",children:"Connection Pooling"}),"\n",(0,i.jsx)(n.p,{children:"For high-traffic deployments, sources can be configured with connection pooling to optimize database connections."}),"\n",(0,i.jsx)(n.h3,{id:"readwrite-separation",children:"Read/Write Separation"}),"\n",(0,i.jsx)(n.p,{children:"Some source types support configuring separate read and write endpoints, allowing you to optimize for different access patterns."}),"\n",(0,i.jsx)(n.h3,{id:"sharding-and-partitioning",children:"Sharding and Partitioning"}),"\n",(0,i.jsx)(n.p,{children:"For very large datasets, sources can be configured to support sharding or partitioning strategies."}),"\n",(0,i.jsx)(n.h2,{id:"conclusion",children:"Conclusion"}),"\n",(0,i.jsx)(n.p,{children:"Sources are a critical part of the Open Register architecture, providing the flexibility to choose the right storage technology for your needs while maintaining a consistent data model and API. By separating the storage configuration from the data model, Open Register allows you to evolve your storage strategy independently from your data structure, giving you the best of both worlds: structured data with flexible storage options."})]})}function u(e={}){const{wrapper:n}={...(0,t.R)(),...e.components};return n?(0,i.jsx)(n,{...e,children:(0,i.jsx)(l,{...e})}):l(e)}},8453:(e,n,r)=>{r.d(n,{R:()=>o,x:()=>a});var s=r(6540);const i={},t=s.createContext(i);function o(e){const n=s.useContext(t);return s.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function a(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(i):e.components||i:o(e.components),s.createElement(t.Provider,{value:n},e.children)}}}]);