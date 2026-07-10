<?php

// Both paths point outside the repo by design: extracted/ holds live legacy
// credentials and goldens/ holds real PII, so neither may ever be committed
// (docs/09, docs/10). CI without these directories skips the golden suite.
return [
    'legacy_extracted_path' => env('LEGACY_EXTRACTED_PATH', base_path('../taicode/restore-db/extracted')),
    'goldens_path' => env('GOLDENS_PATH', base_path('../taicode/restore-db/goldens')),
];
