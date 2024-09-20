import React, { useContext } from "react";
import { Container } from 'react-bootstrap';
import { Archive, ChatDots, BoxArrowRight } from 'react-bootstrap-icons';
import { useNavigate } from 'react-router-dom';
import "./header.css";
import { user_info } from '../database/dexie';
import { ChatContext } from '../../contexts/Chat';

export default function Header() {
    const navigate = useNavigate();
    const { clearMessages } = useContext(ChatContext);

    const handleSignOut = async () => {
        await user_info.current_user.clear();
        await clearMessages(); // Clear chat context
        navigate('/'); // Navigate to login route
    };

    const endSession = async () => {
        const mica = mica_jsmo_module;
        if (mica) {
            // Get user data from IndexedDB
            const users = await user_info.current_user.toArray();
            if (users.length > 0) {
                const { id, name } = users[0]; // Get participant_id and username
                mica_jsmo_module.completeSession(
                    {
                        participant_id: id
                    },
                    async (res) => {
                        console.log('Session ended successfully.');
                        // Now sign out the user
                        handleSignOut();
                    },
                    (err) => {
                        console.error('Error ending session:', err);
                        // Optionally, you might still sign out the user even if ending the session fails
                        handleSignOut();
                    }
                );
            } else {
                console.error('No user data found in IndexedDB');
                // Since there's no user data, proceed to sign out
                handleSignOut();
            }
        } else {
            console.error('MICA EM is not injected, cannot execute endSession');
            // Since MICA EM is not available, proceed to sign out
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
