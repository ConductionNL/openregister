"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.loadRedoclyConfig = loadRedoclyConfig;
const openapi_core_1 = require("@redocly/openapi-core");
async function loadRedoclyConfig(config) {
    let redoclyConfig;
    if (config) {
        if (typeof config === 'string') {
            redoclyConfig = await (0, openapi_core_1.loadConfig)({
                configPath: config,
            });
        }
        else {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            redoclyConfig = new openapi_core_1.Config(config);
        }
    }
    else {
        redoclyConfig = await (0, openapi_core_1.loadConfig)();
    }
    return redoclyConfig;
}
//# sourceMappingURL=loadRedoclyConfig.js.map