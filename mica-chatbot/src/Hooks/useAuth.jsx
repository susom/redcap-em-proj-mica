import * as React from "react";
import {db_cached_chats, user_info} from "../components/database/dexie.js";

const authContext = React.createContext();

function useAuth() {
    const [authed, setAuthed] = React.useState(false);

    const checkUserCache = async (key) => {
        return await user_info.current_user.get(key) ?? null;
    }

    const cacheUser = async (payload) => {
        let {participant_id, name} = payload?.user
        let timestamp = Date.now()
        if(participant_id && name){
            let data = {
                id: parseInt(participant_id),
                name: name,
                timestamp: Date.now()
            }

            await user_info.current_user.clear(); //There should only ever be one cached user in a browser
            await user_info.current_user.put(data);
        } else {
            console.log('unable to cache user... skipping')
        }

    }

    return {
        authed,
        login(name, email) {
            return new Promise(async (resolve, reject) => {
                try {
                    const user = await checkUserCache(1)
                    if(user){
                        setAuthed(true);
                        resolve();
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
        verifyPhone(code) {
            return new Promise(async (resolve, reject) => {
                const mica = mica_jsmo_module
                if(mica) {
                    let result = await mica_jsmo_module.verifyPhone({
                        'code': code,
                    }, (res) => {
                        console.log('valid user, logging in...')
                        setAuthed(true)
                        cacheUser(res);
                        resolve()
                    }, reject)
                } else {
                    console.error('MICA EM is not injected, cannot execute function login')
                    reject();
                }
            })
        }
    };
}

export function AuthProvider({ children }) {
    const auth = useAuth();

    return <authContext.Provider value={auth}>{children}</authContext.Provider>;
}

export default function AuthConsumer() {
    return React.useContext(authContext);
}
