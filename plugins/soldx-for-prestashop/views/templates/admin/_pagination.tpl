{*
 * Soldx for PrestaShop
 *
 * @author    Soldx
 * @copyright Soldx
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 * @version   0.1.0
 *}
{* Compact pagination for the articles page *}
<span class="displaying-num">
    {$total|intval} item{$total|plural:'s'}
</span>
{if $pages > 1}
    {if $page > 1}
        <a class="btn btn-default btn-sm" href="{$base_url|escape:'html':'UTF-8'}&amp;paged={($page - 1)|intval}{if $search}&amp;q={$search|escape:'html':'UTF-8'}{/if}">‹</a>
    {else}
        <span class="btn btn-default btn-sm disabled">‹</span>
    {/if}
    <span class="tablenav-paging-text">{$page|intval} of {$pages|intval}</span>
    {if $page < $pages}
        <a class="btn btn-default btn-sm" href="{$base_url|escape:'html':'UTF-8'}&amp;paged={($page + 1)|intval}{if $search}&amp;q={$search|escape:'html':'UTF-8'}{/if}">›</a>
    {else}
        <span class="btn btn-default btn-sm disabled">›</span>
    {/if}
{/if}
