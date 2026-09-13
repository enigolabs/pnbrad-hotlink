{include file="customer/header-public.tpl"}

<style>
.portal-wrap { max-width: 960px; margin: 0 auto; padding: 15px; }
.portal-card { border-radius: 8px; margin-bottom: 16px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.portal-card .box-header { font-size: 18px; font-weight: 700; }
.portal-price { font-size: 22px; font-weight: 700; color: #3c8dbc; }
.portal-meta { color: #666; margin: 8px 0 12px; }
.portal-top { display:flex; justify-content: space-between; align-items:center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
</style>

<div class="portal-wrap">
    <div class="portal-top">
        <div>
            <h2 style="margin:0;">{$_c['CompanyName']|default:'Hotspot'}</h2>
            <p class="text-muted" style="margin:4px 0 0;">Choose a package to get online - no password required</p>
        </div>
        <a href="{$_url}portal/reconnect" class="btn btn-default">
            <i class="glyphicon glyphicon-refresh"></i> Reconnect
        </a>
    </div>

    {if $notify}
        <div class="alert alert-{if $notify_t == 's'}success{elseif $notify_t == 'e'}danger{else}warning{/if}">{$notify}</div>
    {/if}

    {if Lang::arrayCount($plans) == 0}
        <div class="alert alert-info">
            No hotspot packages are available yet. Ask the administrator to create a Hotspot plan
            (Admin -> Services -> Hotspot Plans).
        </div>
    {else}
        <div class="row">
            {foreach $plans as $plan}
                <div class="col-sm-6 col-md-4">
                    <div class="box box-primary portal-card">
                        <div class="box-header">{$plan['name_plan']}</div>
                        <div class="box-body">
                            <div class="portal-price">{Lang::moneyFormat($plan['price'])}</div>
                            <div class="portal-meta">
                                <div><i class="glyphicon glyphicon-time"></i> {$plan['validity']} {$plan['validity_unit']}</div>
                                {if $plan['typebp'] == 'Limited'}
                                    <div><i class="glyphicon glyphicon-dashboard"></i>
                                        {if $plan['limit_type'] == 'Data_Limit' || $plan['limit_type'] == 'Both_Limit'}
                                            {$plan['data_limit']} {$plan['data_unit']}
                                        {/if}
                                        {if $plan['limit_type'] == 'Time_Limit' || $plan['limit_type'] == 'Both_Limit'}
                                            {$plan['time_limit']} {$plan['time_unit']}
                                        {/if}
                                    </div>
                                {else}
                                    <div><i class="glyphicon glyphicon-infinite"></i> Unlimited</div>
                                {/if}
                            </div>
                            <button type="button" class="btn btn-warning btn-block btn-buy"
                                data-plan-id="{$plan['id']}"
                                data-plan-name="{$plan['name_plan']|escape:'html'}"
                                data-plan-price="{Lang::moneyFormat($plan['price'])}">
                                Buy &amp; Pay
                            </button>
                        </div>
                    </div>
                </div>
            {/foreach}
        </div>
    {/if}
</div>

<!-- Phone modal -->
<div class="modal fade" id="phoneModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="post" action="{$_url}portal/buy" id="buyForm">
                <input type="hidden" name="csrf_token" value="{$csrf_token}">
                <input type="hidden" name="plan_id" id="buy_plan_id" value="">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Enter phone number</h4>
                </div>
                <div class="modal-body">
                    <p>Package: <strong id="buy_plan_name"></strong> - <span id="buy_plan_price"></span></p>
                    <p class="text-muted">Your phone number becomes your account. No password to remember.</p>
                    <div class="form-group">
                        <label>Phone number</label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="glyphicon glyphicon-phone"></i></span>
                            <input type="tel" class="form-control" name="phone" id="buy_phone" required
                                placeholder="{if $country_code_phone}{$country_code_phone}{/if} e.g. +2348012345678"
                                autocomplete="tel">
                        </div>
                        <span class="help-block">
                            International format preferred (+country...).Spaces/dashes OK.
                            {if $country_code_phone}Default country code from settings: <code>{$country_code_phone}</code>{/if}
                        </span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Continue to Paystack</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var buttons = document.querySelectorAll('.btn-buy');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].addEventListener('click', function () {
            document.getElementById('buy_plan_id').value = this.getAttribute('data-plan-id');
            document.getElementById('buy_plan_name').textContent = this.getAttribute('data-plan-name');
            document.getElementById('buy_plan_price').textContent = this.getAttribute('data-plan-price');
            if (window.jQuery) {
                $('#phoneModal').modal('show');
            } else {
                document.getElementById('phoneModal').style.display = 'block';
            }
        });
    }
})();
</script>

{include file="customer/footer-public.tpl"}
