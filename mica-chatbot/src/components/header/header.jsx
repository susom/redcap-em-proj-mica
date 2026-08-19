import { useContext, useState } from "react";
import { Container } from 'react-bootstrap';
import "./header.css";
import { user_info } from '../database/dexie';
import { ChatContext } from '../../contexts/Chat';
import useAuth from '../../Hooks/useAuth.jsx';
import ConfirmSheet from '../confirm/confirmSheet.jsx';

export default function Header() {
    const { clearMessages, chatContext, sessionState, blockSession, pending } = useContext(ChatContext);
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

    const endSession = async () => {
        setEnding(true);
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
                            if (res?.success && res?.survey_link) {
                                // Redirect to post-session survey link
                                window.location.href = res.survey_link;
                            } else {
                                console.warn('Session ended with no survey link — signing out.');
                                handleSignOut();
                            }
                        },
                        async (err) => {
                            // Do NOT sign out here. Signing out on a finalization failure made a
                            // lost session look like a normal exit: the participant was returned to
                            // the login page with no message and no way to retry (docs 14 D16).
                            //
                            // It is also not MICA's line to deliver: a finalization failure is the
                            // study system reporting a problem, so it goes to the session notice
                            // rather than into the counselor's bubble (critique 2026-08-19, P0).
                            console.error('Error ending session:', err);
                            setEnding(false);
                            setConfirmEnd(false);
                            const msg = typeof err === 'string' && err.trim() !== ''
                                ? err
                                : 'Your session could not be finalized. Please contact the study team.';
                            blockSession(msg);
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
