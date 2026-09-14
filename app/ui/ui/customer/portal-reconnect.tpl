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
}
body.body-full, body#app {
  background: var(--enigo-bg) !important;
  color: var(--enigo-text);
  min-height: 100vh;
}
.form-head, .form-head hr, .site-logo { display: none !important; }
.rc-wrap { max-width: 440px; margin: 36px auto; padding: 14px; }
.rc-card {
  background: var(--enigo-card); border: 1px solid var(--enigo-border);
  border-radius: 18px; padding: 24px 20px; box-shadow: 0 10px 32px rgba(0,0,0,.35);
}
.rc-brand {
  font-size: 1.3rem; font-weight: 800; margin: 0 0 2px; text-align: center;
  background: linear-gradient(90deg, #fff, var(--enigo-cyan));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.rc-tag { text-align: center; color: var(--enigo-cyan); font-size: 0.8rem; margin: 0 0 18px; }
.rc-card h3 { margin: 0 0 10px; color: #fff; font-size: 1.05rem; text-align: center; }
.rc-card p { color: var(--enigo-muted); font-size: 0.9rem; }
.rc-card .form-control {
  background: #0a0e17; border-color: var(--enigo-border); color: #fff;
  border-radius: 10px; height: 44px;
}
.rc-card .input-group-addon {
  background: #0a0e17; border-color: var(--enigo-border); color: var(--enigo-cyan);
}
.rc-card .btn-primary {
  width: 100%; border: none; border-radius: 12px; padding: 12px;
  font-weight: 700;
  background: linear-gradient(135deg, var(--enigo-teal), var(--enigo-cyan));
  color: #041016;
}
.rc-card a { color: var(--enigo-cyan); }
.rc-alert { border-radius: 10px; padding: 10px 12px; margin-bottom: 12px; border: 1px solid var(--enigo-border); }
</style>

<div class="rc-wrap">
    <div class="rc-card">
        <p class="rc-brand">{$_c['CompanyName']|default:'eNiGoLabs'}</p>
        <p class="rc-tag">fast secure networks</p>
        <h3>Reconnect</h3>
        {if $notify}
            <div class="rc-alert" style="color:{if $notify_t == 's'}#6ee7b7{elseif $notify_t == 'e'}#fca5a5{else}#fbbf24{/if}">{$notify}</div>
        {/if}
        <p>Enter the phone number you used when buying a package. No password needed.</p>
        <form method="post" action="{$_url}portal/reconnect">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <div class="form-group">
                <label>Phone number</label>
                <div class="input-group">
                    <span class="input-group-addon"><i class="glyphicon glyphicon-phone"></i></span>
                    <input type="tel" class="form-control" name="phone" required
                        placeholder="{if $country_code_phone}{$country_code_phone}{/if} e.g. 254712345678"
                        autocomplete="tel">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Reconnect</button>
        </form>
        <hr style="border-color:#1e293b;">
        <p style="text-align:center;margin:0;"><a href="{$_url}portal">← Back to packages</a></p>
    </div>
</div>

{include file="customer/footer-public.tpl"}
