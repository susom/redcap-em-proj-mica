import { useContext, useState } from "react";
import { Container } from 'react-bootstrap';
import "./header.css";
import { user_info } from '../database/dexie';
import { ChatContext } from '../../contexts/Chat';
import useAuth from '../../Hooks/useAuth.jsx';
import ConfirmSheet from '../confirm/confirmSheet.jsx';

export default function Header() {
    const { clearMessages, chatContext, sessionState, blockSession, reportSessionError, clearSessionError, pending } = useContext(ChatContext);
    const { logout } = useAuth();
    const [confirmEnd, setConfirmEnd] = useState(false);
    const [ending, setEnding] = useState(false);

    const handleSignOut = async () => {
        sessionStorage.setItem('mica_disable_bootstrap','1'); // keep if you already added the guard
        await user_info.current_user.clear();
        await clearMessages();
        await logout();

        const back = window.mica_bootstrap?.login_url || '/';
        window.location.href = back;
    };

    /**
     * Forget this participant on this device, without navigating anywhere.
     *
     * The same clearing handleSignOut does, minus the redirect. Split out because the redirect was
     * the only thing wrong with reusing it: a finished session has to leave nothing behind on a
     * device that gets handed to the next participant, and it must be able to do that while staying
     * on the page to show them a terminal notice.
     */
    const clearLocalSession = async () => {
        sessionStorage.setItem('mica_disable_bootstrap', '1');
        await user_info.current_user.clear();
        await clearMessages();
    };

    const endSession = async () => {
        setEnding(true);
        clearSessionError();
        const mica = mica_jsmo_module;
        if (mica) {
            try {
                // Get user data from IndexedDB
                const users = await user_info.current_user.toArray();
                if (users.length > 0) {
                    const { id } = users[0];
                    mica_jsmo_module.completeSession(
                        {
                            participant_id: id ,
                            session: window.mica_jsmo_module.this_session,
                            session_start_time: users[0].session_start_time
                        },
                        async (res) => {
                            if (!res?.success) {
                                // Falls through to the error handler's contract rather than being
                                // treated as a finished session.
                                reportSessionError(
                                    'Your session could not be finalized. Please contact the study team.',
                                );
                                setEnding(false);
                                setConfirmEnd(false);
                                return;
                            }

                            // Cleared before either exit. On the redirect path this used to be
                            // skipped entirely, so a shared device carried the previous
                            // participant's cached identity and conversation into the next session.
                            await clearLocalSession();

                            if (res.survey_link) {
                                window.location.href = res.survey_link;
                                return;
                            }

                            /**
                             * No survey to send them to, so this is the end - say so and stop.
                             *
                             * It used to sign out, which redirected to `login_url` -
                             * `pages/chatbot.php`, the pilot's standalone login. That page matches on
                             * `participant_name` / `participant_email`, fields an R01 project does not
                             * have, so it could only ever answer "Invalid Credentials". A participant
                             * who had just finished was shown a login form they could not pass.
                             *
                             * The same shape as docs 14 D16: dumped somewhere with no explanation.
                             * blockSession() is the terminal state that already exists for
                             * "Session already completed" - it renders the notice, drops the
                             * composer and the intro, and hides this button.
                             */
                            blockSession(
                                'Your session is complete. Thank you — you can close this window.',
                            );
                            setEnding(false);
                            setConfirmEnd(false);
                        },
                        async (err) => {
                            // Do NOT sign out here. Signing out on a finalization failure made a
                            // lost session look like a normal exit: the participant was returned to
                            // the login page with no message and no way to retry (docs 14 D16).
                            //
                            // It is also not MICA's line to deliver: a finalization failure is the
                            // study system reporting a problem, so it is rendered as a system row
                            // rather than in the counselor's bubble (critique 2026-08-19, P0).
                            //
                            // Reported, NOT blocked. Blocking would hide the transcript and remove
                            // this button, which is the "no way to retry" half of D16 all over
                            // again. The session and the messages still exist; only the save failed,
                            // so End Session stays pressable.
                            console.error('Error ending session:', err);
                            setEnding(false);
                            setConfirmEnd(false);
                            const msg = typeof err === 'string' && err.trim() !== ''
                                ? err
                                : 'Your session could not be finalized. Please contact the study team.';
                            reportSessionError(msg);
                        }
                    );
                } else {
                    console.error('No user data found in IndexedDB');
                    handleSignOut();
                }
            } catch (error) {
                console.error('Unexpected error:', error);
                handleSignOut();
            }
        } else {
            console.error('MICA EM is not injected, cannot execute endSession');
            handleSignOut();
        }
    };

    const blocked = sessionState === 'blocked';
    const hasConversation = (chatContext || []).length > 0;

    return (
        <>
            <Container className="rcchat_header handle">
                <h1>
                    <span className="logo" ></span>
                    MICA AI Chatbot
                </h1>
                <div className="buttons">
                    {/* Hidden while blocked: there is no session to end, and pressing it
                        used to run completeSession or bounce the participant to the login
                        page with no explanation (critique 2026-08-19, P0). */}
                    {!blocked && (
                        <button
                            type="button"
                            className="end_session"
                            onClick={() => setConfirmEnd(true)}
                            disabled={pending}
                        >
                            End Session
                        </button>
                    )}
                </div>
            </Container>

            <ConfirmSheet
                open={confirmEnd}
                title="End your session with MICA?"
                body={
                    <>
                        <p>
                            {hasConversation
                                ? 'This finishes your conversation and saves it for the study team. You will not be able to add to it afterwards.'
                                : 'You have not written anything yet. Ending now finishes the session without a conversation.'}
                        </p>
                        <p>Next you will be taken to a short survey.</p>
                    </>
                }
                confirmLabel="End session"
                cancelLabel="Keep talking"
                tone="neutral"
                busy={ending}
                onConfirm={endSession}
                onCancel={() => setConfirmEnd(false)}
            />
        </>
    );
}
