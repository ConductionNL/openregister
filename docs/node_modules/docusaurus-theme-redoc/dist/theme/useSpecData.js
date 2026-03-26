"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.useSpecData = useSpecData;
const useGlobalData_1 = require("@docusaurus/useGlobalData");
/**
 *
 * @param id ID of plugin data
 * @returns Spec Data of ID or first one if ID is not provided
 */
function useSpecData(id) {
    var _a;
    const allData = (0, useGlobalData_1.useAllPluginInstancesData)('docusaurus-plugin-redoc');
    const apiData = id
        ? allData === null || allData === void 0 ? void 0 : allData[id]
        : (_a = Object.values(allData !== null && allData !== void 0 ? allData : {})) === null || _a === void 0 ? void 0 : _a[0];
    return apiData;
}
exports.default = useSpecData;
//# sourceMappingURL=useSpecData.js.map