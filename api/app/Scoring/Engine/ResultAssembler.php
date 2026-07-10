<?php

namespace App\Scoring\Engine;

/**
 * Builds the results body (keys format) from SessionOutString rows, exactly
 * as the legacy WCF service did from the same rows.
 *
 * The mcs/pro rank vectors live in SessionOutStringTypes 134/135/136 (m/c/s)
 * and 143/142/147 (p/r/o), sequence 1–9 = the nine mission areas in
 * FrameworkKey order. The etc map below was derived empirically: each of the
 * 99 etc keys resolved uniquely against (TypeKey, Sequence, value-kind)
 * across all 30 golden sessions that retain full outstrings, and
 * combo_metaphor (the one ambiguity) was fixed from the
 * SessionOutput2GetByKeyFormatKeys pivot proc (TypeKey 27).
 */
class ResultAssembler
{
    /** The nine mission areas, sequence/FrameworkKey order (wsReportMCS). */
    public const AREAS = [
        'societal_change',
        'theoretical_discovery',
        'strategic_decisions',
        'human_development',
        'entrepreneurial_challenge',
        'production_efficiency',
        'artistic_creativity',
        'natural_appreciation',
        'motivational_energy',
    ];

    private const MCS_TYPES = ['m' => 134, 'c' => 135, 's' => 136];

    private const PRO_TYPES = ['p' => 143, 'r' => 142, 'o' => 147];

    /** etc key => [SessionOutStringTypeKey, Sequence, 'arch'|'insight'] */
    private const ETC_MAP = [
        'combo_metaphor' => [27, 1, 'insight'],
        'combo_metaphor_desc' => [28, 1, 'insight'],
        's_keytrait_1' => [46, 1, 'arch'],
        's_keytrait_2' => [46, 2, 'arch'],
        's_keytrait_3' => [46, 3, 'arch'],
        's_keytrait_1_desc' => [47, 1, 'arch'],
        's_keytrait_2_desc' => [47, 2, 'arch'],
        's_keytrait_3_desc' => [47, 3, 'arch'],
        's_caution_1' => [48, 1, 'arch'],
        's_caution_2' => [48, 2, 'arch'],
        's_caution_3' => [48, 3, 'arch'],
        's_caution_1_desc' => [49, 1, 'arch'],
        's_caution_2_desc' => [49, 2, 'arch'],
        's_caution_3_desc' => [49, 3, 'arch'],
        'sct_trait_impl' => [50, 1, 'insight'],
        'college_major_1' => [69, 1, 'insight'],
        'college_major_2' => [69, 2, 'insight'],
        'college_major_3' => [69, 3, 'insight'],
        'college_major_4' => [69, 4, 'insight'],
        'college_major_5' => [69, 5, 'insight'],
        'industry_field_1' => [88, 1, 'insight'],
        'industry_field_2' => [88, 2, 'insight'],
        'industry_field_3' => [88, 3, 'insight'],
        'industry_field_4' => [88, 4, 'insight'],
        'industry_field_5' => [88, 5, 'insight'],
        'mct_trait_impl' => [102, 1, 'insight'],
        'central_theme' => [110, 1, 'insight'],
        'career_values_imp' => [111, 1, 'insight'],
        'lead_anchor_1' => [112, 1, 'arch'],
        'lead_anchor_2' => [112, 2, 'arch'],
        'lead_anchor_3' => [112, 3, 'arch'],
        'job_function_1' => [114, 1, 'arch'],
        'job_function_2' => [114, 2, 'arch'],
        'job_function_3' => [114, 3, 'arch'],
        'job_function_1_desc_a' => [115, 1, 'arch'],
        'job_function_2_desc_a' => [115, 2, 'arch'],
        'job_function_3_desc_a' => [115, 3, 'arch'],
        'culturalpref_1' => [118, 1, 'arch'],
        'culturalpref_2' => [118, 2, 'arch'],
        'culturalpref_3' => [118, 3, 'arch'],
        'cct_trait_impl' => [149, 1, 'insight'],
        'role_1' => [150, 1, 'insight'],
        'role_2' => [150, 2, 'insight'],
        'role_3' => [150, 3, 'insight'],
        'educkey_1' => [151, 1, 'insight'],
        'educkey_2' => [151, 2, 'insight'],
        'educkey_3' => [151, 3, 'insight'],
        'develsugg_1' => [152, 1, 'insight'],
        'develsugg_2' => [152, 2, 'insight'],
        'develsugg_3' => [152, 3, 'insight'],
        'per_perspective' => [153, 1, 'insight'],
        'rol_perspective' => [154, 1, 'insight'],
        'org_perspective' => [155, 1, 'insight'],
        'job_function_1_desc_b' => [156, 1, 'arch'],
        'job_function_2_desc_b' => [156, 2, 'arch'],
        'job_function_3_desc_b' => [156, 3, 'arch'],
        'job_function_1_desc_c' => [157, 1, 'arch'],
        'job_function_2_desc_c' => [157, 2, 'arch'],
        'job_function_3_desc_c' => [157, 3, 'arch'],
        'mnt_pos_1' => [158, 1, 'arch'],
        'mnt_pos_2' => [158, 2, 'arch'],
        'mnt_pos_3' => [158, 3, 'arch'],
        'mnt_neg_1' => [159, 1, 'arch'],
        'mnt_neg_2' => [159, 2, 'arch'],
        'mnt_neg_3' => [159, 3, 'arch'],
        'mnt_conc_1' => [160, 1, 'arch'],
        'mnt_conc_2' => [160, 2, 'arch'],
        'mnt_conc_3' => [160, 3, 'arch'],
        'prot_pos_1' => [161, 1, 'arch'],
        'prot_pos_2' => [161, 2, 'arch'],
        'prot_pos_3' => [161, 3, 'arch'],
        'prot_neg_1' => [162, 1, 'arch'],
        'prot_neg_2' => [162, 2, 'arch'],
        'prot_neg_3' => [162, 3, 'arch'],
        'prot_conc_1' => [163, 1, 'arch'],
        'prot_conc_2' => [163, 2, 'arch'],
        'prot_conc_3' => [163, 3, 'arch'],
        'role_4' => [169, 1, 'insight'],
        'role_5' => [169, 2, 'insight'],
        'role_6' => [169, 3, 'insight'],
        'role_7' => [171, 1, 'insight'],
        'role_8' => [171, 2, 'insight'],
        'role_9' => [171, 3, 'insight'],
        'role_10' => [172, 1, 'insight'],
        'role_11' => [172, 2, 'insight'],
        'role_12' => [172, 3, 'insight'],
        'role_position_1' => [180, 1, 'insight'],
        'role_position_2' => [180, 2, 'insight'],
        'role_position_3' => [180, 3, 'insight'],
        'role_position_4' => [180, 4, 'insight'],
        'role_position_5' => [180, 5, 'insight'],
        'organization_type_1' => [197, 1, 'insight'],
        'organization_type_2' => [197, 2, 'insight'],
        'organization_type_3' => [197, 3, 'insight'],
        'organization_type_4' => [197, 4, 'insight'],
        'organization_type_5' => [197, 5, 'insight'],
        'roledesc_1' => [265, 1, 'insight'],
        'roledesc_2' => [265, 2, 'insight'],
        'roledesc_3' => [265, 3, 'insight'],
    ];

