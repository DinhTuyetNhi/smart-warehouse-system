<?php
// AI configuration. Fill these to use a real AI service; otherwise stub will be used.
return [
    // If you have your own endpoint, fill these. Otherwise leave empty and use integrations below.
    'enabled' => true,
    'endpoint' => getenv('AI_ENDPOINT') ?: '',
    'api_key' => getenv('AI_API_KEY') ?: '',
    'timeout' => 12,

    // Option 2: Use Hugging Face and OCR integrations (free-tier/community)
    'use_hf' => true,
    'hf_token' => getenv('HF_TOKEN') ?: '',
    'hf_caption_model' => getenv('HF_CAPTION_MODEL') ?: 'Salesforce/blip-image-captioning-base',
    // Optional: image classification model (CLIP). If empty, we'll rely on caption heuristics.
    'hf_clip_model' => getenv('HF_CLIP_MODEL') ?: 'openai/clip-vit-base-patch32',

    'use_ocr' => true,
    'ocrspace_key' => getenv('OCRSPACE_KEY') ?: 'K84502711688957',
];
?>
