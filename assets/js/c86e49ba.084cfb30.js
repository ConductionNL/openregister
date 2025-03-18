(self.webpackChunkopen_catalogi_docs=self.webpackChunkopen_catalogi_docs||[]).push([[5695],{2441:()=>{},3290:()=>{},5537:(e,r,n)=>{"use strict";n.d(r,{A:()=>S});var t=n(6540),s=n(8215),o=n(5627),i=n(6347),a=n(372),c=n(604),l=n(1861),d=n(8749);function u(e){return t.Children.toArray(e).filter((e=>"\n"!==e)).map((e=>{if(!e||(0,t.isValidElement)(e)&&function(e){const{props:r}=e;return!!r&&"object"==typeof r&&"value"in r}(e))return e;throw new Error(`Docusaurus error: Bad <Tabs> child <${"string"==typeof e.type?e.type:e.type.name}>: all children of the <Tabs> component should be <TabItem>, and every <TabItem> should have a unique "value" prop.`)}))?.filter(Boolean)??[]}function h(e){const{values:r,children:n}=e;return(0,t.useMemo)((()=>{const e=r??function(e){return u(e).map((e=>{let{props:{value:r,label:n,attributes:t,default:s}}=e;return{value:r,label:n,attributes:t,default:s}}))}(n);return function(e){const r=(0,l.XI)(e,((e,r)=>e.value===r.value));if(r.length>0)throw new Error(`Docusaurus error: Duplicate values "${r.map((e=>e.value)).join(", ")}" found in <Tabs>. Every value needs to be unique.`)}(e),e}),[r,n])}function p(e){let{value:r,tabValues:n}=e;return n.some((e=>e.value===r))}function g(e){let{queryString:r=!1,groupId:n}=e;const s=(0,i.W6)(),o=function(e){let{queryString:r=!1,groupId:n}=e;if("string"==typeof r)return r;if(!1===r)return null;if(!0===r&&!n)throw new Error('Docusaurus error: The <Tabs> component groupId prop is required if queryString=true, because this value is used as the search param name. You can also provide an explicit value such as queryString="my-search-param".');return n??null}({queryString:r,groupId:n});return[(0,c.aZ)(o),(0,t.useCallback)((e=>{if(!o)return;const r=new URLSearchParams(s.location.search);r.set(o,e),s.replace({...s.location,search:r.toString()})}),[o,s])]}function x(e){const{defaultValue:r,queryString:n=!1,groupId:s}=e,o=h(e),[i,c]=(0,t.useState)((()=>function(e){let{defaultValue:r,tabValues:n}=e;if(0===n.length)throw new Error("Docusaurus error: the <Tabs> component requires at least one <TabItem> children component");if(r){if(!p({value:r,tabValues:n}))throw new Error(`Docusaurus error: The <Tabs> has a defaultValue "${r}" but none of its children has the corresponding value. Available values are: ${n.map((e=>e.value)).join(", ")}. If you intend to show no default tab, use defaultValue={null} instead.`);return r}const t=n.find((e=>e.default))??n[0];if(!t)throw new Error("Unexpected error: 0 tabValues");return t.value}({defaultValue:r,tabValues:o}))),[l,u]=g({queryString:n,groupId:s}),[x,f]=function(e){let{groupId:r}=e;const n=function(e){return e?`docusaurus.tab.${e}`:null}(r),[s,o]=(0,d.Dv)(n);return[s,(0,t.useCallback)((e=>{n&&o.set(e)}),[n,o])]}({groupId:s}),m=(()=>{const e=l??x;return p({value:e,tabValues:o})?e:null})();(0,a.A)((()=>{m&&c(m)}),[m]);return{selectedValue:i,selectValue:(0,t.useCallback)((e=>{if(!p({value:e,tabValues:o}))throw new Error(`Can't select invalid tab value=${e}`);c(e),u(e),f(e)}),[u,f,o]),tabValues:o}}var f=n(9136);const m={tabList:"tabList__CuJ",tabItem:"tabItem_LNqP"};var j=n(4848);function b(e){let{className:r,block:n,selectedValue:t,selectValue:i,tabValues:a}=e;const c=[],{blockElementScrollPositionUntilNextRender:l}=(0,o.a_)(),d=e=>{const r=e.currentTarget,n=c.indexOf(r),s=a[n].value;s!==t&&(l(r),i(s))},u=e=>{let r=null;switch(e.key){case"Enter":d(e);break;case"ArrowRight":{const n=c.indexOf(e.currentTarget)+1;r=c[n]??c[0];break}case"ArrowLeft":{const n=c.indexOf(e.currentTarget)-1;r=c[n]??c[c.length-1];break}}r?.focus()};return(0,j.jsx)("ul",{role:"tablist","aria-orientation":"horizontal",className:(0,s.A)("tabs",{"tabs--block":n},r),children:a.map((e=>{let{value:r,label:n,attributes:o}=e;return(0,j.jsx)("li",{role:"tab",tabIndex:t===r?0:-1,"aria-selected":t===r,ref:e=>{c.push(e)},onKeyDown:u,onClick:d,...o,className:(0,s.A)("tabs__item",m.tabItem,o?.className,{"tabs__item--active":t===r}),children:n??r},r)}))})}function v(e){let{lazy:r,children:n,selectedValue:o}=e;const i=(Array.isArray(n)?n:[n]).filter(Boolean);if(r){const e=i.find((e=>e.props.value===o));return e?(0,t.cloneElement)(e,{className:(0,s.A)("margin-top--md",e.props.className)}):null}return(0,j.jsx)("div",{className:"margin-top--md",children:i.map(((e,r)=>(0,t.cloneElement)(e,{key:r,hidden:e.props.value!==o})))})}function y(e){const r=x(e);return(0,j.jsxs)("div",{className:(0,s.A)("tabs-container",m.tabList),children:[(0,j.jsx)(b,{...r,...e}),(0,j.jsx)(v,{...r,...e})]})}function S(e){const r=(0,f.A)();return(0,j.jsx)(y,{...e,children:u(e.children)},String(r))}},5673:(e,r,n)=>{"use strict";var t=n(6540),s=n(53),o=n(4404),i=(n(4345),n(8794)),a=n(4022),c=n(2077);function l(e){const r=(0,c.kh)("docusaurus-plugin-redoc");return e?r?.[e]:Object.values(r??{})?.[0]}var d=n(4848);const u=e=>{let{id:r,example:n,pointer:c,...u}=e;const h=l(r),{store:p}=(0,a.r)(h);return(0,t.useEffect)((()=>{p.menu.dispose()}),[p]),(0,d.jsx)(o.ThemeProvider,{theme:p.options.theme,children:(0,d.jsx)("div",{className:(0,s.A)(["redocusaurus","redocusaurus-schema",n?null:"hide-example"]),children:(0,d.jsx)(i.SchemaDefinition,{parser:p.spec.parser,options:p.options,schemaRef:c,...u})})})};u.defaultProps={example:!1}},7411:()=>{},7870:(e,r,n)=>{"use strict";n.r(r),n.d(r,{assets:()=>c,contentTitle:()=>a,default:()=>u,frontMatter:()=>i,metadata:()=>t,toc:()=>l});const t=JSON.parse('{"id":"Core/sources","title":"Sources","description":"What is a Source?","source":"@site/docs/Core/sources.md","sourceDirName":"Core","slug":"/Core/sources","permalink":"/docs/Core/sources","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/openregister/tree/main/website/docs/Core/sources.md","tags":[],"version":"current","sidebarPosition":5,"frontMatter":{"title":"Sources","sidebar_position":5},"sidebar":"tutorialSidebar","previous":{"title":"Objects","permalink":"/docs/Core/objects"},"next":{"title":"Files","permalink":"/docs/Core/files"}}');var s=n(4848),o=n(8453);n(5673),n(5537),n(9329);const i={title:"Sources",sidebar_position:5},a="Sources",c={},l=[{value:"What is a Source?",id:"what-is-a-source",level:2},{value:"Source Structure",id:"source-structure",level:2},{value:"Example Source",id:"example-source",level:2},{value:"Supported Source Types",id:"supported-source-types",level:2},{value:"1. Internal",id:"1-internal",level:3},{value:"2. MongoDB",id:"2-mongodb",level:3},{value:"3. Custom Sources (via Extensions)",id:"3-custom-sources-via-extensions",level:3},{value:"Source Use Cases",id:"source-use-cases",level:2},{value:"1. Storage Configuration",id:"1-storage-configuration",level:3},{value:"2. Performance Optimization",id:"2-performance-optimization",level:3},{value:"3. Data Segregation",id:"3-data-segregation",level:3},{value:"4. Scalability",id:"4-scalability",level:3},{value:"Working with Sources",id:"working-with-sources",level:2},{value:"Creating a Source",id:"creating-a-source",level:3},{value:"Retrieving Source Information",id:"retrieving-source-information",level:3},{value:"Updating a Source",id:"updating-a-source",level:3},{value:"Deleting a Source",id:"deleting-a-source",level:3},{value:"Source Configuration Best Practices",id:"source-configuration-best-practices",level:2},{value:"Relationship to Other Concepts",id:"relationship-to-other-concepts",level:2},{value:"Advanced Source Features",id:"advanced-source-features",level:2},{value:"Connection Pooling",id:"connection-pooling",level:3},{value:"Read/Write Separation",id:"readwrite-separation",level:3},{value:"Sharding and Partitioning",id:"sharding-and-partitioning",level:3},{value:"Conclusion",id:"conclusion",level:2}];function d(e){const r={code:"code",h1:"h1",h2:"h2",h3:"h3",header:"header",li:"li",ol:"ol",p:"p",pre:"pre",strong:"strong",table:"table",tbody:"tbody",td:"td",th:"th",thead:"thead",tr:"tr",ul:"ul",...(0,o.R)(),...e.components};return(0,s.jsxs)(s.Fragment,{children:[(0,s.jsx)(r.header,{children:(0,s.jsx)(r.h1,{id:"sources",children:"Sources"})}),"\n",(0,s.jsx)(r.h2,{id:"what-is-a-source",children:"What is a Source?"}),"\n",(0,s.jsxs)(r.p,{children:["In Open Register, a ",(0,s.jsx)(r.strong,{children:"Source"})," defines where and how data is stored. Sources provide the connection details and configuration for the storage backends that hold your registers and objects. They act as the bridge between your data model and the physical storage layer."]}),"\n",(0,s.jsx)(r.p,{children:"Sources allow Open Register to support multiple storage technologies, giving you flexibility in how you deploy and scale your data management solution."}),"\n",(0,s.jsx)(r.h2,{id:"source-structure",children:"Source Structure"}),"\n",(0,s.jsx)(r.p,{children:"A source in Open Register consists of the following key components:"}),"\n",(0,s.jsxs)(r.table,{children:[(0,s.jsx)(r.thead,{children:(0,s.jsxs)(r.tr,{children:[(0,s.jsx)(r.th,{children:"Property"}),(0,s.jsx)(r.th,{children:"Description"})]})}),(0,s.jsxs)(r.tbody,{children:[(0,s.jsxs)(r.tr,{children:[(0,s.jsx)(r.td,{children:(0,s.jsx)(r.code,{children:"id"})}),(0,s.jsx)(r.td,{children:"Unique identifier for the source"})]}),(0,s.jsxs)(r.tr,{children:[(0,s.jsx)(r.td,{children:(0,s.jsx)(r.code,{children:"title"})}),(0,s.jsx)(r.td,{children:"Human-readable name of the source"})]}),(0,s.jsxs)(r.tr,{children:[(0,s.jsx)(r.td,{children:(0,s.jsx)(r.code,{children:"description"})}),(0,s.jsx)(r.td,{children:"Detailed explanation of the source's purpose"})]}),(0,s.jsxs)(r.tr,{children:[(0,s.jsx)(r.td,{children:(0,s.jsx)(r.code,{children:"databaseUrl"})}),(0,s.jsx)(r.td,{children:"URL of the database"})]}),(0,s.jsxs)(r.tr,{children:[(0,s.jsx)(r.td,{children:(0,s.jsx)(r.code,{children:"type"})}),(0,s.jsx)(r.td,{children:"Type of the source (e.g., 'internal', 'mongodb')"})]}),(0,s.jsxs)(r.tr,{children:[(0,s.jsx)(r.td,{children:(0,s.jsx)(r.code,{children:"updated"})}),(0,s.jsx)(r.td,{children:"Timestamp of last update"})]}),(0,s.jsxs)(r.tr,{children:[(0,s.jsx)(r.td,{children:(0,s.jsx)(r.code,{children:"created"})}),(0,s.jsx)(r.td,{children:"Timestamp of creation"})]})]})]}),"\n",(0,s.jsx)(r.h2,{id:"example-source",children:"Example Source"}),"\n",(0,s.jsx)(r.pre,{children:(0,s.jsx)(r.code,{className:"language-json",children:'{\n  "id": "primary-source",\n  "title": "Primary Database",\n  "description": "Main database for production data",\n  "databaseUrl": "mongodb://localhost:27017/openregister",\n  "type": "mongodb",\n  "updated": "2023-03-10T16:45:00Z",\n  "created": "2023-01-01T00:00:00Z"\n}\n'})}),"\n",(0,s.jsx)(r.h2,{id:"supported-source-types",children:"Supported Source Types"}),"\n",(0,s.jsx)(r.p,{children:"Open Register supports multiple types of storage backends:"}),"\n",(0,s.jsx)(r.h3,{id:"1-internal",children:"1. Internal"}),"\n",(0,s.jsx)(r.p,{children:"The internal source type uses Nextcloud's built-in database for storage. This is the simplest option and works well for smaller deployments or when you want to keep everything within Nextcloud."}),"\n",(0,s.jsx)(r.h3,{id:"2-mongodb",children:"2. MongoDB"}),"\n",(0,s.jsx)(r.p,{children:"MongoDB sources provide scalable, document-oriented storage that works well with JSON data. This option is good for larger deployments or when you need advanced querying capabilities."}),"\n",(0,s.jsx)(r.h3,{id:"3-custom-sources-via-extensions",children:"3. Custom Sources (via Extensions)"}),"\n",(0,s.jsx)(r.p,{children:"The Open Register architecture allows for extending the system with custom source types through extensions, enabling integration with other database technologies or specialized storage systems."}),"\n",(0,s.jsx)(r.h2,{id:"source-use-cases",children:"Source Use Cases"}),"\n",(0,s.jsx)(r.p,{children:"Sources serve several important purposes in Open Register:"}),"\n",(0,s.jsx)(r.h3,{id:"1-storage-configuration",children:"1. Storage Configuration"}),"\n",(0,s.jsx)(r.p,{children:"Sources define where your data is physically stored, allowing you to choose the right database technology for your needs."}),"\n",(0,s.jsx)(r.h3,{id:"2-performance-optimization",children:"2. Performance Optimization"}),"\n",(0,s.jsx)(r.p,{children:"Different sources can be configured for different performance characteristics:"}),"\n",(0,s.jsxs)(r.ul,{children:["\n",(0,s.jsx)(r.li,{children:"High-throughput sources for frequently accessed data"}),"\n",(0,s.jsx)(r.li,{children:"Archival sources for historical data"}),"\n",(0,s.jsx)(r.li,{children:"In-memory sources for ultra-fast access to critical data"}),"\n"]}),"\n",(0,s.jsx)(r.h3,{id:"3-data-segregation",children:"3. Data Segregation"}),"\n",(0,s.jsx)(r.p,{children:"Multiple sources allow you to segregate data based on security requirements, regulatory needs, or organizational boundaries."}),"\n",(0,s.jsx)(r.h3,{id:"4-scalability",children:"4. Scalability"}),"\n",(0,s.jsx)(r.p,{children:"As your data grows, you can add new sources to distribute the load across multiple databases or clusters."}),"\n",(0,s.jsx)(r.h2,{id:"working-with-sources",children:"Working with Sources"}),"\n",(0,s.jsx)(r.h3,{id:"creating-a-source",children:"Creating a Source"}),"\n",(0,s.jsx)(r.p,{children:"To create a new source, you define its connection details and type:"}),"\n",(0,s.jsx)(r.pre,{children:(0,s.jsx)(r.code,{className:"language-json",children:'POST /api/sources\n{\n  "title": "Analytics Database",\n  "description": "Database for analytics data",\n  "databaseUrl": "mongodb://analytics.example.com:27017/analytics",\n  "type": "mongodb"\n}\n'})}),"\n",(0,s.jsx)(r.h3,{id:"retrieving-source-information",children:"Retrieving Source Information"}),"\n",(0,s.jsx)(r.p,{children:"You can retrieve information about a specific source:"}),"\n",(0,s.jsx)(r.pre,{children:(0,s.jsx)(r.code,{children:"GET /api/sources/{id}\n"})}),"\n",(0,s.jsx)(r.p,{children:"Or list all available sources:"}),"\n",(0,s.jsx)(r.pre,{children:(0,s.jsx)(r.code,{children:"GET /api/sources\n"})}),"\n",(0,s.jsx)(r.h3,{id:"updating-a-source",children:"Updating a Source"}),"\n",(0,s.jsx)(r.p,{children:"Sources can be updated to change connection details or other properties:"}),"\n",(0,s.jsx)(r.pre,{children:(0,s.jsx)(r.code,{className:"language-json",children:'PUT /api/sources/{id}\n{\n  "title": "Analytics Database",\n  "description": "Updated database for analytics data",\n  "databaseUrl": "mongodb://new-analytics.example.com:27017/analytics",\n  "type": "mongodb"\n}\n'})}),"\n",(0,s.jsx)(r.h3,{id:"deleting-a-source",children:"Deleting a Source"}),"\n",(0,s.jsx)(r.p,{children:"Sources can be deleted when no longer needed:"}),"\n",(0,s.jsx)(r.pre,{children:(0,s.jsx)(r.code,{children:"DELETE /api/sources/{id}\n"})}),"\n",(0,s.jsxs)(r.p,{children:[(0,s.jsx)(r.strong,{children:"Note"}),": Deleting a source does not delete the data in the underlying database. It only removes the connection configuration from Open Register."]}),"\n",(0,s.jsx)(r.h2,{id:"source-configuration-best-practices",children:"Source Configuration Best Practices"}),"\n",(0,s.jsxs)(r.ol,{children:["\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Use Descriptive Names"}),": Give sources clear, descriptive names that indicate their purpose"]}),"\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Document Connection Details"}),": Keep detailed documentation of connection strings and credentials"]}),"\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Monitor Performance"}),": Regularly monitor source performance and adjust as needed"]}),"\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Plan for Growth"}),": Design your source strategy with future growth in mind"]}),"\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Security First"}),": Use secure connection strings and follow database security best practices"]}),"\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Regular Backups"}),": Ensure all sources have appropriate backup strategies"]}),"\n"]}),"\n",(0,s.jsx)(r.h2,{id:"relationship-to-other-concepts",children:"Relationship to Other Concepts"}),"\n",(0,s.jsxs)(r.ul,{children:["\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Registers"}),": Registers are associated with sources that determine where their data is stored"]}),"\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Objects"}),": Objects are stored in the sources configured for their registers"]}),"\n",(0,s.jsxs)(r.li,{children:[(0,s.jsx)(r.strong,{children:"Databases"}),": Sources provide the connection details for the physical databases"]}),"\n"]}),"\n",(0,s.jsx)(r.h2,{id:"advanced-source-features",children:"Advanced Source Features"}),"\n",(0,s.jsx)(r.h3,{id:"connection-pooling",children:"Connection Pooling"}),"\n",(0,s.jsx)(r.p,{children:"For high-traffic deployments, sources can be configured with connection pooling to optimize database connections."}),"\n",(0,s.jsx)(r.h3,{id:"readwrite-separation",children:"Read/Write Separation"}),"\n",(0,s.jsx)(r.p,{children:"Some source types support configuring separate read and write endpoints, allowing you to optimize for different access patterns."}),"\n",(0,s.jsx)(r.h3,{id:"sharding-and-partitioning",children:"Sharding and Partitioning"}),"\n",(0,s.jsx)(r.p,{children:"For very large datasets, sources can be configured to support sharding or partitioning strategies."}),"\n",(0,s.jsx)(r.h2,{id:"conclusion",children:"Conclusion"}),"\n",(0,s.jsx)(r.p,{children:"Sources are a critical part of the Open Register architecture, providing the flexibility to choose the right storage technology for your needs while maintaining a consistent data model and API. By separating the storage configuration from the data model, Open Register allows you to evolve your storage strategy independently from your data structure, giving you the best of both worlds: structured data with flexible storage options."})]})}function u(e={}){const{wrapper:r}={...(0,o.R)(),...e.components};return r?(0,s.jsx)(r,{...e,children:(0,s.jsx)(d,{...e})}):d(e)}},7992:()=>{},8453:(e,r,n)=>{"use strict";n.d(r,{R:()=>i,x:()=>a});var t=n(6540);const s={},o=t.createContext(s);function i(e){const r=t.useContext(o);return t.useMemo((function(){return"function"==typeof e?e(r):{...r,...e}}),[r,e])}function a(e){let r;return r=e.disableParentContext?"function"==typeof e.components?e.components(s):e.components||s:i(e.components),t.createElement(o.Provider,{value:r},e.children)}},8825:()=>{},9329:(e,r,n)=>{"use strict";n.d(r,{A:()=>i});n(6540);var t=n(8215);const s={tabItem:"tabItem_Ymn6"};var o=n(4848);function i(e){let{children:r,hidden:n,className:i}=e;return(0,o.jsx)("div",{role:"tabpanel",className:(0,t.A)(s.tabItem,i),hidden:n,children:r})}}}]);