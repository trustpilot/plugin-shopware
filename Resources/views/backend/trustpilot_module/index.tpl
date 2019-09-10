
{extends file="parent:backend/_base/layout.tpl"}

{block name="content/main"}
    <div style='display:block;'>
        <iframe
            style='display: inline-block;'
            src='{$integrationAppUrl}'
            id='configuration_iframe'
            frameborder='0'
            scrolling='no'
            width='100%'
            height='1400px'
            data-plugin-version='{$pluginVersion}'
            data-source='Shopware'
            data-version='Shopware-{$version}'
            data-page-urls='{$pageUrls}'
            data-transfer='{$integrationAppUrl}'
            data-past-orders='{$pastOrders}'
            data-custom-trustboxes='{$customTrustBoxes}'
            data-settings='{$settings}'
            data-product-identification-options='{$productIdentificationOptions}'
            data-is-from-marketplace='{$isFromMarketplace}'
            data-configuration-scope-tree='{$configurationScopeTree}'
            data-shop='{$shop}'
            onload='sendSettings();sendPastOrdersInfo();'>
        </iframe>
    </div>
    <script>
        ajaxurl = '{$ajaxUrl}';
    </script>
{/block}
