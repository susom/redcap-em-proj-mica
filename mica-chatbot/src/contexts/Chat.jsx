import { createContext, useState, useRef } from 'react';
import {saveNewSession, updateSession, getSession, getCurrentUser} from '../components/database/dexie';

export const ChatContext = createContext();

/** Never show a participant a raw transport payload. */
const GENERIC_TURN_FAILURE = "That didn't reach MICA. Check your connection and try again.";

/**
 * The module answers with HTTP 200 and {"error": ..., "success": false} on a
 * caught exception (MICA.php redcap_module_ajax), and assets/jsmo.js hands that
 * body to errorCallback as an unparsed JSON *string*. So an error arrives here as
 * a string that may or may not be JSON, may be an Error, or may be a bare
 * message. Reduce all of those to one sentence a participant can act on.
 */
const readErrorMessage = (err) => {
    if (!err) return GENERIC_TURN_FAILURE;
    if (err instanceof Error) return err.message || GENERIC_TURN_FAILURE;

    let candidate = err;
    if (typeof candidate === 'string') {
        const trimmed = candidate.trim();
        if (trimmed === '') return GENERIC_TURN_FAILURE;
        try {
            candidate = JSON.parse(trimmed);
        } catch {
            // Not JSON. Only surface it if it reads like prose rather than a payload.
            return /[{}[\]<>]/.test(trimmed) ? GENERIC_TURN_FAILURE : trimmed;
        }
    }
    if (candidate && typeof candidate === 'object') {
        const message = candidate.error || candidate.message;
        if (typeof message === 'string' && message.trim() !== '') return message.trim();
    }
    return GENERIC_TURN_FAILURE;
};

