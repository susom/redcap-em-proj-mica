<?php

namespace Stanford\MICA;

require_once __DIR__ . "/TranscriptException.php";

/**
 * Which session a chat belongs to, and in what setting, derived from the instrument hosting it.
 *
 * The SafetyScan input schema requires `session_type` (baseline|booster) and `setting`
 * (emergency_department|remote_followup|other_approved), both non-nullable. 02-data-model.md §3.1
 * gets them from a `mica_location` field that does not exist on PID 257 yet (audit G4), and
 * `session_type` from the R01 window arithmetic that Stage 2 builds.
 *
 * Neither is needed to answer the question. The R01 design already encodes both in the project
 * structure: `mica_ed_session` is the Day-1 ED session and `mica_booster_session` is the Month-3
 * remote follow-up, each designated to its own event. The instrument the participant is sitting on
 * *is* the answer, and it is known at `completeSession` time with no dictionary work at all.
 *
 * So this maps instrument to (session_type, setting), configurable, with the two R01 hosts as
 * defaults. When `mica_location` exists it becomes an override for `setting` - a participant seen in
 * the ED at Month 3 is a real case - but it overrides a correct default rather than filling a blank.
 *
 * The enum values here are the *schema's*, not the data model's: the schema says
 * `emergency_department` where 02-data-model.md §3.1 writes `ed`. The schema is the pinned artifact,
 * so it wins, and the translation happens once, here.
 */
class SessionHostMap
{
    public const BASELINE = 'baseline';
    public const BOOSTER  = 'booster';

    public const ED     = 'emergency_department';
    public const REMOTE = 'remote_followup';
    public const OTHER  = 'other_approved';

    /** @var array<string,array{session_type:string,setting:string}> */
    private array $map;

    /**
     * The R01 structure, as built on PID 257 (09-pid-257-structure-audit.md §1/§2): MICA is
     * designated to Day 1 (ED) and Month 3, arms 2 and 3 only.
     *
     * @return array<string,array{session_type:string,setting:string}>
     */
    public static function defaults(): array
    {
        return [
            'mica_ed_session'      => ['session_type' => self::BASELINE, 'setting' => self::ED],
            'mica_booster_session' => ['session_type' => self::BOOSTER, 'setting' => self::REMOTE],
        ];
    }

    /** @param array<string,array{session_type:string,setting:string}>|null $map */
    public function __construct(?array $map = null)
    {
        $this->map = $map ?? self::defaults();
    }

    /**
     * Parse the project setting form: one `instrument:session_type:setting` per line.
     *
     * Chosen over a repeatable sub-setting because it is one textarea a study coordinator can read
     * at a glance, and because it has to round-trip into a launch-readiness check that says which
     * host is unmapped.
     */
    public static function fromSetting(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return new self();
        }

        $map = [];
        foreach (preg_split('/\r?\n/', $raw) as $lineNo => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode(':', $line));
            if (count($parts) !== 3) {
                throw new TranscriptException(sprintf(
                    'Session host map line %d is not "instrument:session_type:setting": %s',
                    $lineNo + 1,
                    $line
                ));
            }

            [$instrument, $sessionType, $setting] = $parts;
            self::assertSessionType($sessionType, $instrument);
            self::assertSetting($setting, $instrument);

            $map[$instrument] = ['session_type' => $sessionType, 'setting' => $setting];
        }

        // An empty-but-non-blank setting (all comments, say) means the defaults, not "no hosts".
        return $map === [] ? new self() : new self($map);
    }

    /** @return string[] instruments this map knows about */
    public function instruments(): array
    {
        return array_keys($this->map);
    }

    public function knows(string $instrument): bool
    {
        return isset($this->map[$instrument]);
    }

    /**
     * @return array{session_type:string,setting:string}
     * @throws TranscriptException when the instrument is not a known chat host
     */
    public function resolve(string $instrument, ?string $locationOverride = null): array
    {
        if (!isset($this->map[$instrument])) {
            // Guessing would put a session in the wrong arm of the study's own analysis, and a
            // wrong `setting` changes how a SafetyScan finding is read - an ED disclosure and a
            // remote-follow-up disclosure are not the same clinical situation.
            throw new TranscriptException(sprintf(
                'Instrument "%s" is not a configured MICA chat host, so the session type and '
                . 'setting cannot be determined and nothing was finalized. Known hosts: %s. '
                . 'Configure them in the module\'s session-host-map setting.',
                $instrument,
                $this->instruments() === [] ? '(none)' : implode(', ', $this->instruments())
            ));
        }

        $resolved = $this->map[$instrument];

        if ($locationOverride !== null && trim($locationOverride) !== '') {
            $resolved['setting'] = self::normaliseLocation($locationOverride, $instrument);
        }

        return $resolved;
    }

    /**
     * `mica_location` is specified as `ed / remote_followup / other_approved` (02-data-model.md
     * §3.1) while the pinned schema's enum is `emergency_department / remote_followup /
     * other_approved`. Translating in one place beats discovering the mismatch inside a scan.
     */
    public static function normaliseLocation(string $value, string $context = ''): string
    {
        $value = trim($value);

        $aliases = [
            'ed'                   => self::ED,
            '1'                    => self::ED,
            'emergency_department' => self::ED,
            'remote_followup'      => self::REMOTE,
            '2'                    => self::REMOTE,
            'other_approved'       => self::OTHER,
            '3'                    => self::OTHER,
        ];

        if (!isset($aliases[$value])) {
            throw new TranscriptException(sprintf(
                'mica_location value "%s"%s is not one the SafetyScan input schema allows (%s). '
                . 'Nothing was finalized rather than guessing the clinical setting.',
                $value,
                $context === '' ? '' : " (on $context)",
                implode(', ', [self::ED, self::REMOTE, self::OTHER])
            ));
        }

        return $aliases[$value];
    }

    private static function assertSessionType(string $value, string $instrument): void
    {
        if (!in_array($value, [self::BASELINE, self::BOOSTER], true)) {
            throw new TranscriptException(
                "Session host map: \"$value\" (for $instrument) is not a session type the pinned "
                . 'input schema allows (' . self::BASELINE . ', ' . self::BOOSTER . ').'
            );
        }
    }

    private static function assertSetting(string $value, string $instrument): void
    {
        if (!in_array($value, [self::ED, self::REMOTE, self::OTHER], true)) {
            throw new TranscriptException(
                "Session host map: \"$value\" (for $instrument) is not a setting the pinned input "
                . 'schema allows (' . implode(', ', [self::ED, self::REMOTE, self::OTHER]) . ').'
            );
        }
    }
}
