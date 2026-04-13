function stripTimestamp(line) {
    return line.replace(/^\[[^\]]*\]\s*/, '');
}

function fetchLogLines(initialLoad = false) {
    fetch('plugins/log_history/action.php')
        .then(response => {
            if (response.status === 204) {
                return Promise.reject({ disabled: true });
            }
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            const logs = data.logs || data;
            const style = data.load_style || 'noty';

            if (!logs.length) {
                return;
            }

            // Temporarily unhook to avoid re-saving logs we just fetched
            plugin._replaying = true;
            logs.forEach(entry => {
                const rawMsg = entry.message || '';
                const cleanMsg = stripTimestamp(rawMsg);
                const status = entry.status || 'info';

                if (style === 'log') {
                    log(cleanMsg, false, 'std');
                } else {
                    plugin._originalNoty(cleanMsg, status);
                }
            });
            plugin._replaying = false;
        })
        .catch(err => {
            if (err.disabled) return;
            console.error("Log fetch error:", err.message);
        });
}

function sendLogToServer(msg, status) {
    fetch('plugins/log_history/log_history.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'message=' + encodeURIComponent(msg) + '&status=' + encodeURIComponent(status)
    })
    .catch(error => {
        console.log("Saving Log failed:", error);
    });
}

plugin._replaying = false;

plugin.init = function () {
    plugin._originalNoty = window.noty;
    const originalNoty = window.noty;

    window.noty = function(msg, status, noTime) {
        originalNoty(msg, status, noTime);
        if (!plugin._replaying) {
            sendLogToServer(msg, status || 'info');
        }
    };

    setTimeout(() => {
        fetchLogLines(true);
    }, 3000);

    plugin.markLoaded();
};

plugin.onRemove = function () {
};

plugin.init();
