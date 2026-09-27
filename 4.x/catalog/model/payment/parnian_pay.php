<?php
namespace Opencart\Catalog\Model\Extension\ParnianPay\Payment;
/**
 * Parnian Pay — ParnianCoin (PARC) payments for OpenCart 4.0.2+ (storefront model).
 */
class ParnianPay extends \Opencart\System\Engine\Model {

	public function client(): \ParnianPayClient {
		require_once DIR_EXTENSION . 'parnian_pay/system/library/parnianpay_client.php';
		return new \ParnianPayClient($this->config->get('payment_parnian_pay_gateway_url') ?: 'https://pay.parniancoin.com', (string)$this->config->get('payment_parnian_pay_api_key'));
	}

	public function log(string $message, bool $error = false): void {
		if ($error || $this->config->get('payment_parnian_pay_debug')) {
			$this->log->write('Parnian Pay: ' . $message);
		}
	}

	/** Gateway status and rates, cached 10 minutes (2 minutes after a failure). Null when unreachable. */
	public function remoteStatus(bool $refresh = false): ?array {
		// Note: OpenCart 4's cache returns [] (not false) for a missing key.
		$c = $refresh ? null : $this->cache->get('parnian_pay_status');
		if (is_array($c) && !empty($c['error'])) {
			return null;
		}
		if (is_array($c) && isset($c['data']) && is_array($c['data'])) {
			return $c['data'];
		}
		$me = $this->client()->me();
		if ($me === null) {
			$this->log('status check failed', true);
			$this->cache->set('parnian_pay_status', ['error' => true], 120);
			return null;
		}
		$data = [
			'active' => !empty($me['active']),
			'rates'  => (isset($me['rates']) && is_array($me['rates'])) ? $me['rates'] : [],
		];
		$this->cache->set('parnian_pay_status', ['data' => $data], 600);
		return $data;
	}

	public function clearStatus(): void {
		$this->cache->delete('parnian_pay_status');
	}

	public function getMethods(array $address = []): array {
		$this->load->language('extension/parnian_pay/payment/parnian_pay');

		if (!$this->config->get('payment_parnian_pay_status') || !$this->config->get('payment_parnian_pay_api_key') || $this->cart->hasSubscription()) {
			return [];
		}
		if ($this->config->get('payment_parnian_pay_geo_zone_id') && $this->config->get('config_checkout_payment_address') && $address) {
			$this->load->model('localisation/geo_zone');
			if (!$this->model_localisation_geo_zone->getGeoZone((int)$this->config->get('payment_parnian_pay_geo_zone_id'), (int)$address['country_id'], (int)$address['zone_id'])) {
				return [];
			}
		}
		// Only offer it when the gateway is active and has a rate for the shopper's currency.
		// If the gateway cannot be reached right now, keep offering it rather than lose the sale.
		$status = $this->remoteStatus();
		$currency = $this->session->data['currency'] ?? $this->config->get('config_currency');
		if ($status !== null && (empty($status['active']) || empty($status['rates'][$currency]))) {
			return [];
		}

		$option_data['parnian_pay'] = [
			'code' => 'parnian_pay.parnian_pay',
			'name' => $this->language->get('text_title'),
		];
		return [
			'code'       => 'parnian_pay',
			'name'       => $this->language->get('text_title'),
			'option'     => $option_data,
			'sort_order' => $this->config->get('payment_parnian_pay_sort_order'),
		];
	}

	public function getInvoiceRow(int $order_id): ?array {
		$q = $this->db->query("SELECT * FROM `" . DB_PREFIX . "parnian_pay_invoice` WHERE `order_id` = '" . (int)$order_id . "'");
		return $q->num_rows ? $q->row : null;
	}

	public function saveInvoiceRow(int $order_id, array $inv, string $fiat_key): void {
		$this->db->query("REPLACE INTO `" . DB_PREFIX . "parnian_pay_invoice` SET `order_id` = '" . (int)$order_id . "',
			`invoice_id` = '" . $this->db->escape($inv['id']) . "', `amount` = '" . $this->db->escape($inv['amount']) . "',
			`fiat` = '" . $this->db->escape($fiat_key) . "', `rate` = '" . $this->db->escape((string)($inv['rate'] ?? '')) . "',
			`pay_url` = '" . $this->db->escape($inv['pay_url']) . "', `state` = 'pending', `date_added` = NOW(), `date_modified` = NOW()");
	}

	/** Applies the gateway's invoice state to the order (idempotent). Returns the new local state. */
	public function sync(int $order_id, array $inv): ?string {
		$row = $this->getInvoiceRow($order_id);
		if (!$row) {
			return null;
		}
		$d = \ParnianPayClient::decide($inv, $order_id, $row['invoice_id'], $row['amount'], $row['state']);
		if ($d === null) {
			$this->log('invoice ' . ($inv['id'] ?? '?') . ' does not belong to order ' . $order_id, true);
			return $row['state'];
		}
		if ($d['action'] === 'none') {
			return $d['state'];
		}

		$this->load->language('extension/parnian_pay/payment/parnian_pay');
		$this->load->model('checkout/order');
		$link = rtrim($this->config->get('payment_parnian_pay_gateway_url') ?: 'https://pay.parniancoin.com', '/') . '/pay/' . $inv['id'];
		$paid = $inv['paid'] ?? '0';
		$cfg = fn (string $k, int $def): int => (int)$this->config->get('payment_parnian_pay_' . $k . '_status_id') ?: $def;

		switch ($d['action']) {
			case 'paid':
				$this->model_checkout_order->addHistory($order_id, $cfg('paid', 2), sprintf($this->language->get('text_comment_paid'), $paid, $link), true);
				break;
			case 'confirming':
				$this->model_checkout_order->addHistory($order_id, $cfg('pending', 1), $this->language->get('text_comment_confirming'));
				break;
			case 'mismatch':
				$this->model_checkout_order->addHistory($order_id, $cfg('review', 1), sprintf($this->language->get('text_comment_mismatch'), $inv['id'], $link));
				break;
			case 'review':
				$key = $inv['status'] === 'late' ? 'text_comment_late' : 'text_comment_underpaid';
				$this->model_checkout_order->addHistory($order_id, $cfg('review', 1), sprintf($this->language->get($key), $paid, $link));
				break;
			case 'expired':
				$this->model_checkout_order->addHistory($order_id, $cfg('expired', 14), $this->language->get('text_comment_expired'));
				break;
		}
		$this->db->query("UPDATE `" . DB_PREFIX . "parnian_pay_invoice` SET `state` = '" . $this->db->escape($d['state']) . "', `date_modified` = NOW() WHERE `order_id` = '" . (int)$order_id . "'");
		$this->log('order ' . $order_id . ' -> ' . $d['state'] . ' (invoice ' . $inv['id'] . ')');
		return $d['state'];
	}
}
