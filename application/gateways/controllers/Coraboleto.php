<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Coraboleto extends App_Controller
{
    public function callback()
    {
        $invoiceId = $this->input->get('invoiceid');
        $hash = $this->input->get('hash');
        $coraId = $this->input->request_headers()['webhook-resource-id'];

        check_invoice_restrictions($invoiceId, $hash);

        $this->load->model('invoices_model');
        $invoice = $this->invoices_model->get($this->input->get('invoiceid'));

        $response = $this->coraboleto_gateway->clientPost([], 'invoices/view/' . $coraId);
        if (!$response->status) {
            return show_error('Status não encontrado.');
        }

        if ($response->status == 'PAID') {
            if ($response->total_paid <= 0)
                return show_error('Total pago não encontrado.');

            if (total_rows('invoicepaymentrecords', ['transactionid' => $coraId]) === 0) {
                $success = $this->coraboleto_gateway->addPayment(
                    [
                        'amount'        => $response->total_paid,
                        'invoiceid'     => $invoiceId,
                        'transactionid' => $response->id,
                    ]
                );
            }
        }
    }
}