export const ChatContextProvider = ({ children }) => {
    const [apiContext, setApiContext] = useState([]);
    const [chatContext, setChatContext] = useState([]);
    const [showRatingPO, setShowRatingPO] = useState(false);
    const [sessionId, setSessionId] = useState(Date.now().toString());
    const [messages, setMessages] = useState([]);
    const [msgCount, setMsgCount] = useState(0);

    // Session lifecycle. 'blocked' is a terminal state: the server raised one of
    // its gates and there is no session to hold. It is rendered as a system
    // notice and it takes the composer away, rather than being pushed into the
    // transcript as an assistant message over a live input (docs 14 D7, and
    // critique 2026-08-19 P0).
    const [sessionState, setSessionState] = useState('active'); // 'active' | 'blocked'
    const [blockedReason, setBlockedReason] = useState(null);

    // A turn is in flight. Guards against double-send and drives the pending row.
    const [pending, setPending] = useState(false);
    // The last turn failed and can be retried. { index, message }
    const [turnError, setTurnError] = useState(null);
    // A session-level failure that is NOT terminal: the session and the messages
    // still exist, only an operation on them failed. Kept separate from
    // `blocked` on purpose - blocking would take the transcript and the End
    // Session button away, which is exactly the "no way to retry" half of
    // docs 14 D16.
    const [sessionError, setSessionError] = useState(null);

    const apiContextRef = useRef(apiContext);
    const chatContextRef = useRef(chatContext);
    const pendingRef = useRef(false);

    const setPendingBoth = (value) => {
        pendingRef.current = value;
        setPending(value);
    };

    const blockSession = (reason) => {
        setBlockedReason(reason);
        setSessionState('blocked');
        setPendingBoth(false);
        setTurnError(null);
        setSessionError(null);
    };

    /** Report a recoverable, session-level failure without tearing the session down. */
    const reportSessionError = (message) => setSessionError(message || null);
    const clearSessionError = () => setSessionError(null);

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
        setTurnError(null);
        setSessionError(null);
        setPendingBoth(false);

        // Filter apiContext to keep only "system" roles
        const filteredApiContext = apiContextRef.current.filter(entry => entry.role === "system");
        chatContextRef.current = [];
        apiContextRef.current = filteredApiContext;

        setChatContext([]);
        setApiContext(filteredApiContext);
    };

    const replaceSession = async (session) => {
        setSessionId(session.session_id);
        const queries = session.queries || [];
        await updateChatContext(queries);
        setMessages(queries);
        setMsgCount(queries.length);

        // Rebuild the model-facing context as well. Restoring only the display state left the model
        // with no history at all, so a participant who reloaded mid-session saw their conversation
        // but the counselor had forgotten it (docs 14 D6).
        //
        // The system context is prepended here rather than left to callAjax, so that (a) it stays
        // ahead of the history instead of being appended after it, and (b) every entry carries
        // user_id - the backend reads the participant id off the *first* message.
        const user_id = window.mica_bootstrap?.participant_id;
        const initial = window.mica_jsmo_module?.getInitialSystemContext?.();
        const systemEntries = (Array.isArray(initial) ? initial : (initial ? [initial] : []))
            .filter(ctx => ctx && ctx.role && ctx.content)
            .map((ctx, index) => ({ role: ctx.role, content: ctx.content, index, user_id }));

        const rebuilt = [...systemEntries];
        queries.forEach((q, index) => {
            if (q.user_content) rebuilt.push({ role: 'user', content: q.user_content, index, user_id });
            if (q.assistant_content) rebuilt.push({ role: 'assistant', content: q.assistant_content, index, user_id });
        });
        updateApiContext(rebuilt);
    };

    /**
     * Send the already-recorded turn at `index` to the model.
     * Split out of callAjax so a failed turn can be retried without duplicating
     * the participant's message in either the transcript or the model context.
     */
    const dispatchTurn = (index, callback) => {
        setPendingBoth(true);
        setTurnError(null);

        const wrappedPayload = [...apiContextRef.current];

        const finish = () => {
            setPendingBoth(false);
            if (callback) callback();
        };

        const fail = (err) => {
            // Previously this path only console.log'd, so the participant's message
            // sat in the transcript with no reply, no error and no retry, forever
            // (docs: critique 2026-08-19, heuristic 9).
            console.error('MICA: turn failed', err);
            setTurnError({ index, message: readErrorMessage(err) });
            finish();
        };

        try {
            window.mica_jsmo_module.callAI(wrappedPayload, (res) => {
                if (res && res.response) {
                    updateMessage(res, index);
                    finish();
                } else {
                    fail(res);
                }
            }, fail);
        } catch (err) {
            fail(err);
        }
    };

    const callAjax = async (payload, callback) => {
        // One turn at a time. Without this the composer stayed live during a
        // request, so a participant on a slow connection who tapped send twice
        // produced interleaved turns.
        if (pendingRef.current) {
            if (callback) callback();
            return;
        }

        // Readiness check. Without it a missing cached identity dropped the message with no echo,
        // no request and no error - the send button simply did nothing (docs 14 D22).
        const currentUser = await getCurrentUser();
        if (!currentUser?.[0]?.id) {
            console.error('MICA: cannot send - no participant identity cached for this session', window.mica_bootstrap);
            // When the server explained why there is no session (a completion gate), that is a
            // terminal state, not a chat message: hand it to the session notice (docs 14 D7).
            const serverReason = window.mica_bootstrap?.error;
            if (serverReason) {
                blockSession(serverReason);
            } else {
                await updateChatContext([
                    ...chatContextRef.current,
                    { user_content: payload.content, assistant_content: null, timestamp: new Date().getTime() },
                ]);
                setTurnError({
                    index: chatContextRef.current.length - 1,
                    message: "This chat session could not be started. Please reload the page, and contact the study team if this keeps happening.",
                    retryable: false,
                });
            }
            if (callback) callback();
            return;
        }

        // Inject on "no system message yet" rather than "empty context": after a restore the context
        // is non-empty but still has no system prompt, which would have skipped injection entirely.
        if(!apiContextRef.current.some(entry => entry.role === 'system')){
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
        dispatchTurn(userMessageIndex, callback);
    };

    /** Re-send the turn that failed. The message is already recorded; only the call is repeated. */
    const retryTurn = () => {
        if (!turnError || turnError.retryable === false || pendingRef.current) return;
        dispatchTurn(turnError.index);
    };

    const updateVote = async (index, vote) => {
        const updatedState = [...chatContextRef.current];
        updatedState[index] = {
            ...updatedState[index],
            rating: vote
        };
        await updateChatContext(updatedState);
    };

    return (
        <ChatContext.Provider value={{
            messages, addMessage, clearMessages, replaceSession,
            showRatingPO, setShowRatingPO, msgCount, setMsgCount,
            sessionId, setSessionId, callAjax, chatContext, updateChatContext, updateVote,
            sessionState, blockedReason, blockSession,
            sessionError, reportSessionError, clearSessionError,
            pending, turnError, retryTurn,
        }}>
            {children}
        </ChatContext.Provider>
    );
};
