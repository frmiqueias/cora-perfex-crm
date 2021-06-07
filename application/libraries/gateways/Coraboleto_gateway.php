<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Coraboleto_gateway extends App_gateway
{
    public $urlBase = null;
    public $urlApi = null;

    public function __construct()
    {
        $this->urlBase = 'https://pay.divox.com.br/';
        $this->urlApi = $this->urlBase . 'api/cora/';

        /**
         * Call App_gateway __construct function
         */
        parent::__construct();

        /**
         * Gateway unique id - REQUIRED
         * 
         * * The ID must be alphanumeric
         * * The filename (Example_gateway.php) and the class name must contain the id as ID_gateway
         * * In this case our id is "example"
         * * Filename will be Example_gateway.php (first letter is uppercase)
         * * Class name will be Example_gateway (first letter is uppercase)
         */
        $this->setId('coraboleto');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('Cora Boleto');

        $urlReferer = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $jsScript = "javascript:alert('Antes de autorizar, informe sua chave de licença e clique em salvar.')";
        $urlButtonAuth = $this->decryptSetting('license_key') ?  $this->urlBase . 'corabrowser/auth?license_key=' . $this->decryptSetting('license_key') . '&redirect_uri=' . urlencode($urlReferer) : $jsScript;

        /**
         * Add gateway settings
         * You can add other settings here to fit for your gateway requirements
         *
         * Currently only 2 field types are accepted for gateway
         *
         * 'type'=>'yes_no'
         * 'type'=>'input'
         */
        $this->setSettings(array(
            array(
                'name' => 'license_key',
                'encrypted' => true,
                'label' => 'Chave da licença*',
                'type' => 'input',
                'after'            => '<p class="mbot15">Autorizar aplicação junto à CORA* <br><a href="' . $urlButtonAuth . '" class="btn btn-info">Autorizar agora!</a></p>',
                'field_attributes' => ['required' => true],
            ),
            array(
                'name' => 'days_to_pay',
                'label' => 'Dias para o vencimento*',
                'default_value' => '1',
                'field_attributes' => ['required' => true],
            ),
            array(
                'name' => 'currencies',
                'label' => 'settings_paymentmethod_currencies',
                'default_value' => 'USD,CAD'
            ),
        ));


        /**
         * REQUIRED
         * Hook gateway with other online payment modes
         */
        hooks()->add_filter('app_payment_gateways', [$this, 'initMode']);
    }

    /**
     * Each time a customer click PAY NOW button on the invoice HTML area, the script will process the payment via this function.
     * You can show forms here, redirect to gateway website, redirect to Codeigniter controller etc..
     * @param  array $data - Contains the total amount to pay and the invoice information
     * @return mixed
     */
    public function process_payment($data)
    {
        if (!$this->decryptSetting('license_key'))
            show_error('Erro: Informe sua chave de licença CORA.');

        $vat = $data["invoice"]->client->vat;
        $vat = preg_replace("/[^0-9]/", "", $vat);
        if (empty($vat)) {
            show_error('CPF ou CNPJ inválido.');
        }

        $dayToPay = $this->getSetting('days_to_pay') ? $this->getSetting('days_to_pay') : 1;

        $requestData = [
            'code' => $data['invoiceid'],
            'services' => [
                [
                    'name' => $data['invoice']->prefix . $data['invoiceid'],
                    'amount' => number_format($data['amount'], 2, '', ''),
                ]
            ],
            'customer' => [
                'name' => $data['invoice']->client->company,
                'email' => 'frmiqueias@gmail.com',
                'document' => [
                    'identity' => $vat,
                    'type' => strlen($vat) > 11 ? 'CNPJ' : 'CPF',
                ],
                'address' => [
                    'street' => $data['invoice']->billing_street,
                    'number' => '',
                    'complement' => '',
                    'district' => '',
                    'city' => $data['invoice']->billing_city,
                    'state' => $data['invoice']->billing_state,
                    'country' => 'BR',
                    'zip_code' => $data['invoice']->billing_zip,
                ]
            ],
            'payment_terms' => [
                'due_date' => date('Y-m-d', strtotime("+ {$dayToPay} days")),
                'finde' => [
                    'amount' => 0
                ],
                'interest' => [
                    'rate' => 0
                ],
            ],
            'webhook_url' => site_url('gateways/coraboleto/callback?invoiceid=' . $data['invoice']->id . '&hash=' . $data['hash'])
        ];

        $request = $this->clientPost($requestData, 'invoices/add-default');

        if (!$request->url)
            show_error('Erro: Falha ao obter o boleto. ' . curl_error($ch));
        redirect($request->url);
    }

    public function clientPost(array $post, string $url)
    {
        if (!function_exists('curl_init')) {
            show_error('Curl function not found');
        }

        $header = [
            'Content-Type: application/json',
            'divoxKey: ' . $this->decryptSetting('license_key'),
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->urlApi . $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (curl_errno($ch)) {
            show_error('Erro: ' . curl_error($ch));
        }
        if ($httpcode != 200) {
            show_error('Erro: Cora API ' . $httpcode);
        }
        curl_close($ch);
        return $result ? json_decode($result) : $result;
    }
}
