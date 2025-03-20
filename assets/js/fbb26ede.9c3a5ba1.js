"use strict";(self.webpackChunkopen_catalogi_docs=self.webpackChunkopen_catalogi_docs||[]).push([[649],{5537:(e,n,t)=>{t.d(n,{A:()=>w});var s=t(6540),i=t(8215),r=t(5627),a=t(6347),o=t(372),l=t(604),c=t(1861),d=t(8749);function u(e){return s.Children.toArray(e).filter((e=>"\n"!==e)).map((e=>{if(!e||(0,s.isValidElement)(e)&&function(e){const{props:n}=e;return!!n&&"object"==typeof n&&"value"in n}(e))return e;throw new Error(`Docusaurus error: Bad <Tabs> child <${"string"==typeof e.type?e.type:e.type.name}>: all children of the <Tabs> component should be <TabItem>, and every <TabItem> should have a unique "value" prop.`)}))?.filter(Boolean)??[]}function h(e){const{values:n,children:t}=e;return(0,s.useMemo)((()=>{const e=n??function(e){return u(e).map((e=>{let{props:{value:n,label:t,attributes:s,default:i}}=e;return{value:n,label:t,attributes:s,default:i}}))}(t);return function(e){const n=(0,c.XI)(e,((e,n)=>e.value===n.value));if(n.length>0)throw new Error(`Docusaurus error: Duplicate values "${n.map((e=>e.value)).join(", ")}" found in <Tabs>. Every value needs to be unique.`)}(e),e}),[n,t])}function p(e){let{value:n,tabValues:t}=e;return t.some((e=>e.value===n))}function m(e){let{queryString:n=!1,groupId:t}=e;const i=(0,a.W6)(),r=function(e){let{queryString:n=!1,groupId:t}=e;if("string"==typeof n)return n;if(!1===n)return null;if(!0===n&&!t)throw new Error('Docusaurus error: The <Tabs> component groupId prop is required if queryString=true, because this value is used as the search param name. You can also provide an explicit value such as queryString="my-search-param".');return t??null}({queryString:n,groupId:t});return[(0,l.aZ)(r),(0,s.useCallback)((e=>{if(!r)return;const n=new URLSearchParams(i.location.search);n.set(r,e),i.replace({...i.location,search:n.toString()})}),[r,i])]}function g(e){const{defaultValue:n,queryString:t=!1,groupId:i}=e,r=h(e),[a,l]=(0,s.useState)((()=>function(e){let{defaultValue:n,tabValues:t}=e;if(0===t.length)throw new Error("Docusaurus error: the <Tabs> component requires at least one <TabItem> children component");if(n){if(!p({value:n,tabValues:t}))throw new Error(`Docusaurus error: The <Tabs> has a defaultValue "${n}" but none of its children has the corresponding value. Available values are: ${t.map((e=>e.value)).join(", ")}. If you intend to show no default tab, use defaultValue={null} instead.`);return n}const s=t.find((e=>e.default))??t[0];if(!s)throw new Error("Unexpected error: 0 tabValues");return s.value}({defaultValue:n,tabValues:r}))),[c,u]=m({queryString:t,groupId:i}),[g,j]=function(e){let{groupId:n}=e;const t=function(e){return e?`docusaurus.tab.${e}`:null}(n),[i,r]=(0,d.Dv)(t);return[i,(0,s.useCallback)((e=>{t&&r.set(e)}),[t,r])]}({groupId:i}),f=(()=>{const e=c??g;return p({value:e,tabValues:r})?e:null})();(0,o.A)((()=>{f&&l(f)}),[f]);return{selectedValue:a,selectValue:(0,s.useCallback)((e=>{if(!p({value:e,tabValues:r}))throw new Error(`Can't select invalid tab value=${e}`);l(e),u(e),j(e)}),[u,j,r]),tabValues:r}}var j=t(9136);const f={tabList:"tabList__CuJ",tabItem:"tabItem_LNqP"};var x=t(4848);function b(e){let{className:n,block:t,selectedValue:s,selectValue:a,tabValues:o}=e;const l=[],{blockElementScrollPositionUntilNextRender:c}=(0,r.a_)(),d=e=>{const n=e.currentTarget,t=l.indexOf(n),i=o[t].value;i!==s&&(c(n),a(i))},u=e=>{let n=null;switch(e.key){case"Enter":d(e);break;case"ArrowRight":{const t=l.indexOf(e.currentTarget)+1;n=l[t]??l[0];break}case"ArrowLeft":{const t=l.indexOf(e.currentTarget)-1;n=l[t]??l[l.length-1];break}}n?.focus()};return(0,x.jsx)("ul",{role:"tablist","aria-orientation":"horizontal",className:(0,i.A)("tabs",{"tabs--block":t},n),children:o.map((e=>{let{value:n,label:t,attributes:r}=e;return(0,x.jsx)("li",{role:"tab",tabIndex:s===n?0:-1,"aria-selected":s===n,ref:e=>{l.push(e)},onKeyDown:u,onClick:d,...r,className:(0,i.A)("tabs__item",f.tabItem,r?.className,{"tabs__item--active":s===n}),children:t??n},n)}))})}function v(e){let{lazy:n,children:t,selectedValue:r}=e;const a=(Array.isArray(t)?t:[t]).filter(Boolean);if(n){const e=a.find((e=>e.props.value===r));return e?(0,s.cloneElement)(e,{className:(0,i.A)("margin-top--md",e.props.className)}):null}return(0,x.jsx)("div",{className:"margin-top--md",children:a.map(((e,n)=>(0,s.cloneElement)(e,{key:n,hidden:e.props.value!==r})))})}function y(e){const n=g(e);return(0,x.jsxs)("div",{className:(0,i.A)("tabs-container",f.tabList),children:[(0,x.jsx)(b,{...n,...e}),(0,x.jsx)(v,{...n,...e})]})}function w(e){const n=(0,j.A)();return(0,x.jsx)(y,{...e,children:u(e.children)},String(n))}},8453:(e,n,t)=>{t.d(n,{R:()=>a,x:()=>o});var s=t(6540);const i={},r=s.createContext(i);function a(e){const n=s.useContext(r);return s.useMemo((function(){return"function"==typeof e?e(n):{...n,...e}}),[n,e])}function o(e){let n;return n=e.disableParentContext?"function"==typeof e.components?e.components(i):e.components||i:a(e.components),s.createElement(r.Provider,{value:n},e.children)}},8865:(e,n,t)=>{t.r(n),t.d(n,{assets:()=>d,contentTitle:()=>c,default:()=>p,frontMatter:()=>l,metadata:()=>s,toc:()=>u});const s=JSON.parse('{"id":"UseCases/notes","title":"Making Notes Stick","description":"Note Object: A Tale of Digital Sticky Notes","source":"@site/docs/UseCases/notes.md","sourceDirName":"UseCases","slug":"/UseCases/notes","permalink":"/docs/UseCases/notes","draft":false,"unlisted":false,"editUrl":"https://github.com/conductionnl/openregister/tree/main/website/docs/UseCases/notes.md","tags":[],"version":"current","sidebarPosition":4,"frontMatter":{"title":"Making Notes Stick","sidebar_position":4},"sidebar":"tutorialSidebar","previous":{"title":"Product and Service Catalogue","permalink":"/docs/UseCases/productServiceCatalogue"},"next":{"title":"File Attachments","permalink":"/docs/file-attachments"}}');var i=t(4848),r=t(8453),a=t(5537),o=t(9329);const l={title:"Making Notes Stick",sidebar_position:4},c="Note Taking in Open Register",d={},u=[{value:"Note Object: A Tale of Digital Sticky Notes",id:"note-object-a-tale-of-digital-sticky-notes",level:2},{value:"The Quest for the Perfect Note",id:"the-quest-for-the-perfect-note",level:3},{value:"The Magic of &quot;About&quot;",id:"the-magic-of-about",level:3},{value:"Implementation in Open Register",id:"implementation-in-open-register",level:3},{value:"Technical Implementation",id:"technical-implementation",level:3},{value:"Using Notes in Your Application",id:"using-notes-in-your-application",level:2},{value:"Creating a Note",id:"creating-a-note",level:3},{value:"Retrieving Notes About an Object",id:"retrieving-notes-about-an-object",level:3},{value:"Use Cases",id:"use-cases",level:2},{value:"Case Management",id:"case-management",level:3},{value:"Project Management",id:"project-management",level:3},{value:"Customer Relationship Management",id:"customer-relationship-management",level:3},{value:"Best Practices",id:"best-practices",level:2},{value:"Writing Effective Notes",id:"writing-effective-notes",level:3},{value:"Organizational Strategies",id:"organizational-strategies",level:3},{value:"Conclusion",id:"conclusion",level:2}];function h(e){const n={code:"code",h1:"h1",h2:"h2",h3:"h3",header:"header",li:"li",ol:"ol",p:"p",pre:"pre",strong:"strong",ul:"ul",...(0,r.R)(),...e.components};return(0,i.jsxs)(i.Fragment,{children:[(0,i.jsx)(n.header,{children:(0,i.jsx)(n.h1,{id:"note-taking-in-open-register",children:"Note Taking in Open Register"})}),"\n",(0,i.jsx)(n.h2,{id:"note-object-a-tale-of-digital-sticky-notes",children:"Note Object: A Tale of Digital Sticky Notes"}),"\n",(0,i.jsx)(n.p,{children:"Let's talk about notes - those digital breadcrumbs we leave everywhere in our systems. Whether you're a government employee making case notes, a manager jotting down meeting minutes, or a citizen service representative documenting a phone call, we all need to write things down. It's a fundamental human need that's followed us from paper notebooks into the digital age."}),"\n",(0,i.jsx)(n.h3,{id:"the-quest-for-the-perfect-note",children:"The Quest for the Perfect Note"}),"\n",(0,i.jsx)(n.p,{children:"In our journey to design the perfect note system, we looked at how different platforms handle note-taking:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Microsoft OneNote"})," with its rich formatting and organizational hierarchy"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Google Keep"})," focusing on simplicity and quick capture"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Evernote"})," balancing features with usability"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Schema.org's Comment type"})," providing a web-standard approach"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Nextcloud Notes"})," offering an open-source perspective"]}),"\n"]}),"\n",(0,i.jsx)(n.p,{children:"Each system had its strengths, but they all shared common elements: a title, content, timestamps, and some way to organize notes. We wanted to create something that could work with all of these systems - a note that could live in your phone's notes app, sync to your office OneNote, or appear in your government case management system."}),"\n",(0,i.jsx)(n.h3,{id:"the-magic-of-about",children:'The Magic of "About"'}),"\n",(0,i.jsx)(n.p,{children:'The real breakthrough came when we discovered Schema.org\'s "about" property. It\'s a simple but powerful concept: every note can be "about" something else. In technical terms, it\'s a URI or UUID pointing to another object in the system.'}),"\n",(0,i.jsx)(n.p,{children:"For example:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"A case worker can create a note 'about' a specific case"}),"\n",(0,i.jsx)(n.li,{children:"A manager can write meeting minutes 'about' a project"}),"\n",(0,i.jsx)(n.li,{children:"A support agent can log a conversation 'about' a customer"}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"implementation-in-open-register",children:"Implementation in Open Register"}),"\n",(0,i.jsx)(n.p,{children:"In Open Register, we've implemented notes as first-class objects with the following key features:"}),"\n",(0,i.jsxs)(n.ol,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Rich Text Support"}),": Notes can contain formatted text, lists, and links using Markdown"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Attachments"}),": Files can be added to notes for comprehensive documentation"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Relationships"}),": Notes can be linked to any other object in the system"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Version History"}),": All changes to notes are tracked and can be reviewed"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Access Control"}),": Notes inherit permissions from their parent objects"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"technical-implementation",children:"Technical Implementation"}),"\n",(0,i.jsx)(n.p,{children:"The note object schema includes:"}),"\n",(0,i.jsxs)(a.A,{children:[(0,i.jsx)(o.A,{value:"json",label:"JSON Schema",children:(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-json",children:'{\n  "$schema": "http://json-schema.org/draft-07/schema#",\n  "type": "object",\n  "title": "Note",\n  "description": "A note or comment about something",\n  "required": ["title", "content"],\n  "properties": {\n    "id": {\n      "type": "string",\n      "description": "Unique identifier for the note"\n    },\n    "title": {\n      "type": "string",\n      "description": "Title of the note"\n    },\n    "content": {\n      "type": "string",\n      "description": "Content of the note in Markdown format"\n    },\n    "about": {\n      "type": "string",\n      "format": "uri",\n      "description": "URI or UUID of the object this note is about"\n    },\n    "author": {\n      "type": "string",\n      "description": "Author of the note"\n    },\n    "dateCreated": {\n      "type": "string",\n      "format": "date-time",\n      "description": "Date and time when the note was created"\n    },\n    "dateModified": {\n      "type": "string",\n      "format": "date-time",\n      "description": "Date and time when the note was last modified"\n    },\n    "tags": {\n      "type": "array",\n      "items": {\n        "type": "string"\n      },\n      "description": "Tags or keywords associated with the note"\n    },\n    "attachments": {\n      "type": "array",\n      "items": {\n        "type": "object",\n        "properties": {\n          "id": {\n            "type": "string"\n          },\n          "name": {\n            "type": "string"\n          },\n          "contentType": {\n            "type": "string"\n          },\n          "url": {\n            "type": "string",\n            "format": "uri"\n          }\n        }\n      },\n      "description": "Files attached to the note"\n    }\n  }\n}\n'})})}),(0,i.jsx)(o.A,{value:"example",label:"Example Note",children:(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-json",children:'{\n  "id": "note-123456",\n  "title": "Meeting with Project Team",\n  "content": "# Discussion Points\\n\\n- Timeline review\\n- Budget concerns\\n- Resource allocation\\n\\n## Action Items\\n\\n1. John to update project plan by Friday\\n2. Sarah to contact vendors about pricing\\n3. Team to review resource requirements",\n  "about": "project-789012",\n  "author": "user-345678",\n  "dateCreated": "2023-05-15T10:30:00Z",\n  "dateModified": "2023-05-15T11:45:00Z",\n  "tags": ["meeting", "project", "action-items"],\n  "attachments": [\n    {\n      "id": "attachment-901234",\n      "name": "Project Timeline.pdf",\n      "contentType": "application/pdf",\n      "url": "/api/files/attachment-901234"\n    }\n  ]\n}\n'})})})]}),"\n",(0,i.jsx)(n.h2,{id:"using-notes-in-your-application",children:"Using Notes in Your Application"}),"\n",(0,i.jsx)(n.h3,{id:"creating-a-note",children:"Creating a Note"}),"\n",(0,i.jsx)(n.p,{children:"Notes can be created through the API or the user interface. When creating a note, you'll need to specify at minimum:"}),"\n",(0,i.jsxs)(n.ol,{children:["\n",(0,i.jsx)(n.li,{children:"A title"}),"\n",(0,i.jsx)(n.li,{children:"Content (in Markdown format)"}),"\n",(0,i.jsx)(n.li,{children:"The 'about' reference (what the note is about)"}),"\n"]}),"\n",(0,i.jsxs)(a.A,{children:[(0,i.jsx)(o.A,{value:"api",label:"API Request",children:(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-json",children:'POST /api/objects\n{\n  "register": "notes-register",\n  "schema": "note-schema",\n  "object": {\n    "title": "Customer Follow-up",\n    "content": "Called customer regarding their recent support ticket. They confirmed the issue is resolved.",\n    "about": "customer-567890"\n  }\n}\n'})})}),(0,i.jsxs)(o.A,{value:"ui",label:"User Interface",children:[(0,i.jsx)(n.p,{children:"The Open Register UI provides a note editor with:"}),(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsx)(n.li,{children:"A title field"}),"\n",(0,i.jsx)(n.li,{children:"A rich text editor with Markdown support"}),"\n",(0,i.jsx)(n.li,{children:"An 'about' selector to choose what the note relates to"}),"\n",(0,i.jsx)(n.li,{children:"File upload capabilities for attachments"}),"\n",(0,i.jsx)(n.li,{children:"Tag selection"}),"\n"]})]})]}),"\n",(0,i.jsx)(n.h3,{id:"retrieving-notes-about-an-object",children:"Retrieving Notes About an Object"}),"\n",(0,i.jsx)(n.p,{children:"One of the most powerful features is the ability to retrieve all notes about a specific object:"}),"\n",(0,i.jsxs)(a.A,{children:[(0,i.jsx)(o.A,{value:"api",label:"API Request",children:(0,i.jsx)(n.pre,{children:(0,i.jsx)(n.code,{className:"language-json",children:'GET /api/objects?register=notes-register&schema=note-schema&filter={"about":"customer-567890"}\n'})})}),(0,i.jsx)(o.A,{value:"ui",label:"User Interface",children:(0,i.jsx)(n.p,{children:'In the Open Register UI, notes are displayed in the context of their related objects. When viewing any object, its associated notes appear in a dedicated "Notes" tab or section.'})})]}),"\n",(0,i.jsx)(n.h2,{id:"use-cases",children:"Use Cases"}),"\n",(0,i.jsx)(n.h3,{id:"case-management",children:"Case Management"}),"\n",(0,i.jsx)(n.p,{children:"In a government case management system, notes are essential for documenting interactions, decisions, and progress:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Case Workers"})," can document client interactions"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Supervisors"})," can add review notes"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Specialists"})," can provide expert input"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Clients"})," can even add their own notes in self-service portals"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"project-management",children:"Project Management"}),"\n",(0,i.jsx)(n.p,{children:"For project teams, notes serve multiple purposes:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Meeting Minutes"})," capture discussions and decisions"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Status Updates"})," document progress"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Issue Notes"})," track problems and resolutions"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Decision Records"})," preserve the reasoning behind choices"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"customer-relationship-management",children:"Customer Relationship Management"}),"\n",(0,i.jsx)(n.p,{children:"In CRM systems, notes help maintain comprehensive customer histories:"}),"\n",(0,i.jsxs)(n.ul,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Support Interactions"})," document customer issues and resolutions"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Sales Notes"})," track conversations with prospects"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Account Management"})," records important customer preferences"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Internal Notes"})," share insights between team members"]}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"best-practices",children:"Best Practices"}),"\n",(0,i.jsx)(n.h3,{id:"writing-effective-notes",children:"Writing Effective Notes"}),"\n",(0,i.jsxs)(n.ol,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Be Specific"}),": Include relevant details, dates, and names"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Structure Content"}),": Use headings, lists, and formatting to organize information"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Focus on Facts"}),": Distinguish between observations and interpretations"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Include Next Steps"}),": Note any follow-up actions required"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Be Concise"}),": Keep notes clear and to the point"]}),"\n"]}),"\n",(0,i.jsx)(n.h3,{id:"organizational-strategies",children:"Organizational Strategies"}),"\n",(0,i.jsxs)(n.ol,{children:["\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Consistent Tagging"}),": Develop a standard set of tags for your organization"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Linking Related Notes"}),": Use the 'about' property to create relationships"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Regular Reviews"}),": Periodically review and clean up notes"]}),"\n",(0,i.jsxs)(n.li,{children:[(0,i.jsx)(n.strong,{children:"Template Usage"}),": Create templates for common note types"]}),"\n"]}),"\n",(0,i.jsx)(n.h2,{id:"conclusion",children:"Conclusion"}),"\n",(0,i.jsx)(n.p,{children:"Notes in Open Register bridge the gap between structured data and the messy reality of human communication. By implementing notes as first-class objects with rich relationships to other entities, we've created a flexible system that adapts to how people naturally work while maintaining the benefits of structured data."}),"\n",(0,i.jsx)(n.p,{children:"Whether you're implementing a case management system, a project tracking tool, or a customer relationship platform, the note object provides a powerful way to capture, organize, and retrieve the human side of your digital processes."})]})}function p(e={}){const{wrapper:n}={...(0,r.R)(),...e.components};return n?(0,i.jsx)(n,{...e,children:(0,i.jsx)(h,{...e})}):h(e)}},9329:(e,n,t)=>{t.d(n,{A:()=>a});t(6540);var s=t(8215);const i={tabItem:"tabItem_Ymn6"};var r=t(4848);function a(e){let{children:n,hidden:t,className:a}=e;return(0,r.jsx)("div",{role:"tabpanel",className:(0,s.A)(i.tabItem,a),hidden:t,children:n})}}}]);