<?php
namespace Opencart\Admin\Controller\Extension\ParnianPay\Payment;
/**
 * Parnian Pay — ParnianCoin (PARC) payments for OpenCart 4.0.2+ (admin).
 */
class ParnianPay extends \Opencart\System\Engine\Controller {

	private const FIELDS = [
		'status', 'api_key', 'webhook_secret', 'gateway_url', 'expires', 'geo_zone_id', 'sort_order',
		'pending_status_id', 'paid_status_id', 'expired_status_id', 'review_status_id', 'debug',
	];

	private const DEFAULTS = [
		'gateway_url' => 'https://pay.parniancoin.com', 'expires' => 30, 'sort_order' => 1,
		'pending_status_id' => 1, 'paid_status_id' => 2, 'expired_status_id' => 14, 'review_status_id' => 1,
	];

	public function index(): void {
		$this->load->language('extension/parnian_pay/payment/parnian_pay');
		$this->document->setTitle($this->language->get('heading_title'));
		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/parnian_pay/payment/parnian_pay', $token)],
		];
		$data['save'] = $this->url->link('extension/parnian_pay/payment/parnian_pay.save', $token);
		$data['back'] = $this->url->link('marketplace/extension', $token . '&type=payment');

		foreach (self::FIELDS as $f) {
			$key = 'payment_parnian_pay_' . $f;
			$data[$key] = $this->config->has($key) ? $this->config->get($key) : (self::DEFAULTS[$f] ?? '');
		}

		$data['webhook_url'] = HTTP_CATALOG . 'index.php?route=extension/parnian_pay/payment/parnian_pay.webhook';
		$data['text_webhook_help'] = sprintf($this->language->get('text_webhook_help'), $data['webhook_url']);

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/parnian_pay/payment/parnian_pay', $data));
	}

	public function save(): void {
		$this->load->language('extension/parnian_pay/payment/parnian_pay');
		$json = [];

		if (!$this->user->hasPermission('modify', 'extension/parnian_pay/payment/parnian_pay')) {
			$json['error']['warning'] = $this->language->get('error_permission');
		}
		$post = $this->request->post;
		$post['payment_parnian_pay_api_key'] = trim((string)($post['payment_parnian_pay_api_key'] ?? ''));
		$post['payment_parnian_pay_webhook_secret'] = trim((string)($post['payment_parnian_pay_webhook_secret'] ?? ''));
		$post['payment_parnian_pay_gateway_url'] = rtrim(trim((string)($post['payment_parnian_pay_gateway_url'] ?? '')), '/') ?: 'https://pay.parniancoin.com';
		$post['payment_parnian_pay_expires'] = max(5, min(1440, (int)($post['payment_parnian_pay_expires'] ?? 30)));

		if (!empty($post['payment_parnian_pay_status']) && !preg_match('/^pk_live_[A-Za-z0-9_-]{20,}$/', $post['payment_parnian_pay_api_key'])) {
			$json['error']['api-key'] = $this->language->get('error_api_key');
		}

		if (!$json) {
			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('payment_parnian_pay', $post);
			$this->cache->delete('parnian_pay_status');

			$lines = [$this->language->get('text_success')];
			foreach ($this->connectionReport($post) as $line) {
				$lines[] = $line;
			}
			$json['success'] = implode('<br>', array_map('htmlspecialchars', $lines));
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/** Asks the gateway who we are, whether the gateway is active and whether the store currency has a rate. */
	private function connectionReport(array $post): array {
		require_once DIR_EXTENSION . 'parnian_pay/system/library/parnianpay_client.php';
		if (empty($post['payment_parnian_pay_api_key'])) {
			return [];
		}
		$client = new \ParnianPayClient($post['payment_parnian_pay_gateway_url'], $post['payment_parnian_pay_api_key']);
		$me = $client->me();
		if ($me === null) {
			return [sprintf($this->language->get('text_conn_failed'), $client->last_error)];
		}
		$out = [];
		$out[] = empty($me['active'])
			? sprintf($this->language->get('text_not_active'), $me['name'], $me['status'])
			: sprintf($this->language->get('text_connected'), $me['name']);
		$currency = $this->config->get('config_currency');
		$out[] = empty($me['rates'][$currency])
			? sprintf($this->language->get('text_no_rate'), $currency)
			: sprintf($this->language->get('text_rate'), $me['rates'][$currency], $currency);
		if (empty($post['payment_parnian_pay_webhook_secret'])) {
			$out[] = $this->language->get('text_no_secret');
		}
		return $out;
	}

	public function install(): void {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "parnian_pay_invoice` (
			`order_id` INT(11) NOT NULL,
			`invoice_id` VARCHAR(64) NOT NULL,
			`amount` VARCHAR(32) NOT NULL,
			`fiat` VARCHAR(64) NOT NULL,
			`rate` VARCHAR(40) NOT NULL DEFAULT '',
			`pay_url` VARCHAR(255) NOT NULL,
			`state` VARCHAR(16) NOT NULL DEFAULT 'pending',
			`date_added` DATETIME NOT NULL,
			`date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`order_id`),
			KEY `invoice_id` (`invoice_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	public function uninstall(): void {
		// The invoice table is kept on purpose: it links past orders to their ParnianCoin invoices.
		$this->cache->delete('parnian_pay_status');
	}
}
