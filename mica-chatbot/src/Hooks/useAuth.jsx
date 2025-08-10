import * as React from "react";
import { useContext, useEffect} from 'react';

import {db_cached_chats, user_info} from "../components/database/dexie.js";
import { ChatContext } from '../contexts/Chat';

const authContext = React.createContext();
function useAuth() {
    const [authed, setAuthed] = React.useState(false);
    const { replaceSession } = useContext(ChatContext);

    useEffect(() => {
        if (authed) return;
        const b = window.mica_bootstrap;
        if (!b || !b.participant_id || !b.name) return;

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
        let {participant_id, name} = payload?.user
        let code = payload?.code;
        let session_start_time = payload?.session_start_time;

        if(participant_id && name){
            let data = {
                id: parseInt(participant_id),
                name: name,
                code: code,
                session_start_time : session_start_time, 
                timestamp: Date.now()
            }
            await user_info.current_user.clear(); //There should only ever be one cached user in a browser
            await user_info.current_user.put(data);
        } else {
            console.log('unable to cache user... skipping')
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
        return new Promise(async (resolve, reject) => {
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
        });
    };

    return {
        authed,
        login(name, email) {
            return new Promise(async (resolve, reject) => {
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
                                let result = await mica_jsmo_module.login({
                                    'name': name,
                                    'email': email
                                }, resolve, reject)
                            }
                        }

                    } else { //Attempt logging in user via REST
                        const mica = mica_jsmo_module
                        if(mica) {
                            let result = await mica_jsmo_module.login({
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
