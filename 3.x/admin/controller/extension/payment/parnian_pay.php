<?php
/**
 * Parnian Pay — ParnianCoin (PARC) payments for OpenCart 3.0.x (admin).
 */
class ControllerExtensionPaymentParnianPay extends Controller {
	private $error = array();

	private $fields = array(
		'status', 'api_key', 'webhook_secret', 'gateway_url', 'expires', 'geo_zone_id', 'sort_order',
		'pending_status_id', 'paid_status_id', 'expired_status_id', 'review_status_id', 'debug',
	);

	public function index() {
		$this->load->language('extension/payment/parnian_pay');
		$this->document->setTitle($this->language->get('heading_title'));
		$this->load->model('setting/setting');

		$data['success'] = '';
		$data['warnings'] = array();

		if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
			$post = $this->request->post;
			$post['payment_parnian_pay_api_key'] = trim($post['payment_parnian_pay_api_key']);
			$post['payment_parnian_pay_webhook_secret'] = trim($post['payment_parnian_pay_webhook_secret']);
			$post['payment_parnian_pay_gateway_url'] = rtrim(trim($post['payment_parnian_pay_gateway_url']), '/') ?: 'https://pay.parniancoin.com';
			$post['payment_parnian_pay_expires'] = max(5, min(1440, (int)$post['payment_parnian_pay_expires']));
			$this->model_setting_setting->editSetting('payment_parnian_pay', $post);
			$this->cache->delete('parnian_pay_status');

			$data['success'] = $this->language->get('text_success');
			$data['warnings'] = $this->connectionReport($post);
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['error_api_key'] = isset($this->error['api_key']) ? $this->error['api_key'] : '';

		$token = 'user_token=' . $this->session->data['user_token'];
		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token, true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=payment', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/payment/parnian_pay', $token, true)),
		);
		$data['action'] = $this->url->link('extension/payment/parnian_pay', $token, true);
		$data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=payment', true);

		$defaults = array(
			'gateway_url' => 'https://pay.parniancoin.com', 'expires' => 30, 'sort_order' => 1,
			'pending_status_id' => 1, 'paid_status_id' => 2, 'expired_status_id' => 14, 'review_status_id' => 1,
		);
		foreach ($this->fields as $f) {
			$key = 'payment_parnian_pay_' . $f;
			if (isset($this->request->post[$key])) {
				$data[$key] = $this->request->post[$key];
			} elseif ($this->config->has($key)) {
				$data[$key] = $this->config->get($key);
			} else {
				$data[$key] = isset($defaults[$f]) ? $defaults[$f] : '';
			}
		}

		$data['webhook_url'] = HTTPS_CATALOG . 'index.php?route=extension/payment/parnian_pay/webhook';
		$data['text_webhook_help'] = sprintf($this->language->get('text_webhook_help'), $data['webhook_url']);

		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();
		$this->load->model('localisation/geo_zone');
		$data['geo_zones'] = $this->model_localisation_geo_zone->getGeoZones();

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/payment/parnian_pay', $data));
	}

	/** Asks the gateway who we are, whether the gateway is active and whether the store currency has a rate. */
	private function connectionReport(array $post) {
		require_once DIR_SYSTEM . 'library/parnianpay/client.php';
		$out = array();
		if (empty($post['payment_parnian_pay_api_key'])) {
			return $out;
		}
		$client = new ParnianPayClient($post['payment_parnian_pay_gateway_url'], $post['payment_parnian_pay_api_key']);
		$me = $client->me();
		if ($me === null) {
			$out[] = array('type' => 'danger', 'text' => sprintf($this->language->get('text_conn_failed'), $client->last_error));
			return $out;
		}
		if (empty($me['active'])) {
			$out[] = array('type' => 'warning', 'text' => sprintf($this->language->get('text_not_active'), $me['name'], $me['status']));
		} else {
			$out[] = array('type' => 'success', 'text' => sprintf($this->language->get('text_connected'), $me['name']));
		}
		$currency = $this->config->get('config_currency');
		if (empty($me['rates'][$currency])) {
			$out[] = array('type' => 'warning', 'text' => sprintf($this->language->get('text_no_rate'), $currency));
		} else {
			$out[] = array('type' => 'info', 'text' => sprintf($this->language->get('text_rate'), $me['rates'][$currency], $currency));
		}
		if (empty($post['payment_parnian_pay_webhook_secret'])) {
			$out[] = array('type' => 'warning', 'text' => $this->language->get('text_no_secret'));
		}
		return $out;
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/payment/parnian_pay')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}
		$key = isset($this->request->post['payment_parnian_pay_api_key']) ? trim($this->request->post['payment_parnian_pay_api_key']) : '';
		if (!empty($this->request->post['payment_parnian_pay_status']) && !preg_match('/^pk_live_[A-Za-z0-9_-]{20,}$/', $key)) {
			$this->error['api_key'] = $this->language->get('error_api_key');
		}
		return !$this->error;
	}

	public function install() {
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

	public function uninstall() {
		// The invoice table is kept on purpose: it links past orders to their ParnianCoin invoices.
		$this->cache->delete('parnian_pay_status');
	}
}
