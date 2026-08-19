import * as React from "react";
import { useContext, useEffect} from 'react';

import {user_info} from "../components/database/dexie.js";
import { ChatContext } from '../contexts/Chat';

const authContext = React.createContext();
function useAuth() {
    const [authed, setAuthed] = React.useState(false);
    const { replaceSession, blockSession } = useContext(ChatContext);

    useEffect(() => {
        if (authed) return;
        const b = window.mica_bootstrap;
        // `name` is optional: it comes from the pilot-only `participant_name` field, which does not
        // exist in the R01 project structure. Requiring it here aborted the whole bootstrap, so no
        // user was ever cached and every message was silently dropped downstream (docs 14 D22).
        //
        // With no bootstrap at all there is no participant and no session, so this is terminal too.
        // Returning quietly left a greeting and a live composer on screen that could never send.
        if (!b || !b.participant_id) {
            setAuthed(true);
            blockSession(
                'This chat could not be opened because the session did not load. '
                + 'Please reload the page, and contact the study team if this keeps happening.'
            );
            return;
        }

        // The server raises the session gates ("Session already completed", "Return in N day(s) for
        // your next session!") as exceptions and forwards the message (docs 14 D7). It is a terminal
        // state, not a chat turn: hand it to the session notice, which suppresses the greeting, the
        // end-session reminder and the composer. Rendering it as an assistant message put study
        // system text in MICA's bubble, under MICA's avatar, above an input that still accepted
        // messages (critique 2026-08-19, P0).
        if (b.error) {
            setAuthed(true);
            blockSession(b.error);
            return;
        }

        // Mirror verifyEmail() side-effects
        setAuthed(true);
        if (b.initial_system_context) {
            window.mica_jsmo_module.data = b.initial_system_context;
        }
        window.mica_jsmo_module.this_session = b.current_session || null;

        // Load any saved chat and cache the user (same shapes as before)
        (async () => {
            await fetchSavedSession(b.participant_id, b.name, b.session_start_time);
            await cacheUser({
            user: { participant_id: b.participant_id, name: b.name },
            code: "BOOTSTRAP",
            session_start_time: b.session_start_time
            });
        })();
    // Deliberately one-shot: this is the bootstrap handshake, guarded by `authed`
    // above. blockSession and fetchSavedSession are recreated every render, so
    // listing them would re-run the handshake continuously.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [authed]);

    const checkUserCache = async () => {
        try {
            const firstEntry = await user_info.table('current_user').toArray();
            return firstEntry.length ? firstEntry[0] : null;
        } catch (error) {
            console.error('Failed to retrieve user from cache:', error);
            return null;
        }
    }

    const cacheUser = async (payload) => {
        let {participant_id, name} = payload?.user ?? {}
        let code = payload?.code;
        let session_start_time = payload?.session_start_time;

        if(participant_id){
            let data = {
                // Keep REDCap's record id verbatim. parseInt() turned any non-numeric record id
                // into NaN, which is falsy and failed the identity gate in Chat.addMessage the
                // same way a missing user did (docs 14 D22).
                id: participant_id,
                name: name ?? null,
                code: code,
                session_start_time : session_start_time,
                timestamp: Date.now()
            }
            await user_info.current_user.clear(); //There should only ever be one cached user in a browser
            await user_info.current_user.put(data);
        } else {
            console.error('MICA: no participant_id in the bootstrap - cannot cache user', payload)
        }
    }

    const fetchSavedSession = async (participant_id, name, session_start_time) => {
        const payload = { participant_id, name, session_start_time };
        window.mica_jsmo_module.fetchSavedQueries(
            payload,
            (res) => {
                if (res.current_session?.length) {
                    const sessionData = {
                        session_id: Date.now().toString(),
                        queries: res.current_session,
                    };
                    replaceSession(sessionData);
                }
            },
            (err) => {
                console.error("Error fetching session:", err);
            }
        );
    };
    
    const verifyEmail = (code) => {
        // The executor is synchronous and the async work runs inside it, so a throw
        // rejects the promise instead of becoming an unhandled rejection.
        return new Promise((resolve, reject) => {
            (async () => {
            const mica = mica_jsmo_module;
            if (!mica) {
                console.error('MICA EM is not injected, cannot execute function login');
                reject();
                return;
            }

            await mica.verifyEmail({ code }, async (res) => {
                const { participant_id, name } = res.user;
                const session_start_time = res.session_start_time;
    
                setAuthed(true);
                if (res.initial_system_context) {
                    window.mica_jsmo_module.data = res.initial_system_context;
                }
                window.mica_jsmo_module.this_session = res.currentSession;
    
                await fetchSavedSession(participant_id, name, session_start_time);
                await cacheUser({ ...res, code, session_start_time });
    
                resolve(res);
            }, reject);
            })().catch(reject);
        });
    };

    return {
        authed,
        login(name, email) {
            return new Promise((resolve, reject) => {
                (async () => {
                try {
                    const user = await checkUserCache();
                    
                    if(user && user.name === name ){
                        let timeDifferential = Date.now() - user.timestamp
                        let isWithin30min = timeDifferential <= 30 * 60 * 1000
                        if(isWithin30min  && user.code && user.session_start_time){
                            try {
                                await verifyEmail(user.code, user.session_start_time); 
                                resolve('pass');
                                return;
                            } catch (err) {
                                console.warn('Cached code verification failed:', err);
                                reject(err);
                                return;
                            }
                        } else {
                            const mica = mica_jsmo_module
                            if(mica) {
                                await mica_jsmo_module.login({
                                    'name': name,
                                    'email': email
                                }, resolve, reject)
                            }
                        }

                    } else { //Attempt logging in user via REST
                        const mica = mica_jsmo_module
                        if(mica) {
                            await mica_jsmo_module.login({
                                'name': name,
                                'email': email
                            }, resolve, reject)
                        } else {
                            console.error('MICA EM is not injected, cannot execute function login')
                            reject();
                        }
                    }
                } catch (error) {
                    console.error('Login failed: ', error)
                    reject(error);
                }
                })().catch(reject);
            });
        },
        logout() {
            return new Promise((res) => {
                setAuthed(false);
                res();
            });
        },
        verifyEmail
    };
}

export function AuthProvider({ children }) {
    const auth = useAuth();

    return <authContext.Provider value={auth}>{children}</authContext.Provider>;
}

export default function AuthConsumer() {
    return React.useContext(authContext);
}
