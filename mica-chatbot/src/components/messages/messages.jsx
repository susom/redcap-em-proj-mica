import React, { useContext, useRef, useEffect } from "react";
import { Overlay, Popover } from 'react-bootstrap';
import ReactMarkdown from "react-markdown";
import { HandThumbsUp, HandThumbsDown, HandThumbsUpFill, HandThumbsDownFill, XCircleFill } from 'react-bootstrap-icons';
import { ChatContext } from "../../contexts/Chat";
import "./messages.css";

export const Messages = () => {
    const chat_context = useContext(ChatContext);
    const newQaRef = useRef(null);
    const end_session_text = window.mica_jsmo_module.end_session_text || 'Please click "End Session" to ensure compensation for your participation.';

    const introMessage = {
        user_content: null,
        assistant_content: window.mica_jsmo_module.intro_text || "Hi there. I’m MICA. What is your name?",
        isIntro: true, 
        role: 'assistant',
    };
    
    const allMessages = [introMessage, ...(chat_context.chatContext || [])];
    console.log(allMessages);
    const handleClick = (vote, index) => {
        chat_context.updateVote(index, vote);
    };

    const handleDelete = (index) => {
        chat_context.deleteInteraction(index);
    };

    const getVotesElement = () => {
        if (newQaRef.current) {
            return newQaRef.current.querySelector('.votes');
        }
        return null;
    };

    const popoverOverlay = (
        <Overlay target={getVotesElement} show={chat_context.showRatingPO} placement="top">
            <Popover id="popover-example">
                <Popover.Header as="h3">Please Rate The Response</Popover.Header>
                <Popover.Body>
                    The feedback helps us tune our support bot.
                </Popover.Body>
            </Popover>
        </Overlay>
    );

    useEffect(() => {
        if (newQaRef.current) {
            newQaRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, [chat_context.chatContext]);

    return (
        <div className={`messages`}>
            {allMessages.length > 0 ? (
                allMessages.map((message, index) => (
                    <React.Fragment key={index}>
                        <dl ref={index === allMessages.length - 1 ? newQaRef : null}>
                            {message.user_content ? (
                                <dt>
                                    {message.user_content}
                                    {!message.isIntro && (
                                        <XCircleFill className="delete-icon" onClick={() => handleDelete(index - 1)} />
                                    )}
                                </dt>
                            ) : (
                                <dt className="empty-dt"></dt>
                            )}
                            {message.assistant_content && (
                                <dd>
                                    <ReactMarkdown>{message.assistant_content}</ReactMarkdown>
                                    {/*<div className={'msg_meta'}>*/}
                                    {/*    <div className={'token_usage'}>*/}
                                    {/*        <div>Input Tokens: {message.input_tokens}</div>*/}
                                    {/*        <div>Output Tokens: {message.output_tokens}</div>*/}
                                    {/*    </div>*/}
                                    {/*    <div className={`votes`}>*/}
                                    {/*        {chat_context.showRatingPO ? popoverOverlay : ""}*/}
                                    {/*        <div className={`vote up`} onClick={() => { handleClick(1, index) }}>*/}
                                    {/*            {message.rating === 1 ? (<HandThumbsUpFill color="#ccc" size={20}/>) : (<HandThumbsUp color="#ccc" size={20}/>)}*/}
                                    {/*        </div>*/}
                                    {/*        <div className={`vote down`} onClick={() => { handleClick(0, index) }}>*/}
                                    {/*            {message.rating === 0 ? (<HandThumbsDownFill color="#ccc" size={20}/>) : (<HandThumbsDown color="#ccc" size={20}/>)}*/}
                                    {/*        </div>*/}
                                    {/*    </div>*/}
                                    {/*</div>*/}
                                </dd>
                            )}
                        </dl>
                        {index < allMessages.length - 1 && <hr className="divider" />}
                    </React.Fragment>
                ))
            ) : (
                <p className={`empty`}>
                    <em className={`soft_text`}>{intro_text}</em>
                </p>
            )}
            {chat_context.chatContext && chat_context.chatContext.length > 0 && (
                <dl className="soft_text floating-message">
                    <dd>{end_session_text}</dd>
                </dl>
            )}
        </div>
    );
};

export default Messages;
