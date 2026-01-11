---
license: mit
language:
  - zh
  - en
tags:
  - document-parsing
  - document-understanding
  - document-intelligence
  - ocr
  - layout-analysis
  - table-extraction
  - multimodal
  - vision-language-model
datasets:
  - custom
pipeline_tag: image-text-to-text
library_name: transformers
---


# Dolphin: Document Image Parsing via Heterogeneous Anchor Prompting

<a href="https://github.com/bytedance/Dolphin"><img src="https://img.shields.io/badge/Code-Github-blue"></a>

<!-- 
<div align="center">
  <img src="https://cdn.wandeer.world/null/dolphin_demo.gif" width="800">
</div>
 -->

## Model Description

Dolphin (**Do**cument Image **P**arsing via **H**eterogeneous Anchor Prompt**in**g) is a novel multimodal document image parsing model that follows an analyze-then-parse paradigm. It addresses the challenges of complex document understanding through a two-stage approach designed to handle intertwined elements such as text paragraphs, figures, formulas, and tables.

## 📑 Overview

Document image parsing is challenging due to its complexly intertwined elements such as text paragraphs, figures, formulas, and tables. Dolphin addresses these challenges through a two-stage approach:

1. **🔍 Stage 1**: Comprehensive page-level layout analysis by generating element sequence in natural reading order
2. **🧩 Stage 2**: Efficient parallel parsing of document elements using heterogeneous anchors and task-specific prompts

<!-- <div align="center">
  <img src="https://cdn.wandeer.world/null/dolphin_framework.png" width="680">
</div> -->

Dolphin achieves promising performance across diverse page-level and element-level parsing tasks while ensuring superior efficiency through its lightweight architecture and parallel parsing mechanism.

## Model Architecture

Dolphin is built on a vision-encoder-decoder architecture using transformers:

- **Vision Encoder**: Based on Swin Transformer for extracting visual features from document images
- **Text Decoder**: Based on MBart for decoding text from visual features
- **Prompt-based interface**: Uses natural language prompts to control parsing tasks

The model is implemented as a Hugging Face `VisionEncoderDecoderModel` for easy integration with the Transformers ecosystem.

## Usage

Our demo will be released in these days. Please keep tuned! 🔥

Please refer to our [GitHub repository](https://github.com/bytedance/Dolphin) for detailed usage.

- [Page-wise parsing](https://github.com/bytedance/Dolphin/demo_page_hf.py): for an entire document image
- [Element-wise parsing](https://github.com/bytedance/Dolphin/demo_element_hf.py): for an element (paragraph, table, formula) image


## License

This model is released under the MIT License.

## Citation

```bibtex
@inproceedings{dolphin2025,
  title={Dolphin: Document Image Parsing via Heterogeneous Anchor Prompting},
  author={Feng, Hao and Wei, Shu and Fei, Xiang and Shi, Wei and Han, Yingdong and Liao, Lei and Lu, Jinghui and Wu, Binghong and Liu, Qi and Lin, Chunhui and Tang, Jingqun and Liu, Hao and Huang, Can},
  year={2025},
  booktitle={Proceedings of the 65rd Annual Meeting of the Association for Computational Linguistics (ACL)}
}
```

## Acknowledgements

This model builds on several open-source projects including:
- [Hugging Face Transformers](https://github.com/huggingface/transformers)
- [Donut](https://github.com/clovaai/donut/)
- [Nougat](https://github.com/facebookresearch/nougat)
- [Swin Transformer](https://github.com/microsoft/Swin-Transformer) 