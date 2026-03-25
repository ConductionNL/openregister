"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.loadSpecWithConfig = loadSpecWithConfig;
const openapi_core_1 = require("@redocly/openapi-core");
/**
 * Based on loadAndBundleSpec from redoc.
 * Customized to pass custom loaded config
 * @see https://github.com/Redocly/redoc/blob/33be51a7a4068f44fd914314002c058a204ba0c2/src/utils/loadAndBundleSpec.ts
 */
async function loadSpecWithConfig(specUrlOrObject, config) {
    const bundleOpts = {
        config,
        base: process.cwd(),
    };
    if (typeof specUrlOrObject === 'object' && specUrlOrObject !== null) {
        bundleOpts.doc = {
            source: { absoluteRef: '' },
            parsed: specUrlOrObject,
        };
    }
    else {
        bundleOpts.ref = specUrlOrObject;
    }
    return (0, openapi_core_1.bundle)(bundleOpts);
}
//# sourceMappingURL=loadSpec.js.map