{include file="customer/header-public.tpl"}

<div class="row">
    <div class="col-sm-6 col-sm-offset-3">
        <div class="panel panel-primary">
            <div class="panel-heading">Reconnect with phone number</div>
            <div class="panel-body">
                {if $notify}
                    <div class="alert alert-{if $notify_t == 's'}success{elseif $notify_t == 'e'}danger{else}warning{/if}">{$notify}</div>
                {/if}
                <p>Enter the phone number you used when buying a package. No password needed.</p>
                <form method="post" action="{$_url}portal/reconnect">
                    <input type="hidden" name="csrf_token" value="{$csrf_token}">
                    <div class="form-group">
                        <label>Phone number</label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="glyphicon glyphicon-phone"></i></span>
                            <input type="tel" class="form-control" name="phone" required
                                placeholder="{if $country_code_phone}{$country_code_phone}{/if} e.g. +2348012345678"
                                autocomplete="tel">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Reconnect</button>
                </form>
                <hr>
                <a href="{$_url}portal" class="btn btn-link btn-block">← Back to packages</a>
            </div>
        </div>
    </div>
</div>

{include file="customer/footer-public.tpl"}
