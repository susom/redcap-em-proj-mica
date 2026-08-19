import { useState, useRef, useEffect, useContext } from "react";
import { ChatContext } from "../../contexts/Chat";
import { Container } from 'react-bootstrap';
import { Send, EraserFill } from 'react-bootstrap-icons';
import ConfirmSheet from "../confirm/confirmSheet.jsx";
import "./footer.css";

const MAX_MESSAGE_LENGTH = 2000;

export function Footer() {
    const chat_context = useContext(ChatContext);
    const { pending, sessionState, chatContext } = chat_context;
    const [input, setInput] = useState("");
    const [confirmClear, setConfirmClear] = useState(false);
    const inputRef = useRef(null);

    const canSend = input.trim() !== "" && !pending;
    const hasConversation = (chatContext || []).length > 0;

    // Grow with the message instead of hiding it. A single-line input meant a
    // participant writing more than a few words could not see what they had typed.
    const resize = () => {
        const el = inputRef.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 132)}px`;
    };

    useEffect(resize, [input]);

    // Hand focus back to the composer when the turn completes, so a keyboard or
    // screen-reader user is not left on a control that just went away.
    useEffect(() => {
        if (!pending) inputRef.current?.focus({ preventScroll: true });
    }, [pending]);

    const handleSubmit = () => {
        if (!canSend) return;
        const content = input.trim();
        setInput("");
        chat_context.callAjax({ role: 'user', content });
    };

    const handleKeyDown = (e) => {
        // Enter sends, Shift+Enter starts a new line. Enter alone used to be the
        // only behaviour, so a multi-paragraph answer was impossible to write.
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSubmit();
        }
    };

    const handleClear = async () => {
        setConfirmClear(false);
        await chat_context.clearMessages();
        inputRef.current?.focus();
    };

    // Nothing to compose into: a blocked session has no turn to take, and the
    // notice carries the next step instead.
    if (sessionState === 'blocked') return null;

    return (
        <>
            <Container className={`container footer`}>
                <button
                    type="button"
                    className={`clear_chat`}
                    onClick={() => setConfirmClear(true)}
                    disabled={!hasConversation || pending}
                    aria-label="Clear this conversation from the screen"
                    title="Clear this conversation from the screen"
                >
                    <EraserFill size={20} aria-hidden="true" />
                </button>

                <label className="user_input_label" htmlFor="mica-composer">
                    Your message to MICA
                </label>
                <textarea
                    id="mica-composer"
                    ref={inputRef}
                    className={`user_input`}
                    rows={1}
                    placeholder={pending ? "Waiting for MICA…" : "Type your message here…"}
                    value={input}
                    maxLength={MAX_MESSAGE_LENGTH}
                    disabled={pending}
                    onChange={(e) => setInput(e.target.value)}
                    onKeyDown={handleKeyDown}
                />

                <div className="send-container">
                    <button
                        type="button"
                        className="send_btn"
                        onClick={handleSubmit}
                        disabled={!canSend}
                        aria-label={pending ? "Waiting for MICA's reply" : "Send message"}
                        title="Send message"
                    >
                        <Send size={20} aria-hidden="true" />
                    </button>
                </div>
            </Container>

            <ConfirmSheet
                open={confirmClear}
                title="Clear this conversation from the screen?"
                body={
                    <>
                        <p>The messages will disappear from your screen and MICA will start over from the beginning.</p>
                        <p>This does not delete anything. Your session is still recorded, and the study team keeps the transcript.</p>
                    </>
                }
                confirmLabel="Clear the screen"
                cancelLabel="Keep it"
                tone="destructive"
                onConfirm={handleClear}
                onCancel={() => setConfirmClear(false)}
            />
        </>
    );
}

export default Footer;
