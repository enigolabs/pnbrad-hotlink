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
.wait-wrap {
  max-width: 420px; margin: 40px auto; padding: 16px;
  text-align: center;
}
.wait-card {
  background: var(--enigo-card); border: 1px solid var(--enigo-border);
  border-radius: 20px; padding: 32px 22px 28px;
  box-shadow: 0 12px 40px rgba(0,0,0,.4);
}
.wait-brand {
  font-size: 1.35rem; font-weight: 800; margin: 0 0 4px;
  background: linear-gradient(90deg, #fff, var(--enigo-cyan));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.wait-tag { color: var(--enigo-cyan); font-size: 0.8rem; margin: 0 0 24px; letter-spacing: .04em; }
.wait-spinner {
  width: 72px; height: 72px; margin: 0 auto 22px;
  border-radius: 50%;
  border: 4px solid rgba(34,211,238,.15);
  border-top-color: var(--enigo-cyan);
  animation: enigo-spin 0.9s linear infinite;
}
@keyframes enigo-spin { to { transform: rotate(360deg); } }
.wait-title { font-size: 1.1rem; font-weight: 700; color: #fff; margin: 0 0 8px; }
.wait-sub { color: var(--enigo-muted); font-size: 0.92rem; margin: 0 0 6px; }
.wait-plan { color: var(--enigo-muted); font-size: 0.85rem; margin: 16px 0 0; }
.wait-actions { margin-top: 22px; display: none; }
.wait-actions.show { display: block; }
.wait-btn {
  display: inline-block; margin: 6px 4px; padding: 10px 18px; border-radius: 999px;
  font-weight: 600; text-decoration: none !important; font-size: 0.9rem;
}
.wait-btn-primary {
  background: linear-gradient(135deg, var(--enigo-teal), var(--enigo-cyan));
  color: #041016 !important;
}
.wait-btn-ghost {
  border: 1px solid var(--enigo-border); color: var(--enigo-muted) !important;
}
.wait-checkout {
  margin-top: 14px; font-size: 0.85rem;
}
.wait-checkout a { color: var(--enigo-cyan); }
</style>

<div class="wait-wrap">
    <div class="wait-card">
        <p class="wait-brand">{$_c['CompanyName']|default:'eNiGoLabs'}</p>
        <p class="wait-tag">fast secure networks</p>
        <div class="wait-spinner" id="waitSpinner" aria-hidden="true"></div>
        <h2 class="wait-title" id="waitTitle">Please wait — confirming your payment…</h2>
        <p class="wait-sub" id="waitStatus">Checking…</p>
        <p class="wait-sub" id="waitHint" style="font-size:0.8rem;">Approve the M-Pesa prompt on your phone if shown.</p>
        {if $plan_name}
            <p class="wait-plan">Package: <strong style="color:#fff;">{$plan_name}</strong></p>
        {/if}
        {if $checkout_url}
            <p class="wait-checkout" id="checkoutHint">
                If payment did not open automatically,
                <a href="{$checkout_url}" id="checkoutLink" target="_self">continue to checkout</a>.
            </p>
        {/if}
        <div class="wait-actions" id="waitActions">
            <a href="{$_url}portal" class="wait-btn wait-btn-primary">Try again</a>
            <a href="{$_url}portal" class="wait-btn wait-btn-ghost">Back to packages</a>
        </div>
    </div>
</div>

<script>
(function () {
    var trxId = {$trx_id|default:0};
    var statusUrl = '?_route=portal/pay-status&trx=' + encodeURIComponent(trxId);
    var checkoutUrl = {if $checkout_url}'{$checkout_url|escape:'html'}'{else}''{/if};
    var gateway = '{$gateway|default:''}';
    var pollMs = 2500;
    var ticks = 0;
    var messages = ['Checking…', 'Almost done…', 'Confirming payment…', 'Still working…'];
    var stopped = false;
    var redirectedCheckout = false;

    var elStatus = document.getElementById('waitStatus');
    var elTitle = document.getElementById('waitTitle');
    var elActions = document.getElementById('waitActions');
    var elSpinner = document.getElementById('waitSpinner');

    var needCheckout = {if $need_checkout}true{else}false{/if};
    // IntaSend checkout fallback (non-STK): send user to hosted checkout once, return URL lands back here.
    if (needCheckout && checkoutUrl && !redirectedCheckout) {
        redirectedCheckout = true;
        setTimeout(function () {
            if (!stopped) window.location.href = checkoutUrl;
        }, 600);
    }

    function failUi(msg) {
        stopped = true;
        if (elSpinner) elSpinner.style.display = 'none';
        if (elTitle) elTitle.textContent = 'Payment not confirmed';
        if (elStatus) elStatus.textContent = msg || 'Please try again or pick another package.';
        if (elActions) elActions.className = 'wait-actions show';
        var hint = document.getElementById('waitHint');
        if (hint) hint.style.display = 'none';
    }

    function poll() {
        if (stopped) return;
        ticks++;
        if (elStatus) elStatus.textContent = messages[ticks % messages.length];
        var xhr = new XMLHttpRequest();
        xhr.open('GET', statusUrl + '&_=' + Date.now(), true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
            if (!data || !data.status) {
                setTimeout(poll, pollMs);
                return;
            }
            if (data.status === 'paid') {
                stopped = true;
                if (elTitle) elTitle.textContent = 'Connected — opening Google…';
                if (elStatus) elStatus.textContent = 'Payment confirmed';
                window.location.href = data.redirect || 'https://www.google.com';
                return;
            }
            if (data.status === 'failed') {
                failUi(data.message === 'timeout' ? 'Payment timed out.' : 'Payment failed or was cancelled.');
                if (data.redirect) {
                    setTimeout(function () { window.location.href = data.redirect; }, 2500);
                }
                return;
            }
            setTimeout(poll, pollMs);
        };
        xhr.onerror = function () { setTimeout(poll, pollMs); };
        xhr.send();
    }

    setTimeout(poll, 800);
})();
</script>

{include file="customer/footer-public.tpl"}
