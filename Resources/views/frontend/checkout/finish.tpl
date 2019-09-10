{extends file="parent:frontend/checkout/finish.tpl"}

{block name='frontend_checkout_finish_teaser'}
    <script>
        if (typeof tp !== 'undefined') {
            tp('createInvitation', {$order});
        } else {
            addEventListener('trustpilotScriptLoaded', () => {
                tp('createInvitation', {$order});
            });
        }
    </script>
{/block}
