{extends file='parent:frontend/index/index.tpl'}

{block name="frontend_index_header_javascript_inline" append}
    {literal}
        const trustpilot_trustbox_settings = {/literal}{$trustpilotTrustboxSettings}{literal}
        dispatchEvent(new CustomEvent('trustpilotTrustboxSettingsLoaded'));
        const trustpilot_settings = {
            page: "{/literal}{$page}{literal}",
            key: "{/literal}{$integrationKey}{literal}",
        };
        const createTrustBoxScript = function() {
            const trustBoxScript = document.createElement('script');
            trustBoxScript.src = '{/literal}{$previewShopwareUrl}{literal}';
            document.head.appendChild(trustBoxScript);
        };
         const createWidgetScript = function() {
            const widgetScript = document.createElement('script');
            widgetScript.src = '{/literal}{$widgetScriptUrl}{literal}';
            document.head.appendChild(widgetScript);
        };
        createTrustBoxScript();
        createWidgetScript();
    {/literal}
{/block}
