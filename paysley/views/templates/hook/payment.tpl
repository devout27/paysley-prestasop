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

<p class="payment_module">
	<a href="{$link->getModuleLink('paysley', 'payment', [], true)|escape:'html':'UTF-8'}">
		<img src="{$this_path_paysley|escape:'htmlall':'UTF-8'}logo.png" alt="{l s='Paysley' mod='paysley'}" />
		{l s='Paysley' mod='paysley'} {l s='(order processing will be longer)' mod='paysley'}
	</a>
</p>
