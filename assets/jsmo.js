;{
    const module = ExternalModules.Stanford.MICA;

    if (!window.ExternalModules.moduleQueuedAjax) {
        console.error("moduleQueuedAjax is not defined!");
    } 

    Object.assign(module, {
        InitFunction: function () {
            console.log("Calling this InitFunction() after load...", window.mica_jsmo_module.data);
        },

        getInitialSystemContext: function() {
          return  window.mica_jsmo_module.data;
        },

        getCurrentSession: function() {
            return  window.mica_jsmo_module.this_session;
        },

        callAI: async (payload, callback, errorCallback) => {
            try {
                const res = await module.ajax('callAI', payload);
                let parsedRes = JSON.parse(res);
                if (parsedRes?.response) {
                    callback(parsedRes);
                } else {
                    console.error("Failed to parse response:", res);
                    errorCallback(res);
                }
            } catch (err) {
                console.error("Error in callAI: ", err);
                errorCallback(err);
            }
        },

        login: async (payload, callback, errorCallback) => {
            const res = await module.ajax('login', payload);
            let parsed = JSON.parse(res)

            if('error' in parsed) {
                console.error(parsed['error'])
                errorCallback(parsed['error'])
            } else {
                callback(parsed)
            }
        },

        verifyEmail: async (payload, callback, errorCallback) => {
            const res = await module.ajax('verifyEmail', payload);
            let parsed = JSON.parse(res)

            if('error' in parsed) {
                console.error(parsed['error'])
                errorCallback(parsed['error'])
            } else {
                callback(parsed)
            }
        },

        fetchSavedQueries: async (payload, callback, errorCallback) => {
            const res = await module.ajax('fetchSavedQueries', payload);
            let parsed = JSON.parse(res)

            if('error' in parsed) {
                console.error(parsed['error'])
                errorCallback(parsed['error'])
            } else {
                callback(parsed)
            }
        },

        completeSession: async (payload, callback, errorCallback) => {
            try {
                const res = await module.ajax('completeSession', payload);
                let parsed = JSON.parse(res);

                console.log("in jsmo completeSession", payload, parsed);

                if (parsed?.success) {
                    callback(parsed); // Ensure response contains `survey_link` if needed
                } else {
                    errorCallback("Unexpected response: " + res);
                }
            } catch (err) {
                console.error("Error in completeSession: ", err);
                errorCallback(err);
            }
        },
    });
}
