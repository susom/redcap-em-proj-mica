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

    return (
        <Container className="rcchat_header handle">
            <h1>
                <span className="logo" ></span>
                MICA AI Chatbot
            </h1>
            <div className="buttons">
                <button onClick={handleSignOut}>
                    <BoxArrowRight size={20} />
                </button>
            </div>
        </Container>
    );
}
