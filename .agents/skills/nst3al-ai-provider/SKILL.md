---
name: nst3al-ai-provider
description: Add or change an ns_t3al AI translation provider or model (OpenAI, Gemini, DeepL, Google Translate), batching, provider error handling or the Crowdin upload. Use when touching TranslationService, AiProviderResolver, *Service.php providers, LabelBatchUtility, DeepLLanguageMapper/Provider, CrowdinService or the Translate toolbar.
---

Load first: `context/features/ai-and-crowdin.md`.

## Invariants

- `AiProviderResolver::API_KEY_SETTINGS` is the only provider → ext_conf key map; resolution happens there, never a silent fallback to OpenAI.
- AI fill is **missing-only**; results go back to the grid (`overrideLabels`), never to disk.
- Batches are structured and count-checked (`LabelBatchUtility`, `TranslationService::BATCH_SIZE` 50); a batch without exactly the requested keys is rejected.
- Providers catch `\Throwable` and return `ProviderErrorTrait::providerFailure()`; network / 5xx set `retryLater` so remaining batches stop.
- Model names are allowlisted (`OpenAiService::ALLOWED_MODELS`, `GeminiService::ALLOWED_MODELS`).
- Keys / tokens only from Extension Configuration; never logged, flashed or rendered.
- Crowdin: system extensions only, explicit Save to Crowdin + project ID + `accessToken`; `CrowdinService` never throws.

## Adding a model

Add it in all three places: the service's `ALLOWED_MODELS`, `ext_conf_template.txt` options, `Partials/Module/Toolbar.html` options. Then `Tests/Unit/Service/*ServiceTest.php`.

## Adding a provider

1. Service in `Classes/Service/` using `ProviderErrorTrait` and `LabelBatchUtility` (keyed request, count-checked response, timeouts like the others: 20 s / connect 5 s).
2. Key + URL/model in `ext_conf_template.txt`; entry in `AiProviderResolver::API_KEY_SETTINGS`; dispatch in `TranslationService`.
3. Toolbar tab + `apiModelIs.<provider>` flag (no string comparison in Fluid); labels in `locallang.xlf` + `de.locallang.xlf`.
4. Unit tests with `RecordingRequestFactoryTrait` (no real HTTP); never put real keys in tests.
5. Update `context/features/ai-and-crowdin.md` + `context/core.md` ext_conf table (`nst3al-context-update`).
