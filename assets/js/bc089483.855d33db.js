"use strict";(self.webpackChunkopen_register_docs=self.webpackChunkopen_register_docs||[]).push([[710],{2439:(e,t,r)=>{r.r(t),r.d(t,{assets:()=>p,contentTitle:()=>i,default:()=>d,frontMatter:()=>a,metadata:()=>l,toc:()=>c});var n=r(8168),o=(r(6540),r(5680));const a={},i="Developer Guide",l={unversionedId:"developers",id:"developers",title:"Developer Guide",description:"Overview",source:"@site/docs/developers.md",sourceDirName:".",slug:"/developers",permalink:"/openregister/docs/developers",draft:!1,editUrl:"https://github.com/conductionnl/openregister/tree/main/website/docs/developers.md",tags:[],version:"current",frontMatter:{}},p={},c=[{value:"Overview",id:"overview",level:2},{value:"Project Structure",id:"project-structure",level:2},{value:"Schemas",id:"schemas",level:2},{value:"Additional Information",id:"additional-information",level:2}],s={toc:c},u="wrapper";function d(e){let{components:t,...r}=e;return(0,o.yg)(u,(0,n.A)({},s,r,{components:t,mdxType:"MDXLayout"}),(0,o.yg)("h1",{id:"developer-guide"},"Developer Guide"),(0,o.yg)("h2",{id:"overview"},"Overview"),(0,o.yg)("p",null,"This is a Nextcloud application. Below you will find important information about the project structure and where to find various components."),(0,o.yg)("h2",{id:"project-structure"},"Project Structure"),(0,o.yg)("ul",null,(0,o.yg)("li",{parentName:"ul"},(0,o.yg)("p",{parentName:"li"},(0,o.yg)("strong",{parentName:"p"},"appinfo/routes.php"),": Defines the routes for the application. For example:"),(0,o.yg)("pre",{parentName:"li"},(0,o.yg)("code",{parentName:"pre",className:"language-php"},"<?php\n\nreturn [\n  'routes' => [\n    ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],\n  ],\n];\n"))),(0,o.yg)("li",{parentName:"ul"},(0,o.yg)("p",{parentName:"li"},(0,o.yg)("strong",{parentName:"p"},"lib"),": Contains all the PHP code for the application.")),(0,o.yg)("li",{parentName:"ul"},(0,o.yg)("p",{parentName:"li"},(0,o.yg)("strong",{parentName:"p"},"src"),": Contains all the Vue.js code for the application.")),(0,o.yg)("li",{parentName:"ul"},(0,o.yg)("p",{parentName:"li"},(0,o.yg)("strong",{parentName:"p"},"docs"),": Contains documentation files."))),(0,o.yg)("h2",{id:"schemas"},"Schemas"),(0,o.yg)("p",null,"Schemas are defined using JSON Schema format. These schemas are used to validate the structure of data within the application."),(0,o.yg)("h2",{id:"additional-information"},"Additional Information"),(0,o.yg)("p",null,"For more detailed information on how to contribute to this project, please refer to the other documentation files in the ",(0,o.yg)("inlineCode",{parentName:"p"},"docs")," folder."))}d.isMDXComponent=!0},5680:(e,t,r)=>{r.d(t,{xA:()=>s,yg:()=>f});var n=r(6540);function o(e,t,r){return t in e?Object.defineProperty(e,t,{value:r,enumerable:!0,configurable:!0,writable:!0}):e[t]=r,e}function a(e,t){var r=Object.keys(e);if(Object.getOwnPropertySymbols){var n=Object.getOwnPropertySymbols(e);t&&(n=n.filter((function(t){return Object.getOwnPropertyDescriptor(e,t).enumerable}))),r.push.apply(r,n)}return r}function i(e){for(var t=1;t<arguments.length;t++){var r=null!=arguments[t]?arguments[t]:{};t%2?a(Object(r),!0).forEach((function(t){o(e,t,r[t])})):Object.getOwnPropertyDescriptors?Object.defineProperties(e,Object.getOwnPropertyDescriptors(r)):a(Object(r)).forEach((function(t){Object.defineProperty(e,t,Object.getOwnPropertyDescriptor(r,t))}))}return e}function l(e,t){if(null==e)return{};var r,n,o=function(e,t){if(null==e)return{};var r,n,o={},a=Object.keys(e);for(n=0;n<a.length;n++)r=a[n],t.indexOf(r)>=0||(o[r]=e[r]);return o}(e,t);if(Object.getOwnPropertySymbols){var a=Object.getOwnPropertySymbols(e);for(n=0;n<a.length;n++)r=a[n],t.indexOf(r)>=0||Object.prototype.propertyIsEnumerable.call(e,r)&&(o[r]=e[r])}return o}var p=n.createContext({}),c=function(e){var t=n.useContext(p),r=t;return e&&(r="function"==typeof e?e(t):i(i({},t),e)),r},s=function(e){var t=c(e.components);return n.createElement(p.Provider,{value:t},e.children)},u="mdxType",d={inlineCode:"code",wrapper:function(e){var t=e.children;return n.createElement(n.Fragment,{},t)}},m=n.forwardRef((function(e,t){var r=e.components,o=e.mdxType,a=e.originalType,p=e.parentName,s=l(e,["components","mdxType","originalType","parentName"]),u=c(r),m=o,f=u["".concat(p,".").concat(m)]||u[m]||d[m]||a;return r?n.createElement(f,i(i({ref:t},s),{},{components:r})):n.createElement(f,i({ref:t},s))}));function f(e,t){var r=arguments,o=t&&t.mdxType;if("string"==typeof e||o){var a=r.length,i=new Array(a);i[0]=m;var l={};for(var p in t)hasOwnProperty.call(t,p)&&(l[p]=t[p]);l.originalType=e,l[u]="string"==typeof e?e:o,i[1]=l;for(var c=2;c<a;c++)i[c]=r[c];return n.createElement.apply(null,i)}return n.createElement.apply(null,r)}m.displayName="MDXCreateElement"}}]);