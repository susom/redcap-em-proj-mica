;{
    const module = ExternalModules.Stanford.MICA;

    console.log("ExternalModules:", window.ExternalModules);
    if (!window.ExternalModules.moduleQueuedAjax) {
        console.error("moduleQueuedAjax is not defined!");
    } else {
        console.log("moduleQueuedAjax is defined.");
    }

    Object.assign(module, {
        InitFunction: function () {
            console.log("Calling this InitFunction() after load...", window.mica_jsmo_module.data);
        },

        getInitialSystemContext: function() {
          return  window.mica_jsmo_module.data;
        },

        callAI: async (payload, callback, errorCallback) => {
            try {
                const res = await module.ajax('callAI', payload);

                let parsedRes = JSON.parse(res);
                if (parsedRes?.response) {
                    callback(parsedRes);
                } else {
                    console.error("Failed to parse response:", res);
                    errorCallback(e);
                }
            } catch (err) {
                console.error("Error in callAI: ", err);
                errorCallback(err);
            }
        },

        login: async (payload, callback, errorCallback) => {
            const res = await module.ajax('login', payload);
            if('error' in res) {
                console.error(res['error'])
                errorCallback(res['error'])
            } else {
                callback(res)
            }

        }
    });
}
