# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
"""Golden record of the Dolphin API server's routes.

torch, transformers and pdf2image are replaced by small fakes, so the routes
run without a model. Each case records the status, the JSON answer, what the
server printed and logged, and the calls it made to the fakes. Run with
GOLDEN_RECORD=1 to write the record instead of comparing with it.
"""

import base64
import contextlib
import importlib.util
import io
import json
import logging
import os
import sys
import types
import unittest

from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
GOLDEN = os.path.join(HERE, "api_server.golden.json")
CALLS = []
SETUP = {}


class FakeTensor:
    def __init__(self, label):
        self.label = label

    def cuda(self):
        CALLS.append(["tensor.cuda", self.label])
        return FakeTensor(self.label + "@cuda")


class FakeModel:
    def cuda(self):
        CALLS.append(["model.cuda"])
        return self

    def eval(self):
        CALLS.append(["model.eval"])

    def generate(self, **kwargs):
        CALLS.append(["generate", sorted((k, getattr(v, "label", v)) for k, v in kwargs.items())])
        return [kwargs["pixel_values"].label]


class FakeProcessor:
    def __call__(self, images, return_tensors):
        CALLS.append(["processor", images.mode, list(images.size), return_tensors])
        return {"pixel_values": FakeTensor(f"{images.size[0]}x{images.size[1]}")}

    def batch_decode(self, outputs, skip_special_tokens):
        CALLS.append(["batch_decode", outputs, skip_special_tokens])
        return [SETUP["texts"].get(outputs[0].split("@")[0], SETUP["texts"]["default"])]


def from_pretrained(kind):
    def load(path, trust_remote_code):
        CALLS.append([kind + ".from_pretrained", path, trust_remote_code])
        if SETUP.get("load_fails"):
            raise RuntimeError("no weights")
        return FakeModel() if kind == "model" else FakeProcessor()
    return load


def convert_from_path(path):
    CALLS.append(["convert_from_path", path.endswith(".pdf"), os.path.exists(path)])
    SETUP["pdf_path"] = path
    if SETUP.get("convert_fails"):
        raise RuntimeError("bad pdf")
    return [Image.new("RGB", (10 + n, 20)) for n in range(SETUP.get("pages", 0))]


def install_fakes():
    torch = types.ModuleType("torch")
    torch.cuda = types.SimpleNamespace(is_available=lambda: SETUP.get("cuda", False))
    torch.no_grad = contextlib.nullcontext
    transformers = types.ModuleType("transformers")
    transformers.VisionEncoderDecoderModel = types.SimpleNamespace(from_pretrained=from_pretrained("model"))
    transformers.AutoProcessor = types.SimpleNamespace(from_pretrained=from_pretrained("processor"))
    pdf2image = types.ModuleType("pdf2image")
    pdf2image.convert_from_path = convert_from_path
    sys.modules.update({"torch": torch, "transformers": transformers, "pdf2image": pdf2image})


def load_server():
    install_fakes()
    spec = importlib.util.spec_from_file_location("api_server", os.path.join(HERE, "..", "api_server.py"))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def png(mode="RGB", size=(30, 40)):
    out = io.BytesIO()
    Image.new(mode, size).save(out, format="PNG")
    return out.getvalue()


TEXTS = {
    "json": {"default": json.dumps({"text": "Hello", "layout": {"blocks": 1}, "tables": [[1]]})},
    "elements only": {"default": json.dumps({"elements": ["e"], "reading_order": [0]})},
    "empty json": {"default": "{}"},
    "plain text": {"default": "just text"},
    "json list": {"default": "[1, 2]"},
    "per page": {"10x20": json.dumps({"text": "one", "layout": {"p": 1}}), "11x20": "two", "default": "{}"},
}

PARSE = [
    ("rgb png", lambda: {"data": {"file": (io.BytesIO(png()), "a.png")}}),
    ("grey png", lambda: {"data": {"file": (io.BytesIO(png("L", (5, 6))), "a.png")}}),
    ("options off", lambda: {"data": {"file": (io.BytesIO(png()), "a.png"), "parse_layout": "FALSE", "extract_tables": "no"}}),
    ("not an image", lambda: {"data": {"file": (io.BytesIO(b"nope"), "a.png")}}),
    ("base64", lambda: {"json": {"image_base64": base64.b64encode(png()).decode()}}),
    ("json without image", lambda: {"json": {"other": 1}}),
    ("nothing", dict),
]

