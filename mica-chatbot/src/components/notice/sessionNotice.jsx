import { CalendarCheck, ExclamationTriangle } from "react-bootstrap-icons";
import "./sessionNotice.css";

/**
 * Terminal state for a session that is not open: the server's session gates
 * ("Session already completed. Thank you.", "Session already completed. Return in
 * N day(s) for your next session!", "Study already completed. Thank you.") and
 * hard bootstrap failures.
 *
 * These used to be pushed into the transcript as assistant messages, so they
 * arrived in MICA's bubble, wearing MICA's avatar and the "MICA AI" label, under
 * a "Hi there. I'm MICA. What is your name?" greeting, above a live composer
 * (docs: critique 2026-08-19, P0 "the session gate is expressed only as a chat
 * message, so it does not gate").
 *
 * The rule this component exists to enforce: system state never speaks as the
 * counselor. The server's own sentence is rendered verbatim as the headline - it
 * is the message, and it carries the study's wording - and nothing here invents
 * a reason or a date.
 */
export function SessionNotice({ reason, tone = "info", contact = true }) {
    const Icon = tone === "error" ? ExclamationTriangle : CalendarCheck;

    return (
        <div className={`mica-notice mica-notice--${tone}`} role="status">
            <div className="mica-notice__panel">
                <span className="mica-notice__icon" aria-hidden="true">
                    <Icon size={22} />
                </span>
                <h2 className="mica-notice__headline">{reason}</h2>
                {contact && (
                    <p className="mica-notice__aside">
                        If you think this is a mistake, contact the study team.
                    </p>
                )}
            </div>
        </div>
    );
}

export default SessionNotice;