    /**
     * Strings format (results.format = 2): same shape, but etc values are
     * the resolved content strings from the same SessionOutString rows.
     *
     * @return array{mcs: array, pro: array, etc: array}
     */
    public function assembleStrings(SessionState $state): array
    {
        $body = $this->assemble($state);

        $index = [];
        foreach ($state->outStrings as $row) {
            $index[$row['typeKey']][$row['sequence']] ??= $row;
        }
        foreach (self::ETC_MAP as $key => [$type, $seq, $kind]) {
            // Legacy strips double quotes from strings-format content (no
            // golden ever contains one, while 208 InsightDetail rows do).
            $body['etc'][$key] = str_replace('"', '', trim((string) ($index[$type][$seq]['string'] ?? '')));
        }

        // Faithful legacy quirk: wsReportETCString.cs declares this one
        // property with a capital S ("S_caution_3_desc") in strings format
        // only — keys format uses lowercase.
        $body['etc']['S_caution_3_desc'] = $body['etc']['s_caution_3_desc'];
        unset($body['etc']['s_caution_3_desc']);

        return $body;
    }

    /** @return array{mcs: array, pro: array, etc: array} */
    public function assemble(SessionState $state): array
    {
        $index = [];
        foreach ($state->outStrings as $row) {
            $index[$row['typeKey']][$row['sequence']] ??= $row;
        }

        $mcs = [];
        $pro = [];
        foreach (self::AREAS as $i => $area) {
            foreach (self::MCS_TYPES as $dim => $type) {
                $mcs[$area][$dim] = trim((string) ($index[$type][$i + 1]['string'] ?? ''));
            }
            foreach (self::PRO_TYPES as $dim => $type) {
                $pro[$area][$dim] = trim((string) ($index[$type][$i + 1]['string'] ?? ''));
            }
        }

        $etc = [];
        foreach (self::ETC_MAP as $key => [$type, $seq, $kind]) {
            $row = $index[$type][$seq] ?? null;
            $etc[$key] = match ($kind) {
                'arch' => ['archetypedetailkey' => (string) ($row['archetypeDetailKey'] ?? '')],
                'insight' => ['insightdetailkey' => (string) ($row['insightDetailKey'] ?? '')],
            };
        }

        return ['mcs' => $mcs, 'pro' => $pro, 'etc' => $etc];
    }
}
