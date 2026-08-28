<?php

namespace Stanford\MICA;

require_once __DIR__ . "/ArtifactRegistry.php";

/**
 * The validated SafetyScan prompt, rendered read-only into the module's configuration dialog.
 *
 * ## Why this exists
 *
 * `safetyscan-prompt-addendum` asks an administrator to write text that is appended to a prompt they
 * have never been shown. Its label describes that prompt in prose - "v1.1: 120-case suite, 100%
 * critical detection" - and tells them not to contradict it, which is not something you can do
 * blind. The instructions in force are a file in `handoff/`, readable by whoever has a shell on the
 * server and by nobody else. This class puts them in front of the person writing the addendum, in
 * the dialog where they write it.
 *
 * ## Why it renders from the registry instead of from config.json
 *
 * `config.json` is static, so pasting the prompt into a `descriptive` setting's `name` would create
 * a second copy of a hash-pinned artifact: 4.4 KB of text with no pin on it, free to drift from
 * `handoff/manifest.json` the moment either side is edited, and displayed with equal confidence
 * either way. That is the exact failure `ArtifactRegistry` exists to prevent, so the text is read
 * through the registry at render time - verified on the way out, the same read `ScanRunner` makes.
 * The panel therefore cannot show a prompt other than the one a scan would send.
 *
 * ## Why every failure is caught
 *
 * The framework calls `redcap_module_configuration_settings()` from `manager/ajax/get-settings.php`,
 * a JSON endpoint whose output is the whole configuration dialog. An exception escaping this class
 * would not degrade the panel - it would truncate the JSON and leave the module with no editable
 * settings at all. So `html()` catches `\Throwable` and renders the failure as content. A hash
 * mismatch shows a warning and no prompt text on purpose: displaying bytes that failed their pin as
 * if they were the validated prompt is worse than displaying nothing, and a scan is failing the same
 * way at the same moment.
 *
 * Framework-free by the same convention as the rest of `classes/` (see ArtifactRegistry): it takes a
 * registry and returns a string. Escaping is its own job, because the caller's is raw HTML - see
 * `esc()`.
 */
class PinnedPromptView
{
    /**
     * The `descriptive` project setting whose label this view replaces. Must match `config.json`.
     *
     * A descriptive setting holds no value and is never read back, so this key exists only as an
     * anchor: it reserves the position in the dialog - directly above the addendum field - that the
     * panel is rendered into.
     */
    public const SETTING_KEY = 'safetyscan-prompt-pinned-view';

    /** Logical name in `handoff/manifest.json`; the same one `ScanRunner::resolvePrompt()` reads. */
    private const ARTIFACT = 'safetyscan_prompt';

    private ArtifactRegistry $artifacts;

    public function __construct(?ArtifactRegistry $artifacts = null)
    {
        $this->artifacts = $artifacts ?? new ArtifactRegistry();
    }

    /**
     * Fill in the placeholder's `name` and hand the settings array back.
     *
     * Returns `$settings` untouched when the key is absent, which is the normal case for the
     * control-center dialog: the anchor is a project setting, so the system-settings array does not
     * contain it. Absence is not an error and is not logged.
     *
     * @param array<int,array<string,mixed>> $settings a config section, as the framework passes it
     * @return array<int,array<string,mixed>>
     */
    public function apply(array $settings): array
    {
        foreach ($settings as $i => $setting) {
            if (($setting['key'] ?? null) === self::SETTING_KEY) {
                $settings[$i]['name'] = $this->html();
                return $settings;
            }
        }

        return $settings;
    }

