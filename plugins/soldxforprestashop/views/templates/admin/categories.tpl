<div class="wrap soldx-wrap">
    <h1 class="soldx-title">
        Soldx Categories
        <a class="btn btn-default" href="{$refresh_url}">Refresh</a>
    </h1>
    <p class="soldx-subtitle">Map your PrestaShop categories to Soldx Studio categories. Products pushed to Studio will be auto-categorized based on these mappings.</p>

    {if $flash}
        <div class="alert alert-{$flash.type|default:'info'}">
            <p>{$flash.msg|escape:'htmlall':'UTF-8'}</p>
        </div>
    {/if}

    {if empty($studio_cats)}
        <div class="alert alert-warning">
            <p>No Studio categories found. You can create them directly from this page using the "+ Studio" buttons, or create them in Studio first and then refresh.</p>
        </div>
    {/if}

    {if $ps_cats|@count > 0}
        <p>
            <button type="button" class="btn btn-default" id="soldx-create-all">
                Create All Unmapped in Studio
            </button>
        </p>
        <p class="soldx-search-wrap">
            <input type="search" id="soldx-cat-search" class="form-control" style="width:300px;display:inline-block"
                   placeholder="Filter categories…" />
            <span class="soldx-search-count"></span>
        </p>

        <form method="post" action="{$post_url}" class="soldx-sync-form">
            <input type="hidden" name="soldx_action" value="save_categories" />
            <input type="hidden" name="token" value="{$token}" />

            <table class="table soldx-table">
                <thead>
                    <tr>
                        <th style="width:40%">PrestaShop Category</th>
                        <th>Studio Category</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$ps_cats item=term}
                        <tr data-name="{$term.name|lower}" class="{if $term.level_depth > 2}soldx-cat-child{elseif $term.level_depth == 2}soldx-cat-root{/if}">
                            <td>
                                {if $term.level_depth > 2}
                                    <span class="soldx-cat-indent" style="margin-left:{($term.level_depth - 2) * 20}px">
                                        <span class="soldx-cat-arrow">↳</span>
                                    </span>
                                {/if}
                                <strong>{$term.name|escape:'htmlall':'UTF-8'}</strong>
                                {if $term.parent_name && $term.level_depth > 2}
                                    <span class="soldx-muted soldx-parent-hint">in {$term.parent_name|escape:'htmlall':'UTF-8'}</span>
                                {/if}
                                <br><span class="soldx-muted">#{$term.id_category} · {$term.link_rewrite|escape:'htmlall':'UTF-8'}</span>
                            </td>
                            <td class="soldx-cat-cell">
                                <select name="mapping[{$term.id_category}]" class="soldx-select soldx-cat-select" style="min-width:200px">
                                    <option value="">— Not mapped —</option>
                                    {foreach from=$studio_cats item=cat}
                                        {assign var="cat_label" value=$cat.designation|default:$cat.reference|default:$cat.id}
                                        {assign var="selected_val" value=$mapping[$term.id_category]|default:''}
                                        <option value="{$cat.id}" {if $selected_val == $cat.id}selected{/if}>
                                            {if !empty($cat.idParent)}— {/if}{$cat_label|escape:'htmlall':'UTF-8'}
                                        </option>
                                    {/foreach}
                                </select>
                                <button type="button"
                                        class="btn btn-default btn-sm soldx-create-cat-btn"
                                        data-wc-name="{$term.name|escape:'htmlall':'UTF-8'}"
                                        data-wc-term-id="{$term.id_category}"
                                        data-wc-parent="{$term.id_parent}">+ Studio</button>
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>

            <div class="panel-footer">
                <button type="submit" class="btn btn-primary">Save Mappings</button>
            </div>
        </form>
    {else}
        <div class="alert alert-info">
            <p>No PrestaShop categories found.</p>
        </div>
    {/if}
</div>
