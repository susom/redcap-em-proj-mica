import React, { createContext, useState, useRef, useEffect } from 'react';
import {saveNewSession, updateSession, getSession, deleteSession, getCurrentUser} from '../components/database/dexie';

export const ChatContext = createContext();

export const ChatContextProvider = ({ children }) => {
    const [apiContext, setApiContext] = useState([]);
    const [chatContext, setChatContext] = useState([]);
    const [showRatingPO, setShowRatingPO] = useState(false);
    const [sessionId, setSessionId] = useState(Date.now().toString());
    const [messages, setMessages] = useState([]);
    const [msgCount, setMsgCount] = useState(0);

    const apiContextRef = useRef(apiContext);
    const chatContextRef = useRef(chatContext);

    // useEffect(() => {
    //     console.log("apiContext updated: ", apiContext);
    // }, [apiContext]);

    const updateApiContext = (newContext) => {
        apiContextRef.current = newContext;
        setApiContext(newContext);
    };

    const saveChatContext = async () => {
        if (sessionId && chatContextRef.current.length > 0) {
            const currentSession = await getSession(sessionId);
            if (currentSession) {
                await updateSession(sessionId, chatContextRef.current);
            } else {
                await saveNewSession(sessionId, Date.now(), chatContextRef.current);
            }
        }
    };

    const updateChatContext = async (newContext, shouldSave = true) => {
        chatContextRef.current = newContext;
        setChatContext(newContext);
        // console.log("Updated chatContext:", newContext);
        if (shouldSave) {
            await saveChatContext(); // Save chat session after each update
        }
    };

    const addMessage = async (message) => {
        if (!message || !message.role) {
            console.error('MICA: addMessage called without a usable message', message);
            return;
        }
        const user = await getCurrentUser()
        if(!user?.[0]?.id) {
            // Do not fail silently here - callAjax checks readiness up front and reports to the
            // participant; this is the last-resort guard (docs 14 D22).
            console.error('MICA: dropping message - no participant identity cached', message.role);
            return;
        }
        {
            const index = chatContextRef.current.length;
            const updatedApiContext = [
                ...apiContextRef.current,
                { role: message.role, content: message.content, index, user_id: user[0].id },
            ];
            updateApiContext(updatedApiContext);

            if(message.role == "system"){
                return;
            }

            const newChatContext = [
                ...chatContextRef.current,
                {
                    user_content: message.role === 'user' ? message.content : null,
                    assistant_content: message.role === 'assistant' ? message.content : null,
                    timestamp: new Date().getTime(),
                },
            ];
            updateChatContext(newChatContext);
        }

    };

    const updateMessage = async (response, index) => {
        const { response: assistantResponse, usage, id, model } = response;
        const updatedState = [...chatContextRef.current];
        updatedState[index] = {
            ...updatedState[index],
            assistant_content: assistantResponse.content,
            input_tokens: usage ? usage.prompt_tokens : null,
            output_tokens: usage ? usage.completion_tokens : null,
            input_cost: usage ? usage.input_cost : null,
            output_cost: usage ? usage.output_cost : null,
            id: id || null,
            model: model || null,
        };
        await updateChatContext(updatedState);

        const updatedApiContext = [
            ...apiContextRef.current,
            { role: 'assistant', content: assistantResponse.content, index },
        ];
        updateApiContext(updatedApiContext);
    };

    const clearMessages = async () => {
        const newSessionId = Date.now().toString(); // Generate a new session ID
        setMsgCount(0);
        setMessages([]);
        setSessionId(newSessionId);

        // Filter apiContext to keep only "system" roles
        const filteredApiContext = apiContextRef.current.filter(entry => entry.role === "system");
        chatContextRef.current = [];
        apiContextRef.current = filteredApiContext;

        setChatContext([]);
        setApiContext(filteredApiContext);
    };

    const replaceSession = async (session) => {
        setSessionId(session.session_id);
        await updateChatContext(session.queries);
        setMessages(session.queries); 
        setMsgCount(session.queries.length);
    };

    const callAjax = async (payload, callback) => {
        // Readiness check. Without it a missing cached identity dropped the message with no echo,
        // no request and no error - the send button simply did nothing (docs 14 D22).
        const currentUser = await getCurrentUser();
        if (!currentUser?.[0]?.id) {
            console.error('MICA: cannot send - no participant identity cached for this session', window.mica_bootstrap);
            await updateChatContext([
                ...chatContextRef.current,
                {
                    user_content: payload.content,
                    assistant_content: "Sorry - this chat session could not be started. Please reload the page, and contact the study team if this keeps happening.",
                    timestamp: new Date().getTime(),
                },
            ]);
            if (callback) callback();
            return;
        }

        if(apiContextRef.current.length === 0){
            // getInitialSystemContext() may be empty (no session context resolved server-side) or
            // carry several entries (general context plus a catch-up summary). The previous .pop()
            // both crashed on the empty case and silently discarded all but the last entry
            // (docs 14 D7 and D8).
            const initial = window.mica_jsmo_module.getInitialSystemContext();
            const contexts = Array.isArray(initial) ? initial : (initial ? [initial] : []);
            if (!contexts.length) {
                console.warn('MICA: no initial system context was provided for this session');
            }
            for (const ctx of contexts) {
                if (ctx && ctx.role && ctx.content) await addMessage(ctx);
            }
        }

        await addMessage({ role: 'user', content: payload.content });

        const userMessageIndex = chatContextRef.current.length - 1;
        const wrappedPayload = [...apiContextRef.current];
        console.log("calling callAI with ", wrappedPayload);

        window.mica_jsmo_module.callAI(wrappedPayload, (res) => {
            if (res && res.response) {
                updateMessage(res, userMessageIndex);
                if (callback) callback();
            } else {
                console.log("Unexpected response format:", res);
            }
        }, (err) => {
            console.log("callAI error", err);
            if (callback) callback();
        });
    };

    const updateVote = async (index, vote) => {
        const updatedState = [...chatContextRef.current];
        updatedState[index] = {
            ...updatedState[index],
            rating: vote
        };
        await updateChatContext(updatedState);
        // console.log("Updated chatContext after vote:", updatedState);
    };

    const deleteInteraction = async (index) => {
        const updatedChatContext = [...chatContextRef.current];
        updatedChatContext.splice(index, 1);

        const updatedApiContext = apiContextRef.current.filter(entry => entry.index !== index);

        // Update the index of remaining entries
        updatedApiContext.forEach((entry, i) => {
            if (entry.index > index) {
                entry.index -= 1;
            }
        });

        updateChatContext(updatedChatContext);
        updateApiContext(updatedApiContext);
    };

    return (
        <ChatContext.Provider value={{ messages, addMessage, clearMessages, replaceSession, showRatingPO, setShowRatingPO, msgCount, setMsgCount, sessionId, setSessionId, callAjax, chatContext, updateChatContext, updateVote, deleteInteraction }}>
            {children}
        </ChatContext.Provider>
    );
};
