(self.webpackChunkopen_catalogi_docs=self.webpackChunkopen_catalogi_docs||[]).push([[941],{2441:()=>{},3290:()=>{},5537:(e,n,s)=>{"use strict";s.d(n,{A:()=>S});var i=s(6540),r=s(8215),t=s(5627),a=s(6347),c=s(372),l=s(604),o=s(1861),d=s(8749);function h(e){return i.Children.toArray(e).filter((e=>"\n"!==e)).map((e=>{if(!e||(0,i.isValidElement)(e)&&function(e){const{props:n}=e;return!!n&&"object"==typeof n&&"value"in n}(e))return e;throw new Error(`Docusaurus error: Bad <Tabs> child <${"string"==typeof e.type?e.type:e.type.name}>: all children of the <Tabs> component should be <TabItem>, and every <TabItem> should have a unique "value" prop.`)}))?.filter(Boolean)??[]}function u(e){const{values:n,children:s}=e;return(0,i.useMemo)((()=>{const e=n??function(e){return h(e).map((e=>{let{props:{value:n,label:s,attributes:i,default:r}}=e;return{value:n,label:s,attributes:i,default:r}}))}(s);return function(e){const n=(0,o.XI)(e,((e,n)=>e.value===n.value));if(n.length>0)throw new Error(`Docusaurus error: Duplicate values "${n.map((e=>e.value)).join(", ")}" found in <Tabs>. Every value needs to be unique.`)}(e),e}),[n,s])}function p(e){let{value:n,tabValues:s}=e;return s.some((e=>e.value===n))}function m(e){let{queryString:n=!1,groupId:s}=e;const r=(0,a.W6)(),t=function(e){let{queryString:n=!1,groupId:s}=e;if("string"==typeof n)return n;if(!1===n)return null;if(!0===n&&!s)throw new Error('Docusaurus error: The <Tabs> component groupId prop is required if queryString=true, because this value is used as the search param name. You can also provide an explicit value such as queryString="my-search-param".');return s??null}({queryString:n,groupId:s});return[(0,l.aZ)(t),(0,i.useCallback)((e=>{if(!t)return;const n=new URLSearchParams(r.location.search);n.set(t,e),r.replace({...r.location,search:n.toString()})}),[t,r])]}function x(e){const{defaultValue:n,queryString:s=!1,groupId:r}=e,t=u(e),[a,l]=(0,i.useState)((()=>function(e){let{defaultValue:n,tabValues:s}=e;if(0===s.length)throw new Error("Docusaurus error: the <Tabs> component requires at least one <TabItem> children component");if(n){if(!p({value:n,tabValues:s}))throw new Error(`Docusaurus error: The <Tabs> has a defaultValue "${n}" but none of its children has the corresponding value. Available values are: ${s.map((e=>e.value)).join(", ")}. If you intend to show no default tab, use defaultValue={null} instead.`);return n}const i=s.find((e=>e.default))??s[0];if(!i)throw new Error("Unexpected error: 0 tabValues");return i.value}({defaultValue:n,tabValues:t}))),[o,h]=m({queryString:s,groupId:r}),[x,j]=function(e){let{groupId:n}=e;const s=function(e){return e?`docusaurus.tab.${e}`:null}(n),[r,t]=(0,d.Dv)(s);return[r,(0,i.useCallback)((e=>{s&&t.set(e)}),[s,t])]}({groupId:r}),g=(()=>{const e=o??x;return p({value:e,tabValues:t})?e:null})();(0,c.A)((()=>{g&&l(g)}),[g]);return{selectedValue:a,selectValue:(0,i.useCallback)((e=>{if(!p({value:e,tabValues:t}))throw new Error(`Can't select invalid tab value=${e}`);l(e),h(e),j(e)}),[h,j,t]),tabValues:t}}var j=s(9136);const g={tabList:"tabList__CuJ",tabItem:"tabItem_LNqP"};var f=s(4848);function v(e){let{className:n,block:s,selectedValue:i,selectValue:a,tabValues:c}=e;const l=[],{blockElementScrollPositionUntilNextRender:o}=(0,t.a_)(),d=e=>{const n=e.currentTarget,s=l.indexOf(n),r=c[s].value;r!==i&&(o(n),a(r))},h=e=>{let n=null;switch(e.key){case"Enter":d(e);break;case"ArrowRight":{const s=l.indexOf(e.currentTarget)+1;n=l[s]??l[0];break}case"ArrowLeft":{const s=l.indexOf(e.currentTarget)-1;n=l[s]??l[l.length-1];break}}n?.focus()};return(0,f.jsx)("ul",{role:"tablist","aria-orientation":"horizontal",className:(0,r.A)("tabs",{"tabs--block":s},n),children:c.map((e=>{let{value:n,label:s,attributes:t}=e;return(0,f.jsx)("li",{role:"tab",tabIndex:i===n?0:-1,"aria-selected":i===n,ref:e=>{l.push(e)},onKeyDown:h,onClick:d,...t,className:(0,r.A)("tabs__item",g.tabItem,t?.className,{"tabs__item--active":i===n}),children:s??n},n)}))})}function b(e){let{lazy:n,children:s,selectedValue:t}=e;const a=(Array.isArray(s)?s:[s]).filter(Boolean);if(n){const e=a.find((e=>e.props.value===t));return e?(0,i.cloneElement)(e,{className:(0,r.A)("margin-top--md",e.props.className)}):null}return(0,f.jsx)("div",{className:"margin-top--md",children:a.map(((e,n)=>(0,i.cloneElement)(e,{key:n,hidden:e.props.value!==t})))})}function y(e){const n=x(e);return(0,f.jsxs)("div",{className:(0,r.A)("tabs-container",g.tabList),children:[(0,f.jsx)(v,{...n,...e}),(0,f.jsx)(b,{...n,...e})]})}function S(e){const n=(0,j.A)();return(0,f.jsx)(y,{...e,children:h(e.children)},String(n))}},5673:(e,n,s)=>{"use strict";s.d(n,{A:()=>u});var i=s(6540),r=s(53),t=s(4404),a=(s(4345),s(8794)),c=s(4022),l=s(2077);function o(e){const n=(0,l.kh)("docusaurus-plugin-redoc");return e?n?.[e]:Object.values(n??{})?.[0]}var d=s(4848);const h=e=>{let{id:n,example:s,pointer:l,...h}=e;const u=o(n),{store:p}=(0,c.r)(u);return(0,i.useEffect)((()=>{p.menu.dispose()}),[p]),(0,d.jsx)(t.ThemeProvider,{theme:p.options.theme,children:(0,d.jsx)("div",{className:(0,r.A)(["redocusaurus","redocusaurus-schema",s?null:"hide-example"]),children:(0,d.jsx)(a.SchemaDefinition,{parser:p.spec.parser,options:p.options,schemaRef:l,...h})})})};h.defaultProps={example:!1};const u=h},7411:()=>{},7992:()=>{},8453:(e,n,s)=>{"use strict";s.d(n,{R:()=>a,x:()=>c});var i=s(6540);const r={},t=i.createContext(r);function a(e){const n=i.useContext(t);return i.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function c(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(r):e.components||r:a(e.components),i.createElement(t.Provider,{value:n},e.children)}},8825:()=>{},9146:(e,n,s)=>{"use strict";s.r(n),s.d(n,{assets:()=>l,contentTitle:()=>c,default:()=>h,frontMatter:()=>a,metadata:()=>i,toc:()=>o});const i=JSON.parse('{"id":"Features/schemas","title":"Schemas","description":"An overview of how core concepts in Open Register interact with each other.","source":"@site/docs/Features/schemas.md","sourceDirName":"Features","slug":"/Features/schemas","permalink":"/docs/Features/schemas","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/openregister/tree/main/website/docs/Features/schemas.md","tags":[],"version":"current","sidebarPosition":2,"frontMatter":{"title":"Schemas","sidebar_position":2,"description":"An overview of how core concepts in Open Register interact with each other.","keywords":["Open Register","Core Concepts","Relationships"]},"sidebar":"tutorialSidebar","previous":{"title":"Regsiters","permalink":"/docs/Features/registers"},"next":{"title":"Objects","permalink":"/docs/Features/objects"}}');var r=s(4848),t=s(8453);s(5673),s(5537),s(9329);const a={title:"Schemas",sidebar_position:2,description:"An overview of how core concepts in Open Register interact with each other.",keywords:["Open Register","Core Concepts","Relationships"]},c="Schemas",l={},o=[{value:"What is a Schema?",id:"what-is-a-schema",level:2},{value:"Schema Structure",id:"schema-structure",level:2},{value:"Property Structure",id:"property-structure",level:2},{value:"Example Schema",id:"example-schema",level:2},{value:"Schema Use Cases",id:"schema-use-cases",level:2},{value:"1. Data Validation",id:"1-data-validation",level:3},{value:"2. Documentation",id:"2-documentation",level:3},{value:"3. API Contract",id:"3-api-contract",level:3},{value:"4. UI Generation",id:"4-ui-generation",level:3},{value:"Working with Schemas",id:"working-with-schemas",level:2},{value:"Creating a Schema",id:"creating-a-schema",level:3},{value:"Retrieving Schema Information",id:"retrieving-schema-information",level:3},{value:"Updating a Schema",id:"updating-a-schema",level:3},{value:"Nesting schema&#39;s",id:"nesting-schemas",level:3},{value:"Schema Versioning",id:"schema-versioning",level:3},{value:"Schema Import &amp; Sharing",id:"schema-import--sharing",level:3},{value:"Overview",id:"overview",level:2},{value:"Import Sources",id:"import-sources",level:2},{value:"Schema.org",id:"schemaorg",level:3},{value:"OpenAPI Specification",id:"openapi-specification",level:3},{value:"Gemeentelijk Gegevensmodel (GGM)",id:"gemeentelijk-gegevensmodel-ggm",level:3},{value:"Open Catalogi",id:"open-catalogi",level:3},{value:"Schema Sharing",id:"schema-sharing",level:2},{value:"Key Benefits",id:"key-benefits",level:2},{value:"Schema Design Best Practices",id:"schema-design-best-practices",level:2},{value:"Relationship to Other Concepts",id:"relationship-to-other-concepts",level:2},{value:"Conclusion",id:"conclusion",level:2}];function d(e){const n={a:"a",code:"code",h1:"h1",h2:"h2",h3:"h3",header:"header",li:"li",ol:"ol",p:"p",pre:"pre",strong:"strong",table:"table",tbody:"tbody",td:"td",th:"th",thead:"thead",tr:"tr",ul:"ul",...(0,t.R)(),...e.components};return(0,r.jsxs)(r.Fragment,{children:[(0,r.jsx)(n.header,{children:(0,r.jsx)(n.h1,{id:"schemas",children:"Schemas"})}),"\n",(0,r.jsx)(n.h2,{id:"what-is-a-schema",children:"What is a Schema?"}),"\n",(0,r.jsxs)(n.p,{children:["In Open Register, a ",(0,r.jsx)(n.strong,{children:"Schema"})," defines the structure, validation rules, and relationships for data objects. Schemas act as blueprints that specify:"]}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsxs)(n.li,{children:["What ",(0,r.jsx)(n.strong,{children:"fields"})," an object should have"]}),"\n",(0,r.jsxs)(n.li,{children:["What ",(0,r.jsx)(n.strong,{children:"data types"})," those fields should be"]}),"\n",(0,r.jsxs)(n.li,{children:["Which fields are ",(0,r.jsx)(n.strong,{children:"required"})," vs. optional"]}),"\n",(0,r.jsxs)(n.li,{children:["Any ",(0,r.jsx)(n.strong,{children:"constraints"})," on field values (min/max, patterns, enums)"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Relationships"})," between different objects"]}),"\n"]}),"\n",(0,r.jsxs)(n.p,{children:["Open Register uses ",(0,r.jsx)(n.a,{href:"https://json-schema.org/",children:"JSON Schema"})," as its schema definition language, providing a powerful and standardized way to describe data structures."]}),"\n",(0,r.jsx)(n.h2,{id:"schema-structure",children:"Schema Structure"}),"\n",(0,r.jsxs)(n.p,{children:["A schema in Open Register follows the JSON Schema specification (see ",(0,r.jsx)(n.a,{href:"https://json-schema.org/understanding-json-schema",children:"JSON Schema Core"})," and ",(0,r.jsx)(n.a,{href:"https://json-schema.org/draft/2020-12/json-schema-validation.html",children:"JSON Schema Validation"}),") and consists of the following key components defined in the specification:"]}),"\n",(0,r.jsxs)(n.table,{children:[(0,r.jsx)(n.thead,{children:(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.th,{children:"Property"}),(0,r.jsx)(n.th,{children:"Description"})]})}),(0,r.jsxs)(n.tbody,{children:[(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"id"})}),(0,r.jsx)(n.td,{children:"Unique identifier for the schema"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"title"})}),(0,r.jsx)(n.td,{children:"Human-readable name of the schema"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"version"})}),(0,r.jsx)(n.td,{children:"Schema version in semantic versioning format"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"description"})}),(0,r.jsx)(n.td,{children:"Detailed explanation of what the schema represents"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"summary"})}),(0,r.jsx)(n.td,{children:"Brief summary of the schema's purpose"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"required"})}),(0,r.jsx)(n.td,{children:"Array of property names that are required"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"properties"})}),(0,r.jsx)(n.td,{children:"Object defining the properties and their types"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"archive"})}),(0,r.jsx)(n.td,{children:"Archive of previous schema versions"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"updated"})}),(0,r.jsx)(n.td,{children:"Timestamp of last update"})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"created"})}),(0,r.jsx)(n.td,{children:"Timestamp of creation"})]})]})]}),"\n",(0,r.jsx)(n.h2,{id:"property-structure",children:"Property Structure"}),"\n",(0,r.jsxs)(n.p,{children:["Before diving into schema examples, let's understand the key components of a property definition. These components are primarily derived from JSON Schema specifications (see ",(0,r.jsx)(n.a,{href:"https://json-schema.org/draft/2020-12/json-schema-validation.html",children:"JSON Schema Validation"}),") with some additional extensions required for storage and validation purposes:"]}),"\n",(0,r.jsxs)(n.table,{children:[(0,r.jsx)(n.thead,{children:(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.th,{children:"Property"}),(0,r.jsx)(n.th,{children:"Description"}),(0,r.jsx)(n.th,{children:"Example"})]})}),(0,r.jsxs)(n.tbody,{children:[(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.a,{href:"https://json-schema.org/understanding-json-schema/reference/type#type-specific-keywords",children:(0,r.jsx)(n.code,{children:"type"})})}),(0,r.jsx)(n.td,{children:"Data type of the property (string, number, boolean, object, array)"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"type": "string"'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.a,{href:"https://json-schema.org/understanding-json-schema/keywords#description",children:(0,r.jsx)(n.code,{children:"description"})})}),(0,r.jsx)(n.td,{children:"Human-readable explanation of the property's purpose"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"description": "Person\'s full name"'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.a,{href:"https://json-schema.org/understanding-json-schema/reference/type#format",children:(0,r.jsx)(n.code,{children:"format"})})}),(0,r.jsx)(n.td,{children:"Specific for the type (date, email, uri, etc)"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"format": "date-time"'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"pattern"})}),(0,r.jsx)(n.td,{children:"Regular expression pattern the value must match"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"pattern": "^[A-Z][a-z]+$"'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"enum"})}),(0,r.jsx)(n.td,{children:"Array of allowed values"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"enum": ["active", "inactive"]'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsxs)(n.td,{children:[(0,r.jsx)(n.code,{children:"minimum"}),"/",(0,r.jsx)(n.code,{children:"maximum"})]}),(0,r.jsx)(n.td,{children:"Numeric range constraints"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"minimum": 0, "maximum": 100'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsxs)(n.td,{children:[(0,r.jsx)(n.code,{children:"minLength"}),"/",(0,r.jsx)(n.code,{children:"maxLength"})]}),(0,r.jsx)(n.td,{children:"String length constraints"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"minLength": 3, "maxLength": 50'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"required"})}),(0,r.jsx)(n.td,{children:"Whether the property is mandatory"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"required": true'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"default"})}),(0,r.jsx)(n.td,{children:"Default value if none provided"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"default": "pending"'})})]}),(0,r.jsxs)(n.tr,{children:[(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:"examples"})}),(0,r.jsx)(n.td,{children:"Sample valid values"}),(0,r.jsx)(n.td,{children:(0,r.jsx)(n.code,{children:'"examples": ["John Smith"]'})})]})]})]}),"\n",(0,r.jsxs)(n.p,{children:["Properties can also have nested objects and arrays with their own validation rules, allowing for complex data structures while maintaining strict validation. See the ",(0,r.jsx)(n.a,{href:"#nesting-schemas",children:"Nesting schema's"})," section below for more details."]}),"\n",(0,r.jsx)(n.h2,{id:"example-schema",children:"Example Schema"}),"\n",(0,r.jsx)(n.pre,{children:(0,r.jsx)(n.code,{className:"language-json",children:'{\n  "id": "person",\n  "title": "Person",\n  "version": "1.0.0",\n  "description": "Schema for representing a person with basic information",\n  "summary": "Basic person information",\n  "required": ["firstName", "lastName", "birthDate"],\n  "properties": {\n    "firstName": {\n      "type": "string",\n      "description": "Person\'s first name"\n    },\n    "lastName": {\n      "type": "string",\n      "description": "Person\'s last name"\n    },\n    "birthDate": {\n      "type": "string",\n      "format": "date",\n      "description": "Person\'s date of birth in ISO 8601 format"\n    },\n    "email": {\n      "type": "string",\n      "format": "email",\n      "description": "Person\'s email address"\n    },\n    "address": {\n      "type": "object",\n      "description": "Person\'s address",\n      "properties": {\n        "street": { "type": "string" },\n        "city": { "type": "string" },\n        "postalCode": { "type": "string" },\n        "country": { "type": "string" }\n      }\n    },\n    "phoneNumbers": {\n      "type": "array",\n      "items": {\n        "type": "object",\n        "properties": {\n          "type": { \n            "type": "string",\n            "enum": ["home", "work", "mobile"]\n          },\n          "number": { "type": "string" }\n        }\n      }\n    }\n  },\n  "archive": {},\n  "updated": "2023-04-20T11:25:00Z",\n  "created": "2023-01-05T08:30:00Z"\n}\n'})}),"\n",(0,r.jsx)(n.h2,{id:"schema-use-cases",children:"Schema Use Cases"}),"\n",(0,r.jsx)(n.p,{children:"Schemas serve multiple purposes in Open Register:"}),"\n",(0,r.jsx)(n.h3,{id:"1-data-validation",children:"1. Data Validation"}),"\n",(0,r.jsx)(n.p,{children:"Schemas ensure that all data entering the system meets defined requirements, maintaining data quality and consistency."}),"\n",(0,r.jsx)(n.h3,{id:"2-documentation",children:"2. Documentation"}),"\n",(0,r.jsx)(n.p,{children:"Schemas serve as self-documenting specifications for data structures, helping developers understand what data is available and how it's organized."}),"\n",(0,r.jsx)(n.h3,{id:"3-api-contract",children:"3. API Contract"}),"\n",(0,r.jsx)(n.p,{children:"Schemas define the contract between different systems, specifying what data can be exchanged and in what format."}),"\n",(0,r.jsx)(n.h3,{id:"4-ui-generation",children:"4. UI Generation"}),"\n",(0,r.jsx)(n.p,{children:"Schemas can be used to automatically generate forms and other UI elements, ensuring that user interfaces align with data requirements."}),"\n",(0,r.jsx)(n.h2,{id:"working-with-schemas",children:"Working with Schemas"}),"\n",(0,r.jsx)(n.h3,{id:"creating-a-schema",children:"Creating a Schema"}),"\n",(0,r.jsx)(n.p,{children:"To create a new schema, you define its structure and validation rules:"}),"\n",(0,r.jsx)(n.pre,{children:(0,r.jsx)(n.code,{className:"language-json",children:'POST /api/schemas\n{\n  "title": "Product",\n  "version": "1.0.0",\n  "description": "Schema for product information",\n  "required": ["name", "sku", "price"],\n  "properties": {\n    "name": {\n      "type": "string",\n      "description": "Product name"\n    },\n    "sku": {\n      "type": "string",\n      "description": "Stock keeping unit"\n    },\n    "price": {\n      "type": "number",\n      "minimum": 0,\n      "description": "Product price"\n    },\n    "description": {\n      "type": "string",\n      "description": "Product description"\n    },\n    "category": {\n      "type": "string",\n      "description": "Product category"\n    }\n  }\n}\n'})}),"\n",(0,r.jsx)(n.h3,{id:"retrieving-schema-information",children:"Retrieving Schema Information"}),"\n",(0,r.jsx)(n.p,{children:"You can retrieve information about a specific schema:"}),"\n",(0,r.jsx)(n.pre,{children:(0,r.jsx)(n.code,{children:"GET /api/schemas/{id}\n"})}),"\n",(0,r.jsx)(n.p,{children:"Or list all available schemas:"}),"\n",(0,r.jsx)(n.pre,{children:(0,r.jsx)(n.code,{children:"GET /api/schemas\n"})}),"\n",(0,r.jsx)(n.h3,{id:"updating-a-schema",children:"Updating a Schema"}),"\n",(0,r.jsx)(n.p,{children:"Schemas can be updated to add new fields, change validation rules, or fix issues:"}),"\n",(0,r.jsx)(n.pre,{children:(0,r.jsx)(n.code,{className:"language-json",children:'PUT /api/schemas/{id}\n{\n  "title": "Product",\n  "version": "1.1.0",\n  "description": "Schema for product information",\n  "required": ["name", "sku", "price"],\n  "properties": {\n    "name": {\n      "type": "string",\n      "description": "Product name"\n    },\n    "sku": {\n      "type": "string",\n      "description": "Stock keeping unit"\n    },\n    "price": {\n      "type": "number",\n      "minimum": 0,\n      "description": "Product price"\n    },\n    "description": {\n      "type": "string",\n      "description": "Product description"\n    },\n    "category": {\n      "type": "string",\n      "description": "Product category"\n    },\n    "tags": {\n      "type": "array",\n      "items": {\n        "type": "string"\n      },\n      "description": "Product tags"\n    }\n  }\n}\n'})}),"\n",(0,r.jsx)(n.h3,{id:"nesting-schemas",children:"Nesting schema's"}),"\n",(0,r.jsx)(n.h3,{id:"schema-versioning",children:"Schema Versioning"}),"\n",(0,r.jsx)(n.h3,{id:"schema-import--sharing",children:"Schema Import & Sharing"}),"\n",(0,r.jsx)(n.p,{children:"Open Register provides powerful schema import capabilities, allowing organizations to leverage existing standards and share their own schemas through Open Catalogi."}),"\n",(0,r.jsx)(n.h2,{id:"overview",children:"Overview"}),"\n",(0,r.jsx)(n.p,{children:"The schema system supports importing from:"}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Schema.org definitions"}),"\n",(0,r.jsx)(n.li,{children:"OpenAPI Specification (OAS) files"}),"\n",(0,r.jsx)(n.li,{children:"Gemeentelijk Gegevensmodel (GGM)"}),"\n",(0,r.jsx)(n.li,{children:"Open Catalogi"}),"\n",(0,r.jsx)(n.li,{children:"Custom JSON Schema files"}),"\n"]}),"\n",(0,r.jsx)(n.h2,{id:"import-sources",children:"Import Sources"}),"\n",(0,r.jsx)(n.h3,{id:"schemaorg",children:"Schema.org"}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Import standard web vocabularies"}),"\n",(0,r.jsx)(n.li,{children:"Use established data structures"}),"\n",(0,r.jsx)(n.li,{children:"Benefit from widespread adoption"}),"\n",(0,r.jsx)(n.li,{children:"Maintain semantic compatibility"}),"\n"]}),"\n",(0,r.jsx)(n.h3,{id:"openapi-specification",children:"OpenAPI Specification"}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Import API definitions"}),"\n",(0,r.jsx)(n.li,{children:"Reuse existing data models"}),"\n",(0,r.jsx)(n.li,{children:"Maintain API compatibility"}),"\n",(0,r.jsx)(n.li,{children:"Leverage API documentation"}),"\n"]}),"\n",(0,r.jsx)(n.h3,{id:"gemeentelijk-gegevensmodel-ggm",children:"Gemeentelijk Gegevensmodel (GGM)"}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Import Dutch municipal data models"}),"\n",(0,r.jsx)(n.li,{children:"Comply with government standards"}),"\n",(0,r.jsx)(n.li,{children:"Ensure data compatibility"}),"\n",(0,r.jsx)(n.li,{children:"Support Common Ground principles"}),"\n"]}),"\n",(0,r.jsx)(n.h3,{id:"open-catalogi",children:"Open Catalogi"}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Share schemas between organizations"}),"\n",(0,r.jsx)(n.li,{children:"Import from central repositories"}),"\n",(0,r.jsx)(n.li,{children:"Collaborate on definitions"}),"\n",(0,r.jsx)(n.li,{children:"Version control schemas"}),"\n"]}),"\n",(0,r.jsx)(n.h2,{id:"schema-sharing",children:"Schema Sharing"}),"\n",(0,r.jsx)(n.p,{children:"Organizations can share their schemas through Open Catalogi:"}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Publish schemas publicly"}),"\n",(0,r.jsx)(n.li,{children:"Version control"}),"\n",(0,r.jsx)(n.li,{children:"Collaborative development"}),"\n",(0,r.jsx)(n.li,{children:"Change management"}),"\n",(0,r.jsx)(n.li,{children:"Documentation"}),"\n",(0,r.jsx)(n.li,{children:"Usage statistics"}),"\n"]}),"\n",(0,r.jsx)(n.h2,{id:"key-benefits",children:"Key Benefits"}),"\n",(0,r.jsxs)(n.ol,{children:["\n",(0,r.jsxs)(n.li,{children:["\n",(0,r.jsx)(n.p,{children:(0,r.jsx)(n.strong,{children:"Standardization"})}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Reuse existing standards"}),"\n",(0,r.jsx)(n.li,{children:"Ensure compatibility"}),"\n",(0,r.jsx)(n.li,{children:"Reduce development time"}),"\n",(0,r.jsx)(n.li,{children:"Share best practices"}),"\n"]}),"\n"]}),"\n",(0,r.jsxs)(n.li,{children:["\n",(0,r.jsx)(n.p,{children:(0,r.jsx)(n.strong,{children:"Collaboration"})}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Share schemas"}),"\n",(0,r.jsx)(n.li,{children:"Collaborate on definitions"}),"\n",(0,r.jsx)(n.li,{children:"Build on existing work"}),"\n",(0,r.jsx)(n.li,{children:"Community involvement"}),"\n"]}),"\n"]}),"\n",(0,r.jsxs)(n.li,{children:["\n",(0,r.jsx)(n.p,{children:(0,r.jsx)(n.strong,{children:"Maintenance"})}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsx)(n.li,{children:"Central updates"}),"\n",(0,r.jsx)(n.li,{children:"Version management"}),"\n",(0,r.jsx)(n.li,{children:"Change tracking"}),"\n",(0,r.jsx)(n.li,{children:"Documentation"}),"\n"]}),"\n"]}),"\n"]}),"\n",(0,r.jsx)(n.p,{children:"Open Register supports schema versioning to manage changes over time:"}),"\n",(0,r.jsxs)(n.ol,{children:["\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Minor Updates"}),": Adding optional fields or relaxing constraints"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Major Updates"}),": Adding required fields, removing fields, or changing field types"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Archive"}),": Previous versions are stored in the schema's archive property"]}),"\n"]}),"\n",(0,r.jsx)(n.h2,{id:"schema-design-best-practices",children:"Schema Design Best Practices"}),"\n",(0,r.jsxs)(n.ol,{children:["\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Start Simple"}),": Begin with the minimum required fields and add complexity as needed"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Use Clear Names"}),": Choose descriptive property names that reflect their purpose"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Add Descriptions"}),": Document each property with clear descriptions"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Consider Validation"}),": Add appropriate validation rules to ensure data quality"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Think About Relationships"}),": Design schemas with relationships in mind"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Plan for Evolution"}),": Design schemas to accommodate future changes"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Reuse Common Patterns"}),": Create reusable components for common data structures"]}),"\n"]}),"\n",(0,r.jsx)(n.h2,{id:"relationship-to-other-concepts",children:"Relationship to Other Concepts"}),"\n",(0,r.jsxs)(n.ul,{children:["\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Registers"}),": Registers specify which schemas they support"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Objects"}),": Objects must conform to a schema to be valid"]}),"\n",(0,r.jsxs)(n.li,{children:[(0,r.jsx)(n.strong,{children:"Validation"}),": The validation engine uses schemas to validate objects"]}),"\n"]}),"\n",(0,r.jsx)(n.h2,{id:"conclusion",children:"Conclusion"}),"\n",(0,r.jsx)(n.p,{children:"Schemas are the foundation of data quality in Open Register. By defining clear, consistent structures for your data, you ensure that all information in the system meets your requirements and can be reliably used across different applications and processes."})]})}function h(e={}){const{wrapper:n}={...(0,t.R)(),...e.components};return n?(0,r.jsx)(n,{...e,children:(0,r.jsx)(d,{...e})}):d(e)}},9329:(e,n,s)=>{"use strict";s.d(n,{A:()=>a});s(6540);var i=s(8215);const r={tabItem:"tabItem_Ymn6"};var t=s(4848);function a(e){let{children:n,hidden:s,className:a}=e;return(0,t.jsx)("div",{role:"tabpanel",className:(0,i.A)(r.tabItem,a),hidden:s,children:n})}}}]);