<?php

namespace App\Scoring\Engine;

use RuntimeException;

/**
 * Catalog of licensable assessment products, each addressed by an opaque
 * access code rather than a hardcoded engine identity (docs/04: "product-
 * agnostic engine"; docs/07: access codes + royalties). A product is just a
 * version-bundle + tool-version map + royalty terms — new products are
 * catalog entries, never new engine code.
 *
 * This class is the in-code placeholder for what phase 5 promotes into a
 * real `access_codes` table (issuance, scopes, usage metering). A code can
 * carry more than one payable royalty term by design — e.g. a base per-report
 * fee plus a separate revenue share to a different rights-holder.
 */
class ProductCatalog
{
    public const DEFAULT_CODE = 'VC18';

    /**
     * code => {
     *   label: human-readable name, informational only — the engine never
     *     branches on it,
     *   versionControlKey, packageVersionKey, profileVersionKey,
     *   insightScoreVersionKey: the legacy version bundle this code scores,
     *   toolVersions: api tool name => ToolVersionKey for this product,
     *   royalties: list of independent payable terms for this code (empty
     *     list = no royalty due, e.g. a derivative-type code per docs/07),
     * }
     */
    public const PRODUCTS = [
        self::DEFAULT_CODE => [
            'label' => 'Professional Development assessment (legacy product name)',
            'versionControlKey' => 18,
            'packageVersionKey' => 27,
            'profileVersionKey' => 19,
            'insightScoreVersionKey' => 21,
            'toolVersions' => [
                'reflections' => 29,
                'personalmotivators' => 48,
                'areamissions' => 47,
                'abilitiesfilter' => 28,
                'personalstyle' => 49,
                'personalexpectations' => 13,
                'person' => 26,
                'role' => 27,
                'organization' => 25,
            ],
            'royalties' => [],
        ],
    ];

    /**
     * @return array{label: string, versionControlKey: int, packageVersionKey: int, profileVersionKey: int, insightScoreVersionKey: int, toolVersions: array<string, int>, royalties: list<array<string, mixed>>}
     */
    public static function get(string $code): array
    {
        return self::PRODUCTS[$code] ?? throw new RuntimeException("Unknown product/access code '{$code}'");
    }
}
