import React, { useContext, useRef, useEffect } from "react";
import ReactMarkdown from "react-markdown";
import { ArrowClockwise, ExclamationCircle } from 'react-bootstrap-icons';
import { ChatContext } from "../../contexts/Chat";
import SessionNotice from "../notice/sessionNotice.jsx";
import "./messages.css";

export const Messages = () => {
    const chat_context = useContext(ChatContext);
    const newQaRef = useRef(null);
    const end_session_text = window.mica_jsmo_module.end_session_text || 'Please click "End Session" to ensure compensation for your participation.';

    const { sessionState, blockedReason, pending, turnError, retryTurn } = chat_context;

    const introMessage = {
        user_content: null,
        assistant_content: window.mica_jsmo_module.intro_text || "Hi there. I’m MICA. What is your name?",
        isIntro: true,
        role: 'assistant',
    };

    useEffect(() => {
        if (newQaRef.current) {
            newQaRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, [chat_context.chatContext, pending, turnError]);

    // A blocked session has no transcript to show and no turn to take. The greeting
    // and the end-session reminder are suppressed with it: both were still rendering
    // around a gate message, telling a participant with no session to introduce
    // themselves and then claim compensation (critique 2026-08-19, P0).
    if (sessionState === 'blocked') {
        return (
            <div className="messages messages--blocked">
                <SessionNotice reason={blockedReason} tone="info" />
            </div>
        );
    }

    const allMessages = [introMessage, ...(chat_context.chatContext || [])];
    const lastIndex = allMessages.length - 1;

    return (
        <div className="messages">
            {/* The transcript is the app's only output. Without a live region a screen
                reader user got no announcement when MICA replied at all. */}
            <div role="log" aria-live="polite" aria-relevant="additions text" aria-label="Conversation with MICA">
                {allMessages.map((message, index) => {
                    const chatIndex = index - 1; // allMessages is offset by the intro row
                    const failedHere = turnError && turnError.index === chatIndex;
                    const pendingHere = pending && index === lastIndex && !message.assistant_content;

                    return (
                        <React.Fragment key={index}>
                            <dl ref={index === lastIndex ? newQaRef : null}>
                                {message.user_content ? (
                                    <dt>{message.user_content}</dt>
                                ) : (
                                    <dt className="empty-dt"></dt>
                                )}
                                {message.assistant_content && (
                                    <dd>
                                        <ReactMarkdown>{message.assistant_content}</ReactMarkdown>
                                    </dd>
                                )}
                                {pendingHere && (
                                    <dd className="mica-pending" role="status" aria-label="MICA is replying">
                                        <span className="mica-pending__dots" aria-hidden="true">
                                            <i /><i /><i />
                                        </span>
                                        <span className="mica-pending__text">MICA is replying…</span>
                                    </dd>
                                )}
                                {failedHere && (
                                    <dd className="mica-turn-error">
                                        <p className="mica-turn-error__text">
                                            <ExclamationCircle size={16} aria-hidden="true" />
                                            <span>{turnError.message}</span>
                                        </p>
                                        {turnError.retryable !== false && (
                                            <button
                                                type="button"
                                                className="mica-turn-error__retry"
                                                onClick={retryTurn}
                                                disabled={pending}
                                            >
                                                <ArrowClockwise size={16} aria-hidden="true" />
                                                Try again
                                            </button>
                                        )}
                                    </dd>
                                )}
                            </dl>
                            {index < lastIndex && <hr className="divider" />}
                        </React.Fragment>
                    );
                })}
            </div>
            {chat_context.chatContext && chat_context.chatContext.length > 0 && (
                <dl className="soft_text floating-message">
                    <dd>{end_session_text}</dd>
                </dl>
            )}
        </div>
    );
};

export default Messages;
