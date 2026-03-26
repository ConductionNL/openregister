"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.useSpecOptions = useSpecOptions;
const react_1 = require("react");
const useIsBrowser_1 = __importDefault(require("@docusaurus/useIsBrowser"));
const useGlobalData_1 = require("@docusaurus/useGlobalData");
const theme_common_1 = require("@docusaurus/theme-common");
const merge_1 = __importDefault(require("lodash/merge"));
require("../global");
/**
 * Redocusaurus
 * https://redocusaurus.vercel.app/
 * (c) 2024 Rohit Gohri
 * Released under the MIT License
 */
function useSpecOptions(themeId = 'theme-redoc', optionsOverrides) {
    const isBrowser = (0, useIsBrowser_1.default)();
    const isDarkTheme = (0, theme_common_1.useColorMode)().colorMode === 'dark';
    const defaultThemeOptions = (0, useGlobalData_1.useAllPluginInstancesData)('docusaurus-theme-redoc', {
        failfast: true,
    });
    const themeOptions = (0, useGlobalData_1.usePluginData)('docusaurus-theme-redoc', themeId) ||
        Object.values(defaultThemeOptions)[0];
    const result = (0, react_1.useMemo)(() => {
        const { lightTheme, darkTheme, options: redocOptions } = themeOptions;
        const commonOptions = {
            // Disable offset when server rendering and set to selector
            scrollYOffset: !isBrowser && typeof redocOptions.scrollYOffset === 'string'
                ? 0
                : redocOptions.scrollYOffset,
        };
        const lightThemeOptions = (0, merge_1.default)({
            ...redocOptions,
            ...commonOptions,
            theme: lightTheme,
        }, optionsOverrides);
        const darkThemeOptions = (0, merge_1.default)({
            ...redocOptions,
            ...commonOptions,
            theme: darkTheme,
        }, optionsOverrides);
        const options = isBrowser && isDarkTheme ? darkThemeOptions : lightThemeOptions;
        return {
            options,
            darkThemeOptions,
            lightThemeOptions,
        };
    }, [isBrowser, isDarkTheme, themeOptions, optionsOverrides]);
    return result;
}
//# sourceMappingURL=useSpecOptions.js.map