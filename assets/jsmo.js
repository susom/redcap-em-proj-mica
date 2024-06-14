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
                // console.log("calling callAI() with payload:", payload);
                const res = await module.ajax('callAI', payload);

                let parsedRes;
                try {
                    parsedRes = JSON.parse(res);
                } catch (e) {
                    console.error("Failed to parse response:", res);
                    errorCallback(e);
                    return;
                }

                if (parsedRes?.response) {
                    callback(parsedRes);
                } else {
                    console.log("No response field in parsed response:", parsedRes);
                }
            } catch (err) {
                errorCallback(err);
            }
        }
    });
}
