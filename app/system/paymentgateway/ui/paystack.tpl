{include file="sections/header.tpl"}

<form class="form-horizontal" method="post" role="form" action="{$_url}paymentgateway/paystack">
    <div class="row">
        <div class="col-sm-12 col-md-12">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading">Paystack Payment Gateway</div>
                <div class="panel-body">
                    <div class="alert alert-warning">
                        <strong>Keys required from V:</strong> enter Paystack <em>test</em> or <em>live</em> keys below.
                        Placeholders will not process real charges. Webhook URL must be reachable by Paystack.
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Public Key</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="paystack_public_key"
                                value="{$_c['paystack_public_key']}"
                                placeholder="pk_test_xxxxxxxx or pk_live_xxxxxxxx">
                            <a href="https://dashboard.paystack.com/#/settings/developer" target="_blank" class="help-block">
                                https://dashboard.paystack.com/#/settings/developer
                            </a>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Secret Key</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="paystack_secret_key"
                                value="{$_c['paystack_secret_key']}"
                                placeholder="sk_test_xxxxxxxx or sk_live_xxxxxxxx">
                            <span class="help-block">Used for initialize + verify API calls.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Webhook Secret</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="paystack_webhook_secret"
                                value="{$_c['paystack_webhook_secret']}"
                                placeholder="Usually same as Secret Key (Paystack HMAC)">
                            <span class="help-block">
                                HMAC SHA512 key for <code>x-paystack-signature</code>.
                                If left empty, Secret Key is used (Paystack default).
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Currency</label>
                        <div class="col-md-6">
                            <select class="form-control" name="paystack_currency">
                                {assign var=cur value=$_c['paystack_currency']|default:'NGN'}
                                {foreach from=['NGN','GHS','ZAR','KES','USD','GBP'] item=c}
                                    <option value="{$c}" {if $cur eq $c}selected{/if}>{$c}</option>
                                {/foreach}
                            </select>
                            <span class="help-block">Amount sent to Paystack is plan price × 100 (minor units).</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Webhook Url</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" readonly
                                value="{$_url}callback/paystack"
                                onclick="this.select()">
                            <span class="help-block">Paste this into Paystack Dashboard → Settings → API Keys &amp; Webhooks.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-lg-offset-2 col-lg-10">
                            <button class="btn btn-primary" type="submit">{Lang::T('Save Changes')}</button>
                        </div>
                    </div>

                    <pre>/ip hotspot walled-garden
add dst-host=paystack.com
add dst-host=*.paystack.com</pre>
                    <small class="form-text text-muted">Add Paystack hosts to Mikrotik walled garden so captive clients can pay.</small>
                </div>
            </div>
        </div>
    </div>
</form>

{include file="sections/footer.tpl"}
