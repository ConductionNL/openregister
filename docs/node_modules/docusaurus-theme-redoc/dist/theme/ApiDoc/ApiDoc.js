"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const react_1 = __importDefault(require("react"));
const Layout_1 = __importDefault(require("@theme/Layout"));
const Redoc_1 = __importDefault(require("@theme/Redoc"));
function ApiDoc({ layoutProps, specProps }) {
    var _a, _b, _c, _d;
    const defaultTitle = ((_b = (_a = specProps.spec) === null || _a === void 0 ? void 0 : _a.info) === null || _b === void 0 ? void 0 : _b.title) || 'API Docs';
    const defaultDescription = ((_d = (_c = specProps.spec) === null || _c === void 0 ? void 0 : _c.info) === null || _d === void 0 ? void 0 : _d.description) || 'Open API Reference Docs for the API';
    return (react_1.default.createElement(Layout_1.default, { title: defaultTitle, description: defaultDescription, ...layoutProps },
        react_1.default.createElement(Redoc_1.default, { ...specProps })));
}
exports.default = ApiDoc;
//# sourceMappingURL=ApiDoc.js.map