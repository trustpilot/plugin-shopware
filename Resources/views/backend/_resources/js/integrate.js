window.addEventListener("message", this.receiveSettings);

function JSONParseSafe (json) {
    try {
        return JSON.parse(json);
    } catch (e) {
        return null;
    }
}

function receiveSettings(e) {
    if (e.origin === location.origin){
        return receiveInternalData(e);
    }
    const data = e.data;
    if (data.startsWith('sync:') || data.startsWith('showPastOrdersInitial:')) {
        const split = data.split(':');
        const action = {};
        action['type'] = 'handle_past_orders';
        action[split[0]] = split[1];
        this.submitPastOrdersCommand(action);
    } else if (data.startsWith('resync')) {
        const action = {};
        action['type'] = 'handle_past_orders';
        action['resync'] = 'resync';
        this.submitPastOrdersCommand(action);
    } else if (data.startsWith('issynced')) {
        const action = {};
        action['type'] = 'handle_past_orders';
        action['issynced'] = 'issynced';
        this.submitPastOrdersCommand(action);
    } else if (data.startsWith('check_product_skus')) {
        const split = data.split(':');
        const action = {};
        action['type'] = 'check_product_skus';
        action['skuSelector'] = split[1];
    } else {
        handleJSONMessage(data);
    }
}

function submitPastOrdersCommand(data) {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status >= 400) {
                console.log(`callback error: ${xhr.response} ${xhr.status}`);
            } else {
                sendPastOrdersInfo(xhr.response);
            }
        }
    };
    xhr.send(encodeSettings(data));
}

function sendPastOrdersInfo(data) {
    const iframe = document.getElementById('configuration_iframe');
    const attrs = iframe.dataset;

    if (data === undefined) {
        data = attrs.pastOrders;
    }

    iframe.contentWindow.postMessage(data, checkProtocol(attrs.transfer));
}

function checkProtocol(url) {
    if (url.startsWith('//')) {
        const protocol = window.location.protocol;
        return protocol + url;
    }
    return url;
}

function receiveInternalData(e) {
    const data = e.data;
    if (data && typeof data === 'string') {
        const jsonData = JSONParseSafe(data);
        if (jsonData && jsonData.type === 'updatePageUrls') {
            submitSettings(jsonData);
        }
        if (jsonData && jsonData.type === 'newTrustBox') {
            submitSettings(jsonData);
        }
    }
}

function handleJSONMessage(data) {
    try {
        const parsedData = JSON.parse(data);
        if (parsedData.window) {
            this.updateIframeSize(parsedData);
        } else if (parsedData.type === 'submit') {
            this.submitSettings(parsedData);
        } else if (parsedData.showTotalOrders) {
            const action = {
                type: 'handle_past_orders',
                showTotalOrders: parsedData.showTotalOrders,
            };
            this.submitPastOrdersCommand(action);
        } else if (parsedData.type === 'updatePageUrls' || parsedData.type === 'newTrustBox') {
            this.submitSettings(parsedData);
        }
    } catch (e) {}
}

function updateIframeSize(settings) {
    const iframe = document.getElementById('configuration_iframe');
    if (iframe) {
        iframe.height=(settings.window.height) + "px";
    }
}

function submitSettings(parsedData) {
    let data = { type: 'handle_save_changes' };
    if (parsedData.type === 'updatePageUrls') {
        data.pageUrls = encodeURIComponent(JSON.stringify(parsedData.pageUrls));
    } else if (parsedData.type === 'newTrustBox') {
        data.customTrustBoxes = encodeURIComponent(JSON.stringify(parsedData));
    } else {
        data.settings = encodeURIComponent(JSON.stringify(parsedData.settings));
    }
    const xhr = new XMLHttpRequest();
    xhr.open('POST', ajaxurl);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.send(encodeSettings(data));
}

function encodeSettings(settings) {
    let encodedString = '';
    for (const setting in settings) {
        encodedString += `${setting}=${settings[setting]}&`
    }
    return encodedString.substring(0, encodedString.length - 1);
}

function sendSettings() {
    const iframe = document.getElementById('configuration_iframe');

    const attrs = iframe.dataset;
    const settings = JSONParseSafe(atob(attrs.settings));

    if (!settings.trustbox) {
        settings.trustbox = {}
    }

    settings.trustbox.pageUrls = JSONParseSafe(atob(attrs.pageUrls));
    settings.pluginVersion = attrs.pluginVersion;
    settings.source = attrs.source;
    settings.version = attrs.version;
    settings.basis = 'plugin';
    settings.productIdentificationOptions = JSONParseSafe(attrs.productIdentificationOptions);
    settings.isFromMarketplace = attrs.isFromMarketplace;
    settings.customTrustBoxes = attrs.customTrustboxes;
    settings.configurationScopeTree = JSONParseSafe(atob(attrs.configurationScopeTree));
    settings.shop = attrs.shop;

    if (settings.trustbox.trustboxes && attrs.sku) {
        for (trustbox of settings.trustbox.trustboxes) {
            trustbox.sku = attrs.sku;
        }
    }

    if (settings.trustbox.trustboxes && attrs.name) {
        for (trustbox of settings.trustbox.trustboxes) {
            trustbox.name = attrs.name;
        }
    }

    iframe.contentWindow.postMessage(JSON.stringify(settings), attrs.transfer);
}
