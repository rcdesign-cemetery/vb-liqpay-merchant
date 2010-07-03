<?php
/*======================================================================*\
|| #################################################################### ||
|| # LiqPay Payment API 0.3                                           # ||
|| # ---------------------------------------------------------------- # ||
|| # Copyright � 2009 Dmitry Titov, Vitaly Puzrin.                    # ||
|| # All Rights Reserved.                                             # ||
|| # This file may not be redistributed in whole or significant part. # ||
|| #################################################################### ||
\*======================================================================*/

if (!isset($GLOBALS['vbulletin']->db))
{
	exit;
}

/**
* Class that provides payment verification and form generation functions
*/
class vB_PaidSubscriptionMethod_liqpay extends vB_PaidSubscriptionMethod
{
  /**
  * The variable indicating if this payment provider supports recurring transactions
  *
  * @var	bool
  */
  var $supports_recurring = false;

  /**
  * Perform verification of the payment, this is called from the payment gateway
  *
  * @return	bool	Whether the payment is valid
  */
  function verify_payment()
  {
    $this->registry->input->clean_array_gpc('p', array(
        'operation_xml' => TYPE_STR,
        'signature'     => TYPE_STR,
      ));

    if (   !strlen($this->registry->GPC['operation_xml'])
        OR !strlen($this->registry->GPC['signature']))
      return false;

    $operation_xml = base64_decode($this->registry->GPC['operation_xml']);

    if ($operation_xml === false)
      return false;

    // check signature
    $signature = base64_encode(sha1(
        $this->settings['merchant_pass']
        . $operation_xml
        . $this->settings['merchant_pass'],
        1
      ));

    if ($signature != $this->registry->GPC['signature'])
      return false;

    // signature passed
    // parse xml
    require_once(DIR . '/includes/class_xml.php');
    $xmlobj = new vB_XML_Parser($operation_xml);
    $order  = $xmlobj->parse('UTF-8');

    if ($order === false)
      return false;

    $this->transaction_id = $order['transaction_id'];

    if ($this->transaction_id > 0)
    {
      $this->paymentinfo = $this->registry->db->query_first("
          SELECT paymentinfo.*, user.username
          FROM " . TABLE_PREFIX . "paymentinfo AS paymentinfo
          INNER JOIN " . TABLE_PREFIX . "user AS user USING (userid)
          WHERE hash = '" . $this->registry->db->escape_string($order['order_id']) . "'
        ");

      // lets check the values
      if (!empty($this->paymentinfo))
      {
        $this->paymentinfo['currency'] = strtolower($order['currency']);
        $this->paymentinfo['amount']   = floatval($order['amount']);

        $sub = $this->registry->db->query_first("
            SELECT *
            FROM " . TABLE_PREFIX . "subscription
            WHERE subscriptionid = " . $this->paymentinfo['subscriptionid']
          );

        $cost = unserialize($sub['cost']);

        // Check if its a payment or if its a reversal
        if ($order['status'] == 'success')
        {
          if ($this->paymentinfo['amount'] == floatval($cost["{$this->paymentinfo[subscriptionsubid]}"]['cost'][strtolower($order['currency'])]))
          {
            $this->type = 1;
          }
          else
          {
            $this->error_code = 'invalid_payment_amount';
          }
        }
        else
        if ($order['status'] == 'wait_secure')
        {
          $this->error_code = 'payment_verification_is_in_progress';
        }
        else
        if ($order['status'] == 'failure')
        {
          $this->type = 2;
          $this->error_code = 'payment_failed';
        }
        else
        {
          $this->error_code = 'unhandled_payment_status_or_type';
        }
			}
			else
			{
				$this->error_code = 'invalid_subscriptionid';
			}

			$status_code = '200 OK';

			// LiqPay likes to get told its message has been received (?)
			if (SAPI_NAME == 'cgi' OR SAPI_NAME == 'cgi-fcgi')
			{
				header('Status: ' . $status_code);
			}
			else
			{
				header('HTTP/1.1 ' . $status_code);
			}
			return ($this->type > 0);
		}
		else
		{
			$this->error_code = 'authentication_failure';
			$this->error = 'Invalid Request';
		}

		$status_code = '503 Service Unavailable';

		// LiqPay likes to get told its message has been received (?)
		if (SAPI_NAME == 'cgi' OR SAPI_NAME == 'cgi-fcgi')
		{
			header('Status: ' . $status_code);
		}
		else
		{
			header('HTTP/1.1 ' . $status_code);
		}

		return false;
	}

	/**
	* Test that required settings are available, and if we can communicate with the server (if required)
	*
	* @return	bool	If the vBulletin has all the information required to accept payments
	*/
	function test()
	{
		return (    !empty($this->settings['merchant_id']  )
		        AND !empty($this->settings['merchant_pass']));
	}

	/**
	* Generates HTML for the subscription form page
	*
	* @param	string		Hash used to indicate the transaction within vBulletin
	* @param	string		The cost of this payment
	* @param	string		The currency of this payment
	* @param	array		Information regarding the subscription that is being purchased
	* @param	array		Information about the user who is purchasing this subscription
	* @param	array		Array containing specific data about the cost and time for the specific subscription period
	*
	* @return	array		Compiled form information
	*/
	function generate_form_html($hash, $cost, $currency, $subinfo, $userinfo, $timeinfo)
	{
		global $vbphrase, $vbulletin, $stylevar, $show;

		$item = $hash;
		$currency = strtoupper($currency);

		$form['action'] = 'https://liqpay.com/?do=click_n_buy';
		$form['method'] = 'post';

		// load settings into array so the template system can access them
		$settings =& $this->settings;

    $settings['result_url'] =
      $vbulletin->options['bburl'] . '/payments.php?do=finish&amp;method=liqpay';

    $settings['server_url'] =
      $vbulletin->options['bburl'] . '/payment_gateway.php?method=liqpay';

    $xml =
        '<request>'
      .   '<version>1.2</version>'
      .   '<result_url>'  . $settings['result_url']    . '</result_url>'
      .   '<server_url>'  . $settings['server_url']    . '</server_url>'
      .   '<merchant_id>' . $settings['merchant_id']   . '</merchant_id>'
      .   '<order_id>'    . $hash                      . '</order_id>'
      .   '<amount>'      . $cost                      . '</amount>'
      .   '<currency>'    . $currency                  . '</currency>'
      .   '<description>' . $subinfo['title']          . '</description>'
      .   '<default_phone></default_phone>'
      .   '<pay_way>card</pay_way>'
      . '</request>';

    $signature = base64_encode(sha1(
        $this->settings['merchant_pass']
        . $xml
        . $this->settings['merchant_pass'],
        1
      ));

    $operation_xml = base64_encode($xml);

        $templater = vB_Template::create('subscription_payment_liqpay');
				$templater->register('operation_xml', $operation_xml);
        $form['hiddenfields'] .= $templater->render();
		return $form;
	}
}

?>
