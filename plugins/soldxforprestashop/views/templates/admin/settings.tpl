<div class="wrap soldx-wrap">
    <h1 class="soldx-title">Soldx Sync</h1>
    <p class="soldx-subtitle">Connect your PrestaShop shop to Soldx Studio to push products into Studio.</p>

    {if $flash}
        <div class="alert alert-{$flash.type|default:'info'}">
            <p>{$flash.msg nofilter}</p>
        </div>
    {/if}

    <div class="soldx-safety">
        <div class="soldx-safety-icon">
            <span class="icon-cloud-upload" style="font-size:32px"></span>
        </div>
        <div class="soldx-safety-body">
            <h3>Your store is safe</h3>
            <ul>
                <li>This module is read-only: it never modifies, deletes, or reorders your PrestaShop products, orders, or settings.</li>
                <li>Nothing happens automatically. Products are sent to Studio only when you manually click "Push to Studio".</li>
                <li>No background tasks, no cron jobs, no webhooks — zero automation.</li>
                <li>You choose exactly which products to push, one by one.</li>
                <li>Disconnect at any time. Your PrestaShop data is never affected.</li>
            </ul>
        </div>
    </div>

    {if $is_connected}
        <div class="soldx-card soldx-card--connected">
            <div class="soldx-card-body">
                <h2 class="soldx-card-title">
                    <span class="soldx-dot soldx-dot--ok"></span>
                    Connected
                </h2>
                <p class="soldx-card-meta">
                    Establishment: <strong>{$etb_name|default:'—'}</strong> ·
                    Integration: <code>{$integration_short}</code>
                </p>
                <p>
                    <a class="btn btn-primary" href="{$articles_url}">Go to Articles</a>
                    <a class="btn btn-default" href="{$categories_url}">Category Mapping</a>
                </p>
            </div>
        </div>
    {/if}

    <form method="post" action="{$post_url}" class="soldx-form form-horizontal">
        <input type="hidden" name="soldx_action" value="save" />
        <input type="hidden" name="token" value="{$token}" />

        <div class="panel">
            <div class="form-group">
                <label class="control-label col-lg-3" for="studio_url">Studio URL</label>
                <div class="col-lg-9">
                    <input type="url" id="studio_url" name="studio_url" class="form-control"
                           placeholder="https://studio.soldx.tn"
                           value="{$studio_url}" autocomplete="off" />
                    <p class="help-block">The base URL of your Soldx Studio installation (no trailing slash).</p>
                </div>
            </div>

            <div class="form-group">
                <label class="control-label col-lg-3" for="api_key">API Key</label>
                <div class="col-lg-9">
                    <input type="password" id="api_key" name="api_key" class="form-control"
                           placeholder="{if $api_key}••••••••••••{/if}"
                           value="" autocomplete="new-password" />
                    <p class="help-block">
                        {if $api_key}
                            <code>{$api_key_masked}</code>
                            Already set. Leave blank to keep the current key.
                        {else}
                            Get this from Studio &rarr; Settings &rarr; Plugins &rarr; Activate PrestaShop.
                        {/if}
                    </p>
                </div>
            </div>
        </div>

        <div class="panel-footer">
            <button type="submit" name="soldx_action" value="save" class="btn btn-default">
                Save settings
            </button>
            <button type="submit" name="soldx_action" value="test" class="btn btn-primary">
                Save &amp; Test connection
            </button>
            {if $is_connected}
                <button type="submit" name="soldx_action" value="disconnect" class="btn btn-link"
                        onclick="return confirm('Disconnect? Products already synced will stay in place but will no longer be updated.');">
                    Disconnect
                </button>
            {/if}
        </div>
    </form>

    <div class="soldx-help">
        <h3>How sync works</h3>
        <ul>
            <li>One-way push: PrestaShop &rarr; Studio only. Studio never writes back to your shop.</li>
            <li>Manual only: you pick which products to push on the Articles page — no automatic syncing, ever.</li>
            <li>For each product you choose a sale unit (required), a purchase unit, and a deposit.</li>
            <li>Pricing is synced; stock is intentionally NOT synced.</li>
            <li>Re-pushing a product updates the matching Studio article in Studio — not in PrestaShop.</li>
            <li>Uninstalling or disconnecting the module leaves your PrestaShop store exactly as it was.</li>
        </ul>
    </div>
</div>
