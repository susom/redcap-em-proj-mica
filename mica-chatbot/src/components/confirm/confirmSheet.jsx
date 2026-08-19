import { useEffect, useRef, useCallback } from "react";
import "./confirmSheet.css";

/**
 * Confirmation for actions a participant cannot take back.
 *
 * Two of them exist in this app: ending the session (irreversible, tied to
 * compensation, redirects away) and clearing the conversation from the screen.
 * Both were one-tap with no confirm, on targets under 44px (docs: critique
 * 2026-08-19, P0 "two unlabeled, unconfirmed destructive controls").
 *
 * Bottom-anchored on a phone so the primary button lands in the thumb zone -
 * this app is used one-handed on a device handed over in an ED - and centered
 * from 640px up.
 */
export function ConfirmSheet({
    open,
    title,
    body,
    confirmLabel,
    cancelLabel = "Cancel",
    tone = "neutral", // "neutral" | "destructive"
    busy = false,
    onConfirm,
    onCancel,
}) {
    const panelRef = useRef(null);
    const confirmRef = useRef(null);
    const restoreFocusRef = useRef(null);

    const handleCancel = useCallback(() => {
        if (!busy && onCancel) onCancel();
    }, [busy, onCancel]);

    // Remember what had focus so it can be handed back on close. Without this a
    // keyboard user is dropped at the top of the document after cancelling.
    useEffect(() => {
        if (!open) return;
        restoreFocusRef.current = document.activeElement;
        confirmRef.current?.focus();
        return () => {
            const el = restoreFocusRef.current;
            if (el && typeof el.focus === "function" && document.contains(el)) el.focus();
        };
    }, [open]);

    // Esc closes; Tab is trapped inside the panel.
    useEffect(() => {
        if (!open) return;
        const onKeyDown = (e) => {
            if (e.key === "Escape") {
                e.preventDefault();
                handleCancel();
                return;
            }
            if (e.key !== "Tab") return;
            const focusables = panelRef.current?.querySelectorAll(
                'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            if (!focusables || focusables.length === 0) return;
            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        };
        document.addEventListener("keydown", onKeyDown, true);
        return () => document.removeEventListener("keydown", onKeyDown, true);
    }, [open, handleCancel]);

    if (!open) return null;

    return (
        <div className="mica-confirm" role="presentation">
            <div className="mica-confirm__scrim" onClick={handleCancel} />
            <div
                className="mica-confirm__panel"
                role="dialog"
                aria-modal="true"
                aria-labelledby="mica-confirm-title"
                aria-describedby="mica-confirm-body"
                ref={panelRef}
            >
                <h2 className="mica-confirm__title" id="mica-confirm-title">{title}</h2>
                <div className="mica-confirm__body" id="mica-confirm-body">{body}</div>
                <div className="mica-confirm__actions">
                    <button
                        type="button"
                        className="mica-btn mica-btn--ghost"
                        onClick={handleCancel}
                        disabled={busy}
                    >
                        {cancelLabel}
                    </button>
                    <button
                        type="button"
                        className={`mica-btn ${tone === "destructive" ? "mica-btn--danger" : "mica-btn--primary"}`}
                        onClick={onConfirm}
                        disabled={busy}
                        ref={confirmRef}
                    >
                        {busy ? "Working…" : confirmLabel}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default ConfirmSheet;
