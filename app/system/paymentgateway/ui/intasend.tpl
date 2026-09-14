{include file="sections/header.tpl"}

<form class="form-horizontal" method="post" role="form" action="{$_url}paymentgateway/intasend">
    <div class="row">
        <div class="col-sm-12 col-md-12">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading">IntaSend Payment Gateway</div>
                <div class="panel-body">
                    <div class="alert alert-warning">
                        <strong>Keys required from V:</strong> enter IntaSend <em>test</em> or <em>live</em> keys below
                        (ISPubKey_… / ISSecretKey_…). Placeholders will not process real charges.
                        Set the same <em>challenge</em> string in IntaSend Dashboard → Webhooks.
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Publishable Key</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="intasend_publishable_key"
                                value="{$_c['intasend_publishable_key']}"
                                placeholder="ISPubKey_test_xxxxxxxx or ISPubKey_live_xxxxxxxx">
                            <span class="help-block">Required for STK + checkout. From IntaSend → Settings → API Keys.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Secret Key</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="intasend_secret_key"
                                value="{$_c['intasend_secret_key']}"
                                placeholder="ISSecretKey_test_xxxxxxxx or ISSecretKey_live_xxxxxxxx">
                            <span class="help-block">Bearer token for STK / status when required. Keep backend-only.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Webhook Challenge</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="intasend_webhook_secret"
                                value="{$_c['intasend_webhook_secret']}"
                                placeholder="Same challenge string set in IntaSend Webhooks">
                            <span class="help-block">
                                IntaSend posts a <code>challenge</code> field — must match this value.
                                Leave empty only for local smoke tests (not recommended in production).
                            </span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Mode</label>
                        <div class="col-md-6">
                            {assign var=mode value=$_c['intasend_mode']|default:'test'}
                            <select class="form-control" name="intasend_mode">
                                <option value="test" {if $mode eq 'test'}selected{/if}>Test (sandbox.intasend.com)</option>
                                <option value="live" {if $mode eq 'live'}selected{/if}>Live (payment.intasend.com)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Currency</label>
                        <div class="col-md-6">
                            <select class="form-control" name="intasend_currency">
                                {assign var=cur value=$_c['intasend_currency']|default:'KES'}
                                {foreach from=['KES','UGX','TZS','GHS','NGN','USD'] item=c}
                                    <option value="{$c}" {if $cur eq $c}selected{/if}>{$c}</option>
                                {/foreach}
                            </select>
                            <span class="help-block">Default KES for M-Pesa STK. Amount sent as plan price (major units).</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">Webhook Url</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" readonly
                                value="{$_url}callback/intasend"
                                onclick="this.select()">
                            <span class="help-block">Paste into IntaSend Dashboard → Webhooks. Prefer HTTPS in production.</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="col-lg-offset-2 col-lg-10">
                            <button class="btn btn-primary" type="submit">{Lang::T('Save Changes')}</button>
                        </div>
                    </div>

                    <pre>/ip hotspot walled-garden
add dst-host=intasend.com
add dst-host=*.intasend.com
add dst-host=sandbox.intasend.com
add dst-host=payment.intasend.com</pre>
                    <small class="form-text text-muted">Add IntaSend hosts to Mikrotik walled garden so captive clients can pay (checkout fallback).</small>
                </div>
            </div>
        </div>
    </div>
</form>

{include file="sections/footer.tpl"}
