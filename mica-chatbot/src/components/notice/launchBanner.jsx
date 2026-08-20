import { ExclamationTriangle } from "react-bootstrap-icons";
import "./launchBanner.css";

/**
 * The strip a development project shows above the conversation when launch gates fail.
 *
 * Three things it is deliberately not:
 *
 * **Not a SessionNotice.** That component is a terminal state - it replaces the transcript, because
 * there is no session. This is the opposite: the session runs, and this says the configuration is not
 * launch-ready. Sharing the component would have made "you cannot talk to MICA" and "staff, this is
 * not configured yet" look the same.
 *
 * **Not in MICA's voice.** No avatar, no bubble, no speaker label. Same rule the session notice
 * enforces: system state never speaks as the counselor. This one is addressed to study staff, which
 * makes it more important still.
 *
 * **Not detailed.** The server sends gate titles and a count, never `detail` or `how_to_fix` - this
 * page is in `no-auth-pages`, so anything here is readable by anyone holding the survey link. The
 * full checklist lives on the review dashboard behind a role. See
 * `LaunchReadiness::developmentBanner()`.
 *
 * It only ever appears on a development project with failing gates: a production project gets a
 * refusal instead, and a project that passes everything gets nothing, because a permanent "this is
 * dev" badge teaches people to ignore the banner.
 */
export function LaunchBanner({ banner }) {
    if (!banner || !banner.count) return null;

    const titles = banner.titles || [];
    const decisions = banner.awaiting_decision || [];
    const allDecisions = decisions.length === titles.length && titles.length > 0;

    /**
     * Name at most two, then count the rest.
     *
     * This listed every title at first, on the reasoning that a truncated list of what is
     * unconfigured is worse than none. Measuring it on an iPhone changed my mind: three titles took
     * five lines and about a quarter of the viewport, clipping the top of the conversation on the one
     * device where the conversation is the whole point. The banner is a *pointer* - the count is the
     * alarm, and the launch-readiness card is the actual report. The count stays exact either way, so
     * nothing is hidden about how much is wrong, only about which.
     */
    const shown = titles.slice(0, 2);
    const rest = titles.length - shown.length;

    return (
        // `role="status"` and not `alert`: an assertive region would interrupt a screen reader
        // mid-conversation on every page load, and this is a standing condition rather than an event.
        <div className="mica-launch" role="status">
            <span className="mica-launch__icon" aria-hidden="true">
                <ExclamationTriangle size={18} />
            </span>
            <div className="mica-launch__body">
                <p className="mica-launch__headline">{banner.headline}</p>
                <p className="mica-launch__detail">
                    {banner.count === 1 ? "1 gate is" : `${banner.count} gates are`} unmet:{" "}
                    <span className="mica-launch__titles">{shown.join(" · ")}</span>
                    {rest > 0 ? ` and ${rest} more` : ""}
                </p>
                {/* Kept to one line where possible. The first draft ran to three sentences, which
                    took five lines of an iPhone and pushed the conversation off the top of the
                    screen - on the one device where the conversation is the whole point. */}
                <p className="mica-launch__aside">
                    {allDecisions ? "Awaiting a decision, not broken. " : ""}
                    In production this session would be refused; screening and review still run.
                </p>
            </div>
        </div>
    );
}

export default LaunchBanner;
