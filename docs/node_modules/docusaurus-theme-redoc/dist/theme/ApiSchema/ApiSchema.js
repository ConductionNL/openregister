"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const react_1 = __importStar(require("react"));
const clsx_1 = __importDefault(require("clsx"));
const styled_components_1 = require("styled-components");
require("../../global");
const redoc_1 = require("redoc");
const useSpec_1 = require("../../utils/useSpec");
const useSpecData_1 = require("../useSpecData");
require("../Redoc/styles.css");
require("./styles.css");
const ApiSchema = ({ id, example, pointer, ...rest }) => {
    const specProps = (0, useSpecData_1.useSpecData)(id);
    const { store } = (0, useSpec_1.useSpec)(specProps);
    (0, react_1.useEffect)(() => {
        /**
         * @see https://github.com/Redocly/redoc/blob/823be24b313c3a2445df7e0801a0cc79c20bacd1/src/services/MenuStore.ts#L273-L276
         */
        store.menu.dispose();
    }, [store]);
    return (react_1.default.createElement(styled_components_1.ThemeProvider, { theme: store.options.theme },
        react_1.default.createElement("div", { className: (0, clsx_1.default)([
                'redocusaurus',
                'redocusaurus-schema',
                example ? null : 'hide-example',
            ]) },
            react_1.default.createElement(redoc_1.SchemaDefinition, { parser: store.spec.parser, options: store.options, schemaRef: pointer, ...rest }))));
};
ApiSchema.defaultProps = {
    example: false,
};
exports.default = ApiSchema;
//# sourceMappingURL=ApiSchema.js.map