PDF = [
    ("no file", {}, dict),
    ("two pages", {"pages": 2}, lambda: {"data": {"file": (io.BytesIO(b"%PDF"), "a.pdf")}}),
    ("no pages", {"pages": 0}, lambda: {"data": {"file": (io.BytesIO(b"%PDF"), "a.pdf")}}),
    ("convert fails", {"convert_fails": True}, lambda: {"data": {"file": (io.BytesIO(b"%PDF"), "a.pdf")}}),
]


def run_case(server, path, setup, request, fresh=True):
    CALLS.clear()
    SETUP.clear()
    SETUP.update(setup)
    if fresh:
        server.dolphin_model = None
        server.dolphin_processor = None
    logged = []
    handler = logging.Handler()
    handler.emit = lambda record: logged.append([record.getMessage(), record.exc_info[0].__name__ if record.exc_info else None])
    server.app.logger.addHandler(handler)
    printed = io.StringIO()
    try:
        with contextlib.redirect_stdout(printed):
            answer = server.app.test_client().post(path, **request)
    finally:
        server.app.logger.removeHandler(handler)
    pdf_path = SETUP.get("pdf_path")
    return {
        "status": answer.status_code,
        "body": answer.get_json(),
        "printed": printed.getvalue().splitlines(),
        "logged": logged,
        "calls": json.loads(json.dumps(CALLS)),
        "temp_file_left": os.path.exists(pdf_path) if pdf_path else None,
    }


def all_cases():
    server = load_server()
    cases = {}
    for cuda in (False, True):
        for text_name, texts in TEXTS.items():
            for name, request in PARSE:
                setup = {"cuda": cuda, "texts": texts}
                cases[f"parse {name} / {text_name} / cuda={cuda}"] = run_case(server, "/parse", setup, request())
            for name, setup, request in PDF:
                setup = {**setup, "cuda": cuda, "texts": texts}
                cases[f"parse_pdf {name} / {text_name} / cuda={cuda}"] = run_case(server, "/parse_pdf", setup, request())
    for path in ("/parse", "/parse_pdf"):
        request = {"data": {"file": (io.BytesIO(png() if path == "/parse" else b"%PDF"), "a")}}
        cases[f"{path} model fails to load"] = run_case(server, path, {"load_fails": True, "pages": 1, "texts": TEXTS["json"]}, request)
    first = run_case(server, "/parse", {"texts": TEXTS["json"]}, PARSE[0][1]())
    cases["second request reuses the model"] = {
        "first": first,
        "second": run_case(server, "/parse_pdf", {"pages": 1, "texts": TEXTS["json"]}, PDF[1][2](), fresh=False),
    }
    for path in ("/health", "/info"):
        answer = server.app.test_client().get(path)
        cases[f"GET {path}"] = {"status": answer.status_code, "body": answer.get_json()}
    return cases


class ApiServerGolden(unittest.TestCase):
    def test_routes_match_the_golden_record(self):
        cases = all_cases()
        if os.environ.get("GOLDEN_RECORD"):
            with open(GOLDEN, "w") as fh:
                json.dump(cases, fh, indent=1, sort_keys=True)
                fh.write("\n")
        with open(GOLDEN) as fh:
            golden = json.load(fh)
        self.assertEqual(sorted(golden), sorted(cases))
        for name in golden:
            with self.subTest(name):
                self.assertEqual(golden[name], cases[name])


class Base64Image(unittest.TestCase):
    def test_a_base64_image_is_parsed_like_an_upload(self):
        server = load_server()
        setup = {"texts": TEXTS["json"]}
        sent = run_case(server, "/parse", setup, {"json": {"image_base64": base64.b64encode(png()).decode()}})
        uploaded = run_case(server, "/parse", setup, PARSE[0][1]())
        self.assertEqual(sent["status"], 200)
        self.assertEqual(sent["body"], uploaded["body"])
        self.assertEqual(sent["logged"], [])

    def test_invalid_base64_still_fails_with_500(self):
        server = load_server()
        sent = run_case(server, "/parse", {"texts": TEXTS["json"]}, {"json": {"image_base64": "not base64!"}})
        self.assertEqual(sent["status"], 500)
        self.assertEqual(sent["body"], {"error": "Document parsing failed"})


if __name__ == "__main__":
    unittest.main()
