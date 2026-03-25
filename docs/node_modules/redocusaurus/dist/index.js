"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.default = preset;
const path_1 = __importDefault(require("path"));
const utils_1 = require("@docusaurus/utils");
const DEFAULT_OPENAPI_OPTIONS = {
    path: 'openapi',
    routeBasePath: '/api',
};
async function preset(context, opts = {
    theme: {},
}) {
    const { debug = false, openapi, specs, theme = {}, config } = opts;
    if (debug) {
        console.error('[REDOCUSAURUS] Options:', opts);
    }
    const id = opts.id ? `-${opts.id}` : '';
    const themeId = `theme-redoc${id}`;
    if (debug) {
        console.error('[REDOCUSAURUS] ID Suffix:', id);
    }
    const specsArray = [];
    if (specs) {
        if (debug) {
            console.error('[REDOCUSAURUS] Specs Files:', specs);
        }
        specsArray.push(...(Array.isArray(specs) ? specs : [specs]));
    }
    if (!specs || openapi) {
        // Load folder if no specs provided or folder specifically provided
        const { path: folder, routeBasePath } = {
            ...DEFAULT_OPENAPI_OPTIONS,
            ...openapi,
        };
        const resolvedFolder = path_1.default.resolve(folder);
        if (debug) {
            console.error('[REDOCUSAURUS] Loading Folder:', {
                folder,
                resolvedFolder,
            });
        }
        const specFiles = await (0, utils_1.Globby)([
            `${folder}/**/*.openapi.{yaml,json}`,
            `${folder}/**/openapi.{yaml,json}`,
        ]);
        if (debug) {
            console.error('[REDOCUSAURUS] Found openapi files:', specFiles);
        }
        const slugger = (0, utils_1.createSlugger)();
        const baseRoute = routeBasePath.replace(/\/*$/, '');
        specsArray.push(...specFiles.map((specFile) => {
            const spec = path_1.default.resolve(specFile);
            const fileRoute = path_1.default
                .relative(resolvedFolder, spec)
                .replace(/(\/index)?\.openapi\.(yaml|json)$/, '')
                .replace(/\/*$/, '');
            const docRoute = `${baseRoute}/${fileRoute}`;
            return {
                id: slugger.slug(fileRoute),
                spec: spec,
                route: docRoute,
            };
        }));
    }
    if (debug) {
        console.error('[REDOCUSAURUS] All specs:', specsArray);
    }
    const resolvedPreset = {
        themes: [
            [
                require.resolve('docusaurus-theme-redoc'),
                {
                    id: themeId,
                    options: config,
                    ...theme,
                },
            ],
        ],
        plugins: [
            ...specsArray.map((pluginOpts, index) => [
                require.resolve('docusaurus-plugin-redoc'),
                {
                    config,
                    ...pluginOpts,
                    themeId,
                    id: pluginOpts.id || `plugin-redoc${id}-${index}`,
                    debug,
                },
            ]),
        ],
    };
    if (debug) {
        console.error('[REDOCUSAURUS] Final:', JSON.stringify(resolvedPreset, null, 2));
    }
    return resolvedPreset;
}
//# sourceMappingURL=index.js.map