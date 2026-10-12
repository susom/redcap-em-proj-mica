<?php

namespace Stanford\MICA;

/**
 * Which AI Hub token policy MICA runs under, and which model setting each role reads under it.
 *
 * SecureChatAI keeps two model sets, standard and high-threshold, each on its own AI Hub key
 * (SecureChatAI `docs/token-policy.md`). The super-user switch `use-high-threshold-models` moves
 * BOTH of MICA's calls - the counselor turn and the SafetyScan - to the high-threshold set together.
 * Each role then reads its own high-threshold alias setting, because the two sets are separate
 * deployments and an alias picked from one list means nothing in the other.
 *
 * It is a data rule, so nothing here falls back: with the switch on, a blank high-threshold alias
 * is a refusal, never a quiet read of the standard one.
 *
 * Framework-free and static, so it is unit-tested without REDCap.
 */
final class ModelSelection
{
    public const SWITCH_KEY = 'use-high-threshold-models';

    public const STANDARD = 'standard';
    public const HIGH = 'high';

    public const COUNSELOR = 'counselor';
    public const SAFETYSCAN = 'safetyscan';

    /** role => policy => project setting key */
    public const KEYS = [
        self::COUNSELOR => [self::STANDARD => 'llm-model', self::HIGH => 'llm-model-high'],
        self::SAFETYSCAN => [self::STANDARD => 'safetyscan-model-alias', self::HIGH => 'safetyscan-model-alias-high'],
    ];

    /**
     * Dropdown entries in config.json that stand in for the high-threshold aliases until AI Hub
     * publishes them. Never registered anywhere, so a saved placeholder fails every call and the
     * models launch gate names it as a placeholder.
     */
    public const PLACEHOLDER_PREFIX = 'placeholder-';

    /** The policy the switch selects. Unset, false, '' or '0' is standard. */
    public static function policy($switchValue): string
    {
        return in_array($switchValue, [true, 1, '1', 'true'], true) ? self::HIGH : self::STANDARD;
    }

    public static function key(string $role, string $policy): string
    {
        if (!isset(self::KEYS[$role][$policy])) {
            throw new \InvalidArgumentException("Unknown model role/policy: $role/$policy");
        }

        return self::KEYS[$role][$policy];
    }

    public static function isPlaceholder(?string $alias): bool
    {
        return $alias !== null && str_starts_with(trim($alias), self::PLACEHOLDER_PREFIX);
    }

    /**
     * Shape the project-settings dialog for the active policy.
     *
     * - Adds every alias SecureChatAI has registered under high-threshold to the high-threshold
     *   dropdowns, ahead of the placeholders, so the list is right before config.json is edited.
     *   The SafetyScan dropdown only takes aliases in `$schemaCapable`: the scan needs enforced
     *   structured output, and SecureChatAI drops the schema for any other alias.
     * - For a super user, branchingLogic on the switch shows one pair or the other as it is ticked.
     * - Anyone else never sees the switch: REDCap leaves a super-users-only field out of the dialog,
     *   and a branching condition on a missing field is false, which would hide BOTH dropdowns. So
     *   for them the branching is dropped and the inactive dropdown removed here instead. A removed
     *   field is not posted on Save, so its stored value is kept.
     *
     * @param array<int,array<string,mixed>> $settings project-settings config rows
     * @param list<string> $highRegistered aliases SecureChatAI serves under high-threshold
     * @param list<string> $schemaCapable aliases SecureChatAI forwards `json_schema` for
     * @return array<int,array<string,mixed>>
     */
    public static function applyToConfig(
        array $settings,
        string $policy,
        bool $superUser,
        array $highRegistered,
        array $schemaCapable
    ): array {
        $live = [
            self::KEYS[self::COUNSELOR][self::HIGH] => array_values($highRegistered),
            self::KEYS[self::SAFETYSCAN][self::HIGH] => array_values(array_intersect($highRegistered, $schemaCapable)),
        ];
        $inactive = $policy === self::HIGH ? self::STANDARD : self::HIGH;
        $remove = array_column(self::KEYS, $inactive);
        $managed = array_merge(array_column(self::KEYS, self::STANDARD), array_column(self::KEYS, self::HIGH));

        $out = [];
        foreach ($settings as $setting) {
            $key = $setting['key'] ?? null;

            if (isset($live[$key])) {
                $setting['choices'] = self::withLiveChoices($setting['choices'] ?? [], $live[$key]);
            }

            if (!$superUser && in_array($key, $managed, true)) {
                if (in_array($key, $remove, true)) {
                    continue;
                }
                unset($setting['branchingLogic']);
            }

            $out[] = $setting;
        }

        return $out;
    }

    /**
     * @param list<array{value:string,name:string}> $choices
     * @param list<string> $aliases
     * @return list<array{value:string,name:string}>
     */
    private static function withLiveChoices(array $choices, array $aliases): array
    {
        $known = array_column($choices, 'value');
        $added = [];
        foreach ($aliases as $alias) {
            $alias = (string) $alias;
            if ($alias === '' || in_array($alias, $known, true)) {
                continue;
            }
            $added[] = ['value' => $alias, 'name' => "$alias (registered in SecureChatAI as high-threshold)"];
            $known[] = $alias;
        }

        if ($added === []) {
            return $choices;
        }

        $at = count($choices);
        foreach ($choices as $i => $choice) {
            if (self::isPlaceholder($choice['value'] ?? null)) {
                $at = $i;
                break;
            }
        }
        array_splice($choices, $at, 0, $added);

        return $choices;
    }
}
