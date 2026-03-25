"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.getSwizzleComponentList = void 0;
exports.default = redocTheme;
const path_1 = __importDefault(require("path"));
const redocData_1 = require("./redocData");
function redocTheme(context, options) {
    return {
        name: 'docusaurus-theme-redoc',
        configureWebpack(_config, isServer, { currentBundler }) {
            var _a;
            const bundler = (_a = currentBundler.instance) !== null && _a !== void 0 ? _a : require("webpack");
            return {
                plugins: [
                    new bundler.NormalModuleReplacementPlugin(/^tty$/, require.resolve('./tty')),
                    new bundler.DefinePlugin({
                        'process.versions.node': JSON.stringify(process.versions.node || '0.0.0'),
                        'process.platform': JSON.stringify(''),
                        // IMPORTANT: To fix debug library‘s bug #236
                        // {}.DEBUG = namespaces; // SyntaxError: Unexpected token '.'
                        'process.env.DEBUG': JSON.stringify(process.env.DEBUG),
                        'process.env': JSON.stringify({}),
                    }),
                    ...(isServer
                        ? [
                            new bundler.NormalModuleReplacementPlugin(/theme\/Redoc\/Styles/, path_1.default.join(__dirname, 'theme', 'Redoc', 'ServerStyles.js')),
                        ]
                        : []),
                ],
            };
        },
        async contentLoaded({ actions }) {
            const { setGlobalData } = actions;
            const globalData = await (0, redocData_1.getGlobalData)(options);
            setGlobalData(globalData);
        },
        getThemePath() {
            return path_1.default.join(__dirname, '..', 'dist-jsx', 'theme');
        },
        getTypeScriptThemePath() {
            return path_1.default.join(__dirname, '..', 'src', 'theme');
        },
        getClientModules() {
            return [
                path_1.default.join(__dirname, 'custom.css'),
                path_1.default.join(__dirname, 'global.js'),
            ];
        },
    };
}
const getSwizzleComponentList = () => {
    return ['Redoc', 'ApiDoc'];
};
exports.getSwizzleComponentList = getSwizzleComponentList;
//# sourceMappingURL=index.js.map