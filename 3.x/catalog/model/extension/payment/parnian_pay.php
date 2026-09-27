<?php
/**
 * Parnian Pay — ParnianCoin (PARC) payments for OpenCart 3.0.x (storefront model).
 */
class ModelExtensionPaymentParnianPay extends Model {

	public function client() {
		require_once DIR_SYSTEM . 'library/parnianpay/client.php';
		return new ParnianPayClient($this->config->get('payment_parnian_pay_gateway_url') ?: 'https://pay.parniancoin.com', $this->config->get('payment_parnian_pay_api_key'));
	}

	public function log($message, $error = false) {
		if ($error || $this->config->get('payment_parnian_pay_debug')) {
			$this->log->write('Parnian Pay: ' . $message);
		}
	}

	/**
	 * Gateway status and rates, cached 10 minutes (2 minutes after a failure).
	 * @return array|null null when the gateway could not be reached.
	 */
	public function remoteStatus($refresh = false) {
		$c = $refresh ? null : $this->cache->get('parnian_pay_status');
		if (is_array($c) && isset($c['at']) && time() - $c['at'] < (empty($c['error']) ? 600 : 120)) {
			if (!empty($c['error'])) {
				return null;
			}
			if (isset($c['data']) && is_array($c['data'])) {
				return $c['data'];
			}
		}
		$me = $this->client()->me();
		if ($me === null) {
			$this->log('status check failed', true);
			$this->cache->set('parnian_pay_status', array('at' => time(), 'error' => true));
			return null;
		}
		$data = array(
			'active' => !empty($me['active']),
			'rates'  => (isset($me['rates']) && is_array($me['rates'])) ? $me['rates'] : array(),
		);
		$this->cache->set('parnian_pay_status', array('at' => time(), 'data' => $data));
		return $data;
	}

	public function clearStatus() {
		$this->cache->delete('parnian_pay_status');
	}

	public function getMethod($address, $total) {
		$this->load->language('extension/payment/parnian_pay');

		if (!$this->config->get('payment_parnian_pay_status') || !$this->config->get('payment_parnian_pay_api_key') || $total <= 0) {
			return array();
		}
		if ($this->config->get('payment_parnian_pay_geo_zone_id')) {
			$q = $this->db->query("SELECT * FROM " . DB_PREFIX . "zone_to_geo_zone WHERE geo_zone_id = '" . (int)$this->config->get('payment_parnian_pay_geo_zone_id') . "' AND country_id = '" . (int)$address['country_id'] . "' AND (zone_id = '" . (int)$address['zone_id'] . "' OR zone_id = '0')");
			if (!$q->num_rows) {
				return array();
			}
		}
		// Only offer it when the gateway is active and has a rate for the shopper's currency.
		// If the gateway cannot be reached right now, keep offering it rather than lose the sale.
		$status = $this->remoteStatus();
		$currency = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');
		if ($status !== null && (empty($status['active']) || empty($status['rates'][$currency]))) {
			return array();
		}

		return array(
			'code'       => 'parnian_pay',
			'title'      => $this->language->get('text_title'),
			'terms'      => '',
			'sort_order' => $this->config->get('payment_parnian_pay_sort_order'),
		);
	}

	public function getInvoiceRow($order_id) {
		$q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "parnian_pay_invoice` WHERE order_id = '" . (int)$order_id . "'");
		return $q->num_rows ? $q->row : null;
	}

	public function saveInvoiceRow($order_id, array $inv, $fiat_key) {
		$this->db->query("REPLACE INTO `" . DB_PREFIX . "parnian_pay_invoice` SET order_id = '" . (int)$order_id . "',
			invoice_id = '" . $this->db->escape($inv['id']) . "', amount = '" . $this->db->escape($inv['amount']) . "',
			fiat = '" . $this->db->escape($fiat_key) . "', rate = '" . $this->db->escape(isset($inv['rate']) ? (string)$inv['rate'] : '') . "',
			pay_url = '" . $this->db->escape($inv['pay_url']) . "', state = 'pending', date_added = NOW(), date_modified = NOW()");
	}

	/**
	 * Applies the gateway's invoice state to the order (idempotent). Returns the new local state.
	 */
	public function sync($order_id, array $inv) {
		$row = $this->getInvoiceRow($order_id);
		if (!$row) {
			return null;
		}
		$d = ParnianPayClient::decide($inv, $order_id, $row['invoice_id'], $row['amount'], $row['state']);
		if ($d === null) {
			$this->log('invoice ' . (isset($inv['id']) ? $inv['id'] : '?') . ' does not belong to order ' . $order_id, true);
			return $row['state'];
		}
		if ($d['action'] === 'none') {
			return $d['state'];
		}

		$this->load->language('extension/payment/parnian_pay');
		$this->load->model('checkout/order');
		$link = rtrim($this->config->get('payment_parnian_pay_gateway_url') ?: 'https://pay.parniancoin.com', '/') . '/pay/' . $inv['id'];
		$paid = isset($inv['paid']) ? $inv['paid'] : '0';
		$cfg = function ($k) { return (int)$this->config->get('payment_parnian_pay_' . $k . '_status_id'); };

		switch ($d['action']) {
			case 'paid':
				$this->model_checkout_order->addOrderHistory($order_id, $cfg('paid') ?: 2, sprintf($this->language->get('text_comment_paid'), $paid, $link), true);
				break;
			case 'confirming':
				$this->model_checkout_order->addOrderHistory($order_id, $cfg('pending') ?: 1, $this->language->get('text_comment_confirming'), false);
				break;
			case 'mismatch':
				$this->model_checkout_order->addOrderHistory($order_id, $cfg('review') ?: 1, sprintf($this->language->get('text_comment_mismatch'), $inv['id'], $link), false);
				break;
			case 'review':
				$key = $inv['status'] === 'late' ? 'text_comment_late' : 'text_comment_underpaid';
				$this->model_checkout_order->addOrderHistory($order_id, $cfg('review') ?: 1, sprintf($this->language->get($key), $paid, $link), false);
				break;
			case 'expired':
				$this->model_checkout_order->addOrderHistory($order_id, $cfg('expired') ?: 14, $this->language->get('text_comment_expired'), false);
				break;
		}
		$this->db->query("UPDATE `" . DB_PREFIX . "parnian_pay_invoice` SET state = '" . $this->db->escape($d['state']) . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
		$this->log('order ' . $order_id . ' -> ' . $d['state'] . ' (invoice ' . $inv['id'] . ')');
		return $d['state'];
	}
}
