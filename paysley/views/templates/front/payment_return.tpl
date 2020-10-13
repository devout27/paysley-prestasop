{*
* 2020 Paysley
*
* NOTICE OF Paysley
*
* This source file is subject to the General Public License) (GPL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/gpl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
*  @author    Paysley <info@paysley.com>
*  @copyright 2020 Paysley
*  @license   https://www.gnu.org/licenses/gpl-3.0.html  General Public License (GPL 3.0)
*  International Registered Trademark & Property of Paysley
*}

{extends file='page.tpl'}

{block name="content"}
<section id="content-payment-return" class="card definition-list">
    <div class="card-block">
      <div class="row">
        <div class="col-md-12">
            <p>
                {l s='Your order on' mod='paysley'} {$shop_name|escape:'htmlall':'UTF-8'} {l s='is in the process.' mod='paysley'}
                <br>
                {l s='Please back again after a minutes and check your order history' mod='paysley'}
            </p>
        </div>
      </div>
    </div>
</section>
{/block}
