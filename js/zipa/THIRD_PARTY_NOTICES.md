# ZIPA browser runtime

- The default model is the original **ZIPA-CR Small 300k INT8** export by
  Jian Zhu, Farhan Samir, Eleanor Chodroff, and David R. Mortensen, published
  by AnySpeech. LL Tools uses the
  model and its complete published token table without modifications. The
  model and token table are licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
  [Pinned model release](https://huggingface.co/anyspeech/zipa-small-crctc-300k/tree/2f458a4dd4102d49d2c7e54d9ccf69c660866256)
  · [ZIPA project](https://github.com/lingjzhu/zipa)
  · [Research paper](https://aclanthology.org/2025.acl-long.961/)
- `runtime/vendor/ort.wasm.min.mjs` and `runtime/vendor/ort-wasm-simd-threaded.mjs`
  are the original distributions from Microsoft ONNX Runtime Web 1.31.1.
  The matching WASM binary is downloaded on demand and verified by SHA-256.
  [Package](https://www.npmjs.com/package/onnxruntime-web/v/1.31.1)
  · [MIT license](runtime/vendor/LICENSE-onnxruntime.txt)
  · [Upstream component notices](https://github.com/microsoft/onnxruntime/blob/main/ThirdPartyNotices.txt)