    /**
     * The panel, as HTML safe to drop into the dialog - or the failure notice, never both.
     */
    public function html(): string
    {
        try {
            $text = $this->artifacts->getText(self::ARTIFACT);
            $hash = $this->artifacts->getHash(self::ARTIFACT);
        } catch (\Throwable $e) {
            return $this->failureHtml($e);
        }

        /*
         * Both counts are shown so the reader can tell a bounded scroll box from a truncated one.
         * The panel never elides anything, but a 4 KB `<pre>` in a 260px window looks exactly like
         * something that might, and "83 lines" answers that without them having to scroll to check.
         */
        $lines = substr_count($text, "\n") + (str_ends_with($text, "\n") ? 0 : 1);
        $kb    = number_format(strlen($text) / 1024, 1);

        return '<div style="font-weight:normal">'
            . '<b>SafetyScan analysis prompt — the validated instructions in force</b><br>'
            . '<i>Read-only, and not editable from REDCap by design: this is the hash-pinned prompt '
            . 'the research team validated (v1.1: 120-case suite, 100% critical detection, 100% quote '
            . 'traceability), verified against <code>handoff/manifest.json</code> every time a scan '
            . 'runs. Changing it is a code deployment with a re-pinned manifest, not a setting.<br><br>'
            . 'Study-specific guidance goes in the field <b>below</b>, where it is appended to this '
            . 'text inside a labelled block — after which the two requirements this prompt guarantees, '
            . 'output matching the pinned schema and evidence quoted verbatim, are restated and take '
            . 'precedence over your wording. Read this first: an addition that argues with it costs '
            . 'you findings.<br><br>'
            . 'SHA-256 <code style="word-break:break-all">' . $this->esc($hash) . '</code><br>'
            . 'That is the value a scan run row records in <code>prompt_sha256</code> when the '
            . 'addendum is blank. A row showing anything else was composed with an addendum, and '
            . 'carries <code>prompt_addendum_sha256</code> to identify it.</i>'
            . '<details style="margin-top:8px">'
            . '<summary style="cursor:pointer;font-weight:bold;display:list-item;width:fit-content">'
            . 'Show the full prompt (' . $lines . ' lines, ' . $kb . ' KB)'
            . '</summary>'
            . '<pre style="max-height:320px;overflow:auto;white-space:pre-wrap;word-break:break-word;'
            . 'margin:6px 0 0;padding:10px;border:1px solid #ccc;border-radius:4px;background:#f9f9f9;'
            . 'font-family:Consolas,Monaco,monospace;font-size:11px;line-height:1.45;color:#333">'
            . $this->esc($text)
            . '</pre>'
            . '</details>'
            . '</div>';
    }

    /**
     * Shown in place of the prompt when it cannot be vouched for.
     *
     * Deliberately alarming, and deliberately empty of prompt text. The same read fails inside
     * `ScanRunner`, so this is not a display problem the administrator can ignore: scans are already
     * failing. The reason is included because the useful ones name a path or a hash, and this dialog
     * is only reachable by someone with module design rights.
     */
    private function failureHtml(\Throwable $e): string
    {
        return '<div style="font-weight:normal;padding:10px;border:1px solid #c00;border-radius:4px;'
            . 'background:#fff4f4;color:#900">'
            . '<b>SafetyScan analysis prompt — cannot be shown</b><br>'
            . '<i>The pinned prompt did not pass its integrity check, so it is not displayed: text '
            . 'that failed its hash is not the prompt the research team validated, and showing it as '
            . 'if it were would be worse than showing nothing. <b>Safety scans are failing for the '
            . 'same reason right now</b> — this is a deployment problem, not a display one. Restore '
            . '<code>handoff/</code> from the handoff package or re-pin '
            . '<code>handoff/manifest.json</code>.<br><br>Reason: <code style="word-break:break-all">'
            . $this->esc($e->getMessage())
            . '</code></i></div>';
    }

    /**
     * The framework injects a setting's `name` as raw HTML - `manager/ajax/get-settings.php` refuses
     * to escape the config ("It breaks HTML in module setting names") and `manager/js/globals.js`
     * drops the value straight into a `<label>`. Everything interpolated above is therefore escaped
     * here, including the exception message, which can carry a path.
     *
     * `ENT_SUBSTITUTE` is not decoration. Without it, invalid UTF-8 anywhere in the input makes
     * `htmlspecialchars()` return the empty string, and the endpoint's `JSON_PARTIAL_OUTPUT_ON_ERROR`
     * would then hand the dialog a silently empty panel rather than an error. Substituting keeps the
     * output valid UTF-8 and encodable no matter what the bytes were.
     */
    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
