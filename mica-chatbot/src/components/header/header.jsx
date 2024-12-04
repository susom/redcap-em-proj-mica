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
    const { clearMessages } = useContext(ChatContext);
    const { logout } = useAuth();

    const handleSignOut = async () => {
        await user_info.current_user.clear();
        await clearMessages(); // Clear chat context
        await logout()
        navigate('/'); // Navigate to login route
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
                        { participant_id: id },
                        async (res) => {
                            console.log('Session ended successfully.');
                            if (res?.success) {
                                // Redirect to post-session survey link
                                navigate('/postsession', { state: { surveyLink: res.survey_link } });
                            } else {
                                console.error('Session ended, but no survey link found.');
                            }
                        },
                        (err) => {
                            console.error('Error ending session:', err);
                            // Handle sign out even if session ending fails
                            handleSignOut();
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
                <button onClick={handleSignOut}>
                    <BoxArrowRight size={20}/>
                </button>


            </div>
        </Container>
    );
}
