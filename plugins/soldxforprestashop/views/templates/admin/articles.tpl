<div class="wrap soldx-wrap soldx-wrap--fluid">
    <h1 class="soldx-title">
        Soldx Articles
        <a class="btn btn-default btn-sm" href="{$base_url}">Refresh</a>
    </h1>
    <p class="soldx-subtitle">
        Select PrestaShop products to push into Soldx Studio. Choose a sale unit (required), purchase unit, and deposit for each.
        <a class="btn btn-default btn-sm" href="{$cats_url}">Category Mapping</a>
    </p>
    <p class="soldx-safety-inline">
        <span class="icon-shield"></span>
        Read-only: nothing is modified in your PrestaShop store. Products are pushed to Studio only when you click the button below — no automation.
    </p>

    {if isset($options_error)}
        <div class="alert alert-danger">
            <p>Could not load establishment options from Studio. Check your connection in Settings, then retry.</p>
        </div>
        <p><a class="btn btn-default" href="{$settings_url}">Open Settings</a></p>
    {else}

    {if $flash}
        <div class="alert alert-{$flash.type|default:'info'}">
            <p>{$flash.msg nofilter}</p>
        </div>
    {/if}

    {* Search form *}
    <form method="get" action="{$base_url}" class="soldx-search-form">
        <input type="hidden" name="controller" value="AdminSoldxArticles" />
        <input type="hidden" name="token" value="{$token}" />
        <input type="search" id="soldx-q" name="q" value="{$search}" class="form-control"
               placeholder="Search by name or reference…" style="width:300px;display:inline-block" />
        <button type="submit" class="btn btn-default">Search</button>
        {if $search != ''}
            <a class="btn btn-link" href="{$base_url}">Clear</a>
        {/if}
    </form>

    {* Push form *}
    <form method="post" action="{$post_url}" class="soldx-sync-form" id="soldx-sync-form">
        <input type="hidden" name="soldx_action" value="sync_selected" />
        <input type="hidden" name="return_page" value="{$page}" />
        <input type="hidden" name="return_q" value="{$search}" />
        <input type="hidden" name="token" value="{$token}" />

        <div class="tablenav top soldx-tablenav">
            <div class="alignleft actions bulkactions">
                <button type="submit" class="btn btn-primary soldx-bulk-sync" id="soldx-bulk-sync">
                    Push selected to Studio
                </button>
            </div>
            <div class="tablenav-pages">
                <span class="displaying-num">{$total} item(s)</span>
                {if $pages > 1}
                    {if $page > 1}
                        <a class="btn btn-default btn-sm" href="{$base_url}&paged={$page - 1}{if $search}&q={$search}{/if}">‹</a>
                    {else}
                        <span class="btn btn-default btn-sm disabled">‹</span>
                    {/if}
                    <span class="tablenav-paging-text">{$page} of {$pages}</span>
                    {if $page < $pages}
                        <a class="btn btn-default btn-sm" href="{$base_url}&paged={$page + 1}{if $search}&q={$search}{/if}">›</a>
                    {else}
                        <span class="btn btn-default btn-sm disabled">›</span>
                    {/if}
                {/if}
            </div>
        </div>

        <table class="table soldx-table">
            <thead>
                <tr>
                    <th class="soldx-check"><input type="checkbox" id="soldx-select-all" /></th>
                    <th class="soldx-thumb">Image</th>
                    <th class="soldx-name">Product</th>
                    <th class="soldx-sku">Ref</th>
                    <th class="soldx-price">Price</th>
                    <th class="soldx-discount">Discount</th>
                    <th class="soldx-cats">Categories</th>
                    <th class="soldx-tags">Tags</th>
                    <th class="soldx-unit">Sale unit</th>
                    <th class="soldx-unit">Purchase unit</th>
                    <th class="soldx-deposit">Deposit</th>
                    <th class="soldx-publish">Publish</th>
                    <th class="soldx-status">Status</th>
                </tr>
            </thead>
            <tbody>
                {if empty($items)}
                    <tr>
                        <td colspan="13">No products found.</td>
                    </tr>
                {else}
                    {foreach from=$items item=p name=prodloop}
                    {assign var="pid" value=$p.id_product}
                    {assign var="su" value=$defaults.saleUnitId}
                    {assign var="pu" value=$defaults.purchaseUnitId}
                    {assign var="dp" value=$defaults.depositId}
                    <tr>
                        <td class="soldx-check">
                            <input type="checkbox" name="product_ids[]" value="{$pid}" class="soldx-row-check" />
                        </td>
                        <td class="soldx-thumb">
                            {if $p.image_url}
                                <img src="{$p.image_url}" class="soldx-thumb-img" alt="" />
                            {else}
                                <span class="soldx-thumb-placeholder">—</span>
                            {/if}
                        </td>
                        <td class="soldx-name">
                            {$p.name|escape:'html':'UTF-8'}
                            <br><span class="soldx-muted">#{$pid}</span>
                        </td>
                        <td class="soldx-sku">
                            <code>{if $p.reference}{$p.reference|escape:'html':'UTF-8'}{else}—{/if}</code>
                        </td>
                        <td class="soldx-price">
                            {Tools::displayPrice($p.price)}
                        </td>
                        <td class="soldx-discount">
                            {if $p.has_discount}
                                <span class="soldx-badge soldx-badge--warn">-{$p.discount_percent|string_format:"%.0f"}%</span>
                                <br><span class="soldx-muted">{Tools::displayPrice($p.sale_price)}</span>
                            {else}
                                <span class="soldx-muted">—</span>
                            {/if}
                        </td>
                        <td class="soldx-cats">
                            {if empty($p.resolved_cats)}
                                <span class="soldx-muted">—</span>
                            {else}
                                {foreach from=$p.resolved_cats item=cid}
                                    {assign var="cat_label" value=$studio_cats[$cid]|default:$cid}
                                    <span class="soldx-badge soldx-badge--cat">{$cat_label|escape:'html':'UTF-8'}</span>
                                {/foreach}
                            {/if}
                        </td>
                        <td class="soldx-tags">
                            <div class="soldx-tag-pills">
                                {foreach from=$studio_tags item=stag}
                                    {assign var="tag_id" value=$stag.id|default:''}
                                    {* Determine label: nameFr > name *}
                                    {assign var="tag_fr" value=''}
                                    {assign var="tag_nm" value=''}
                                    {if isset($stag.nameFr) && $stag.nameFr != ''}{assign var="tag_fr" value=$stag.nameFr}{/if}
                                    {if isset($stag.name) && $stag.name != ''}{assign var="tag_nm" value=$stag.name}{/if}
                                    {assign var="tag_lbl" value=$tag_fr|default:$tag_nm}

                                    {* Check auto-match: is this Studio tag's slug in PS product tags? *}
                                    {assign var="is_matched" value=false}
                                    {if isset($ps_tags[$pid])}
                                        {foreach from=$ps_tags[$pid] item=ps_slug}
                                            {if isset($stag.slug) && $ps_slug == $stag.slug}
                                                {assign var="is_matched" value=true}
                                            {/if}
                                        {/foreach}
                                    {/if}

                                    <label class="soldx-pill{if $is_matched} soldx-pill--on{/if}">
                                        <input type="checkbox"
                                               name="overrides[{$pid}][tagIds][]"
                                               value="{$tag_id}"{if $is_matched} checked{/if} />
                                        {$tag_lbl|escape:'html':'UTF-8'}
                                    </label>
                                {/foreach}
                            </div>
                        </td>
                        <td class="soldx-unit">
                            <select name="overrides[{$pid}][saleUnitId]" class="soldx-select" required>
                                <option value="">— Select —</option>
                                {foreach from=$units item=opt}
                                    {assign var="opt_label" value=$opt.designation|default:$opt.reference|default:$opt.id}
                                    <option value="{$opt.id}" {if $su == $opt.id}selected{/if}>
                                        {$opt_label|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </td>
                        <td class="soldx-unit">
                            <select name="overrides[{$pid}][purchaseUnitId]" class="soldx-select">
                                <option value="">— Select —</option>
                                {foreach from=$units item=opt}
                                    {assign var="opt_label" value=$opt.designation|default:$opt.reference|default:$opt.id}
                                    <option value="{$opt.id}" {if $pu == $opt.id}selected{/if}>
                                        {$opt_label|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </td>
                        <td class="soldx-deposit">
                            <select name="overrides[{$pid}][depositId]" class="soldx-select">
                                <option value="">— Select —</option>
                                {foreach from=$deposits item=opt}
                                    {assign var="opt_label" value=$opt.designation|default:$opt.reference|default:$opt.id}
                                    <option value="{$opt.id}" {if $dp == $opt.id}selected{/if}>
                                        {$opt_label|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </td>
                        <td class="soldx-publish">
                            <input type="checkbox" name="overrides[{$pid}][published]" value="1" checked />
                        </td>
                        <td class="soldx-status">
                            {if isset($mappings[$pid])}
                                {assign var="map_status" value=$mappings[$pid].sync_status}
                                {if $map_status == 'SYNCED'}
                                    <span class="soldx-badge soldx-badge--ok">Synced</span>
                                {elseif $map_status == 'ERROR'}
                                    <span class="soldx-badge soldx-badge--err">Error</span>
                                {else}
                                    <span class="soldx-badge soldx-badge--warn">Pending</span>
                                {/if}
                            {else}
                                <span class="soldx-badge soldx-badge--new">New</span>
                            {/if}
                        </td>
                    </tr>
                    {/foreach}
                {/if}
            </tbody>
        </table>

        <div class="tablenav bottom soldx-tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num">{$total} item(s)</span>
                {if $pages > 1}
                    {if $page > 1}
                        <a class="btn btn-default btn-sm" href="{$base_url}&paged={$page - 1}{if $search}&q={$search}{/if}">‹</a>
                    {else}
                        <span class="btn btn-default btn-sm disabled">‹</span>
                    {/if}
                    <span class="tablenav-paging-text">{$page} of {$pages}</span>
                    {if $page < $pages}
                        <a class="btn btn-default btn-sm" href="{$base_url}&paged={$page + 1}{if $search}&q={$search}{/if}">›</a>
                    {else}
                        <span class="btn btn-default btn-sm disabled">›</span>
                    {/if}
                {/if}
            </div>
        </div>
    </form>

    {/if}{* end options_error else *}
</div>
