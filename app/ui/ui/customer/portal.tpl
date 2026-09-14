{include file="customer/header-public.tpl"}

<style>
:root {
  --enigo-bg: #0a0e17;
  --enigo-card: #121826;
  --enigo-border: #1e293b;
  --enigo-cyan: #22d3ee;
  --enigo-teal: #14b8a6;
  --enigo-text: #f1f5f9;
  --enigo-muted: #94a3b8;
  --enigo-accent: #06b6d4;
}
body.body-full, body#app {
  background: var(--enigo-bg) !important;
  color: var(--enigo-text);
  min-height: 100vh;
}
.form-head, .form-head hr, .site-logo { display: none !important; }
.portal-wrap { max-width: 960px; margin: 0 auto; padding: 16px 14px 40px; }
.portal-brand {
  display: flex; justify-content: space-between; align-items: flex-start;
  flex-wrap: wrap; gap: 12px; margin-bottom: 22px;
}
.portal-brand-mark { display: flex; flex-direction: column; gap: 2px; }
.portal-brand-name {
  font-size: 1.55rem; font-weight: 800; letter-spacing: -0.02em;
  color: var(--enigo-text); margin: 0;
  background: linear-gradient(90deg, #fff 0%, var(--enigo-cyan) 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}
.portal-brand-tag {
  margin: 0; font-size: 0.85rem; color: var(--enigo-cyan);
  font-weight: 500; letter-spacing: 0.04em; text-transform: lowercase;
}
.portal-reconnect {
  background: transparent; border: 1px solid var(--enigo-cyan); color: var(--enigo-cyan);
  border-radius: 999px; padding: 8px 16px; font-weight: 600; text-decoration: none !important;
  display: inline-flex; align-items: center; gap: 6px; transition: all .15s ease;
}
.portal-reconnect:hover { background: rgba(34,211,238,.12); color: #fff; }
.portal-lead { color: var(--enigo-muted); margin: 0 0 18px; font-size: 0.95rem; }
.portal-alert {
  border-radius: 12px; padding: 12px 14px; margin-bottom: 16px;
  border: 1px solid var(--enigo-border); background: var(--enigo-card);
}
.portal-alert-info { border-color: rgba(34,211,238,.35); color: var(--enigo-muted); }
.portal-alert-warn { border-color: rgba(251,191,36,.4); color: #fbbf24; }
.portal-alert-err { border-color: rgba(248,113,113,.4); color: #fca5a5; }
.portal-alert-ok { border-color: rgba(52,211,153,.4); color: #6ee7b7; }
.portal-grid {
  display: grid; grid-template-columns: 1fr; gap: 14px;
}
@media (min-width: 576px) {
  .portal-grid { grid-template-columns: 1fr 1fr; }
}
@media (min-width: 900px) {
  .portal-grid { grid-template-columns: 1fr 1fr 1fr; }
}
.portal-card {
  background: var(--enigo-card); border: 1px solid var(--enigo-border);
  border-radius: 16px; padding: 18px 16px 16px;
  box-shadow: 0 8px 24px rgba(0,0,0,.35); display: flex; flex-direction: column;
  transition: border-color .15s ease, transform .15s ease;
}
.portal-card:hover { border-color: rgba(34,211,238,.45); transform: translateY(-2px); }
.portal-card-title { font-size: 1.1rem; font-weight: 700; margin: 0 0 8px; color: #fff; }
.portal-price {
  font-size: 1.45rem; font-weight: 800; color: var(--enigo-cyan); margin-bottom: 10px;
}
.portal-meta { color: var(--enigo-muted); font-size: 0.88rem; margin-bottom: 14px; flex: 1; }
.portal-meta div { margin: 4px 0; }
.btn-buy-enigo {
  width: 100%; border: none; border-radius: 12px; padding: 12px 14px;
  font-weight: 700; font-size: 0.95rem; cursor: pointer;
  background: linear-gradient(135deg, var(--enigo-teal), var(--enigo-cyan));
  color: #041016; box-shadow: 0 4px 14px rgba(34,211,238,.25);
}
.btn-buy-enigo:hover { filter: brightness(1.08); }
/* Modal polish */
#phoneModal .modal-content {
  background: var(--enigo-card); color: var(--enigo-text);
  border: 1px solid var(--enigo-border); border-radius: 16px;
}
#phoneModal .modal-header, #phoneModal .modal-footer {
  border-color: var(--enigo-border);
}
#phoneModal .modal-title { color: #fff; font-weight: 700; }
#phoneModal .form-control {
  background: #0a0e17; border-color: var(--enigo-border); color: #fff;
  border-radius: 10px; height: 44px;
}
#phoneModal .input-group-addon {
  background: #0a0e17; border-color: var(--enigo-border); color: var(--enigo-cyan);
}
#phoneModal .btn-primary {
  background: linear-gradient(135deg, var(--enigo-teal), var(--enigo-cyan));
  border: none; color: #041016; font-weight: 700; border-radius: 10px;
}
#phoneModal .btn-default {
  background: transparent; border: 1px solid var(--enigo-border); color: var(--enigo-muted); border-radius: 10px;
}
#phoneModal .close { color: #fff; opacity: .7; }
#phoneModal .help-block, #phoneModal .text-muted { color: var(--enigo-muted); }
</style>

<div class="portal-wrap">
    <div class="portal-brand">
        <div class="portal-brand-mark">
            <h1 class="portal-brand-name">{$_c['CompanyName']|default:'eNiGoLabs'}</h1>
            <p class="portal-brand-tag">fast secure networks</p>
        </div>
        <a href="{$_url}portal/reconnect" class="portal-reconnect">
            <i class="glyphicon glyphicon-refresh"></i> Reconnect
        </a>
    </div>

    <p class="portal-lead">Choose a package to get online — phone number only, no password.</p>

    {if $notify}
        <div class="portal-alert {if $notify_t == 's'}portal-alert-ok{elseif $notify_t == 'e'}portal-alert-err{else}portal-alert-warn{/if}">{$notify}</div>
    {/if}

    {if Lang::arrayCount($plans) == 0}
        <div class="portal-alert portal-alert-info">
            No hotspot packages are available yet. Ask the administrator to create a Hotspot plan
            (Admin → Services → Hotspot Plans).
        </div>
    {else}
        <div class="portal-grid">
            {foreach $plans as $plan}
                <div class="portal-card">
                    <div class="portal-card-title">{$plan['name_plan']}</div>
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
                            <div><i class="glyphicon glyphicon-ok"></i> Unlimited</div>
                        {/if}
                    </div>
                    <button type="button" class="btn-buy-enigo btn-buy"
                        data-plan-id="{$plan['id']}"
                        data-plan-name="{$plan['name_plan']|escape:'html'}"
                        data-plan-price="{Lang::moneyFormat($plan['price'])}">
                        Buy &amp; Pay
                    </button>
                </div>
            {/foreach}
        </div>
    {/if}
</div>

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
                    <p>Package: <strong id="buy_plan_name"></strong> — <span id="buy_plan_price"></span></p>
                    <p class="text-muted">Your phone becomes your account. We will confirm payment on the next screen.</p>
                    <div class="form-group">
                        <label>Phone number (M-Pesa)</label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="glyphicon glyphicon-phone"></i></span>
                            <input type="tel" class="form-control" name="phone" id="buy_phone" required
                                placeholder="{if $country_code_phone}{$country_code_phone}{/if} e.g. 254712345678"
                                autocomplete="tel">
                        </div>
                        <span class="help-block">
                            Use your M-Pesa number. Spaces/dashes OK.
                            {if $country_code_phone}Default country code: <code>{$country_code_phone}</code>{/if}
                        </span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="buySubmitBtn">Continue</button>
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
    var form = document.getElementById('buyForm');
    if (form) {
        form.addEventListener('submit', function () {
            var btn = document.getElementById('buySubmitBtn');
            if (btn) { btn.disabled = true; btn.textContent = 'Please wait…'; }
        });
    }
})();
</script>

{include file="customer/footer-public.tpl"}
