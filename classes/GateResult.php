<?php

namespace Stanford\MICA;

/**
 * One launch-readiness gate's verdict.
 *
 * Carries `howToFix` because a gate that says only "failed" gets escalated to IT, and most of these
 * are resolved by a study administrator in a settings screen. A blocker nobody knows how to clear is
 * a blocker that gets bypassed.
 */
class GateResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly bool $passed,
        public readonly string $detail,
        public readonly string $howToFix = '',
        /**
         * True when this gate is a *deliberate* stop rather than a configuration slip.
         *
         * Only `critical_acknowledgment_minutes` is one: the handoff sets it to null on purpose so
         * that launching requires study leadership to decide, in writing, how quickly a critical
         * finding must be acknowledged. Marked so the UI can say "this is waiting on a decision"
         * rather than "something is broken".
         */
        public readonly bool $deliberate = false
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'title'      => $this->title,
            'passed'     => $this->passed,
            'detail'     => $this->detail,
            'how_to_fix' => $this->howToFix,
            'deliberate' => $this->deliberate,
        ];
    }
}
