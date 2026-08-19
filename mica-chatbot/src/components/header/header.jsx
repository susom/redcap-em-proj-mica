import React, { useContext } from "react";
import { Container } from 'react-bootstrap';
import { Archive, ChatDots, BoxArrowRight } from 'react-bootstrap-icons';
import { useNavigate } from 'react-router-dom';
import "./header.css";
import { user_info } from '../database/dexie';
import { ChatContext } from '../../contexts/Chat';
import useAuth from '../../Hooks/useAuth.jsx';

export default function Header() {
    const navigate = useNavigate();
    const { clearMessages, chatContext, updateChatContext } = useContext(ChatContext);
    const { logout } = useAuth();

    const handleSignOut = async () => {
        sessionStorage.setItem('mica_disable_bootstrap','1'); // keep if you already added the guard
        await user_info.current_user.clear();
        await clearMessages();
        await logout();

        const back = window.mica_bootstrap?.login_url || '/';
        window.location.href = back;
    };

    const endSession = async () => {
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
                            console.error('Error ending session:', err);
                            const msg = typeof err === 'string' && err.trim() !== ''
                                ? err
                                : 'Your session could not be finalized. Please contact the study team.';
                            await updateChatContext([
                                ...(chatContext || []),
                                { user_content: null, assistant_content: msg, timestamp: new Date().getTime() },
                            ]);
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

    return (
        <Container className="rcchat_header handle">
            <h1>
                <span className="logo" ></span>
                MICA AI Chatbot
            </h1>
            <div className="buttons">
                <button onClick={endSession}>
                    End Session
                </button>
            </div>
        </Container>
    );
}
