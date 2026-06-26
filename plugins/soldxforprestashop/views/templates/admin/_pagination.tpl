{* Compact pagination for the articles page *}
<span class="displaying-num">
    {$total} item{$total|plural:'s'}
</span>
{if $pages > 1}
    {assign var="q_param" value=''}
    {if $search != ''}{assign var="q_param" value="&q=`$search`"}{/if}
    {if $page > 1}
        <a class="btn btn-default btn-sm" href="{$base_url}&paged={$page - 1}{$q_param}">‹</a>
    {else}
        <span class="btn btn-default btn-sm disabled">‹</span>
    {/if}
    <span class="tablenav-paging-text">{$page} of {$pages}</span>
    {if $page < $pages}
        <a class="btn btn-default btn-sm" href="{$base_url}&paged={$page + 1}{$q_param}">›</a>
    {else}
        <span class="btn btn-default btn-sm disabled">›</span>
    {/if}
{/if}
